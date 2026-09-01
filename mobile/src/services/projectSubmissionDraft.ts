import AsyncStorage from '@react-native-async-storage/async-storage';

import {accountScopedStorageKey} from '../constants/helpers';
import type {SelectedProjectFile} from '../components/VideoPlayer/types';
import {
  cacheLearnerDraftFile,
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
} from './learnerDraftFiles';

type ProjectSubmissionDraft = {
  file?: SelectedProjectFile | null;
  note: string;
  updatedAt: number;
};

const KEY = '@rokn/project-editor-draft/v1';
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

const keyFor = async (projectId: string) =>
  `${await accountScopedStorageKey(KEY)}:${String(projectId).replace(
    /[^a-z0-9_-]/gi,
    '',
  )}`;

export const loadProjectSubmissionDraft = async (
  projectId: string,
): Promise<ProjectSubmissionDraft | null> => {
  const key = await keyFor(projectId);
  return withDraftLock(key, async () => {
    const raw = await AsyncStorage.getItem(key);
    if (!raw) return null;
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
      if (draft.file && !(await learnerDraftFileIsReadable(draft.file))) {
        await removeLearnerDraftFile(draft.file);
        const repaired = {note: draft.note, updatedAt: Number(draft.updatedAt)};
        await AsyncStorage.setItem(key, JSON.stringify(repaired));
        return repaired;
      }
      return draft as ProjectSubmissionDraft;
    } catch {
      await removeLearnerDraftFile(parsed?.file);
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
  const key = await keyFor(projectId);
  await withDraftLock(key, async () => {
    if (!draft.note.trim() && !draft.file) {
      await AsyncStorage.removeItem(key);
      return;
    }
    await AsyncStorage.setItem(key, JSON.stringify(draft));
  });
};

export const clearProjectSubmissionDraft = async (
  projectId: string,
  file?: SelectedProjectFile | null,
): Promise<void> => {
  const key = await keyFor(projectId);
  await withDraftLock(key, async () => {
    const raw = await AsyncStorage.getItem(key);
    if (raw) {
      try {
        const draft = JSON.parse(raw) as Partial<ProjectSubmissionDraft>;
        await removeLearnerDraftFile(draft.file);
      } catch {}
    }
    await removeLearnerDraftFile(file);
    await AsyncStorage.removeItem(key);
  });
};
