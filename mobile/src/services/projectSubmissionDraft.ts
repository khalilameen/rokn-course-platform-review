import AsyncStorage from '@react-native-async-storage/async-storage';

import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import type {ChatAttachmentDraft, SelectedProjectFile} from '../components/VideoPlayer/types';
import {
  cacheLearnerDraftFile,
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
} from './learnerDraftFiles';

type ProjectSubmissionDraft = {
  files?: SelectedProjectFile[];
  /** Compatibility for the older module list editor. */
  file?: SelectedProjectFile | null;
  note: string;
  updatedAt: number;
};

const KEY = '@rokn/project-editor-draft/v1';
const FEEDBACK_KEY = '@rokn/project-feedback-draft/v1';
const TTL_MS = 14 * 24 * 60 * 60 * 1000;
const MAX_BYTES = 25 * 1024 * 1024;
const draftOperations = new Map<string, Promise<unknown>>();

const withDraftLock = <T>(key: string, operation: () => Promise<T>) => {
  const previous = draftOperations.get(key) || Promise.resolve();
  const result = previous.then(operation, operation);
  const tail = result.then(
    () => undefined,
    () => undefined,
  );
  draftOperations.set(key, tail);
  void tail.finally(() => {
    if (draftOperations.get(key) === tail) draftOperations.delete(key);
  });
  return result;
};

const keyFor = async (projectId: string, boundary?: AccountSessionBoundary) =>
  `${await accountScopedStorageKey(KEY, boundary)}:${String(projectId).replace(
    /[^a-z0-9_-]/gi,
    '',
  )}`;
const submissionReferenceOwner = (projectId: string) =>
  `project-submission:${String(projectId).replace(/[^a-z0-9_-]/gi, '')}`;
const feedbackReferenceOwner = (threadId: string) =>
  `project-feedback:${String(threadId).replace(/[^a-z0-9_-]/gi, '')}`;

export const loadProjectSubmissionDraft = async (
  projectId: string,
): Promise<ProjectSubmissionDraft | null> => {
  const boundary = await captureAccountSessionBoundary();
  const key = await keyFor(projectId, boundary);
  return withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    if (!raw) {
      await retainLearnerDraftFiles(submissionReferenceOwner(projectId), [], boundary.scope);
      return null;
    }
    let parsed: Partial<ProjectSubmissionDraft> | null = null;
    try {
      const draft = JSON.parse(raw) as Partial<ProjectSubmissionDraft>;
      parsed = draft;
      if (
        typeof draft.note !== 'string' ||
        !Number.isFinite(draft.updatedAt) ||
        Date.now() - Number(draft.updatedAt) > TTL_MS
      ) {
        throw new Error('INVALID_PROJECT_DRAFT');
      }
      const files = Array.isArray(draft.files) ? draft.files : draft.file ? [draft.file] : [];
      const readable = (await Promise.all(files.map(async file =>
        (await learnerDraftFileIsReadable(file)) ? file : null
      ))).filter((file): file is SelectedProjectFile => Boolean(file));
      if (readable.length !== files.length) {
        await Promise.all(files.filter(file => !readable.includes(file)).map(removeLearnerDraftFile));
        const repaired = {files: readable, note: draft.note, updatedAt: Number(draft.updatedAt)};
        await AsyncStorage.setItem(key, JSON.stringify(repaired));
        await retainLearnerDraftFiles(submissionReferenceOwner(projectId), readable, boundary.scope);
        assertAccountSessionBoundary(boundary);
        return repaired;
      }
      return {...draft, files, file: files[0] || null} as ProjectSubmissionDraft;
    } catch {
      await retainLearnerDraftFiles(submissionReferenceOwner(projectId), [], boundary.scope);
      await Promise.all([...(parsed?.files || []), ...(parsed?.file ? [parsed.file] : [])].map(removeLearnerDraftFile));
      await AsyncStorage.removeItem(key);
      return null;
    }
  });
};

export const cacheProjectDraftFile = async (
  file: SelectedProjectFile,
): Promise<SelectedProjectFile> => {
  const cached = await cacheLearnerDraftFile(
    'project',
    {
      uri: file.uri,
      fileName: file.name,
      type: file.type,
      size: file.size,
    },
    MAX_BYTES,
  );
  return {
    uri: cached.uri,
    name: cached.fileName || file.name,
    type: cached.type || file.type,
    size: cached.size,
  };
};

export const saveProjectSubmissionDraft = async (
  projectId: string,
  draft: ProjectSubmissionDraft,
): Promise<void> => {
  const boundary = await captureAccountSessionBoundary();
  const key = await keyFor(projectId, boundary);
  await withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    if (!draft.note.trim() && !(draft.files?.length) && !draft.file) {
      await retainLearnerDraftFiles(submissionReferenceOwner(projectId), [], boundary.scope);
      await AsyncStorage.removeItem(key);
      return;
    }
    await retainLearnerDraftFiles(
      submissionReferenceOwner(projectId),
      [...(draft.files || []), ...(draft.file ? [draft.file] : [])],
      boundary.scope,
    );
    await AsyncStorage.setItem(key, JSON.stringify(draft));
    assertAccountSessionBoundary(boundary);
  });
};

export const clearProjectSubmissionDraft = async (
  projectId: string,
  input: SelectedProjectFile | SelectedProjectFile[] | null = [],
): Promise<void> => {
  const files = Array.isArray(input) ? input : input ? [input] : [];
  const boundary = await captureAccountSessionBoundary();
  const key = await keyFor(projectId, boundary);
  await withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    let storedFiles: SelectedProjectFile[] = [];
    if (raw) {
      try {
        const draft = JSON.parse(raw) as Partial<ProjectSubmissionDraft>;
        storedFiles = [...(draft.files || []), ...(draft.file ? [draft.file] : [])];
      } catch {}
    }
    // Removing the outbox record is the durable local acknowledgement. Only
    // after it succeeds may its file references be released. File deletion is
    // maintenance and remains safely retryable by the registry sweeper.
    await AsyncStorage.removeItem(key);
    await retainLearnerDraftFiles(submissionReferenceOwner(projectId), [], boundary.scope);
    await Promise.all([...storedFiles, ...files].map(file =>
      removeLearnerDraftFile(file).catch(() => undefined)));
  });
};

export type ProjectFeedbackDraft = {
  text: string;
  attachments: ChatAttachmentDraft[];
  requestId?: string;
  fingerprint?: string;
  updatedAt: number;
};

const feedbackKeyFor = async (threadId: string, boundary?: AccountSessionBoundary) =>
  `${await accountScopedStorageKey(FEEDBACK_KEY, boundary)}:${String(threadId).replace(/[^a-z0-9_-]/gi, '')}`;

export const cacheProjectFeedbackFile = async (
  file: ChatAttachmentDraft,
): Promise<ChatAttachmentDraft> => {
  if (file.serverId || !file.uri) return file;
  const cached = await cacheLearnerDraftFile('project', {
    uri: file.uri, fileName: file.name, type: file.type, size: file.size,
  }, 8 * 1024 * 1024);
  return {...file, uri: cached.uri, name: cached.fileName || file.name,
    type: cached.type || file.type, size: cached.size};
};

export const loadProjectFeedbackDraft = async (
  threadId: string,
): Promise<ProjectFeedbackDraft | null> => {
  const boundary = await captureAccountSessionBoundary();
  const key = await feedbackKeyFor(threadId, boundary);
  return withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    if (!raw) {
      await retainLearnerDraftFiles(feedbackReferenceOwner(threadId), [], boundary.scope);
      return null;
    }
    try {
      const draft = JSON.parse(raw) as ProjectFeedbackDraft;
      if (typeof draft.text !== 'string' || !Array.isArray(draft.attachments)
        || Date.now() - Number(draft.updatedAt) > TTL_MS) throw new Error('INVALID');
      const attachments: ChatAttachmentDraft[] = [];
      for (const file of draft.attachments) {
        if (file.serverId || await learnerDraftFileIsReadable(file)) attachments.push(file);
        else await removeLearnerDraftFile(file);
      }
      await retainLearnerDraftFiles(feedbackReferenceOwner(threadId), attachments, boundary.scope);
      assertAccountSessionBoundary(boundary);
      return {...draft, attachments};
    } catch {
      await retainLearnerDraftFiles(feedbackReferenceOwner(threadId), [], boundary.scope);
      await AsyncStorage.removeItem(key);
      return null;
    }
  });
};

export const saveProjectFeedbackDraft = async (
  threadId: string,
  draft: ProjectFeedbackDraft,
): Promise<void> => {
  const boundary = await captureAccountSessionBoundary();
  const key = await feedbackKeyFor(threadId, boundary);
  await withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    if (!draft.text.trim() && draft.attachments.length === 0) {
      await retainLearnerDraftFiles(feedbackReferenceOwner(threadId), [], boundary.scope);
      await AsyncStorage.removeItem(key);
      return;
    }
    await retainLearnerDraftFiles(feedbackReferenceOwner(threadId), draft.attachments, boundary.scope);
    await AsyncStorage.setItem(key, JSON.stringify(draft));
    assertAccountSessionBoundary(boundary);
  });
};

export const clearProjectFeedbackDraft = async (
  threadId: string,
  files: ChatAttachmentDraft[] = [],
): Promise<void> => {
  const boundary = await captureAccountSessionBoundary();
  const key = await feedbackKeyFor(threadId, boundary);
  await withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    let storedFiles: ChatAttachmentDraft[] = [];
    if (raw) {
      try {
        const draft = JSON.parse(raw) as ProjectFeedbackDraft;
        storedFiles = draft.attachments;
      } catch {}
    }
    // First durably acknowledge that this logical message was accepted. A
    // later registry/blob cleanup failure cannot resurrect its stale draft.
    await AsyncStorage.removeItem(key);
    await retainLearnerDraftFiles(feedbackReferenceOwner(threadId), [], boundary.scope);
    await Promise.all([...storedFiles, ...files]
      .filter(file => !file.serverId || Boolean(file.uri))
      .map(file => removeLearnerDraftFile(file).catch(() => undefined)));
  });
};
