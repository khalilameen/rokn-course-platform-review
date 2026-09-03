import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';
import {
  assertPendingProjectCacheCapacity,
  PROJECT_SUBMISSION_MAX_BYTES,
  validateProjectFile,
} from '../../../config/projects';
import {isLocalDemoId} from '../../../config/runtime';
import {publicRequest} from '../../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getCurrentAccountStorageScope,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {requireProductFeature} from '../../../services/productFeatures';
import {clearAccountLearnerDraftFiles} from '../../../services/learnerDraftFiles';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {cleanUnicodeText} from '../../../utils/unicodeText';
import {openExternalUrlOnce} from '../../../services/systemActions';
import type {
  CourseLearningData,
  ProjectFeedbackThread,
  ProjectStatus,
  SelectedProjectFile,
} from '../types';
import {
  resetPlayerStateRuntime,
  updatePlayerState,
  updatePlayerStateForScope,
} from './persistence';
import {resetPlaybackRuntimeState} from './playback';
import {
  asArray,
  asRecord,
  DataRecord,
  valueAsBoolean,
  valueAsString,
} from './shared';

const PROJECT_SUBMISSION_PREFIX = '@rokn/project-submission/v2';
const PROJECT_FILE_CACHE_DIR = `${RNFS.CachesDirectoryPath}/rokn_project_submissions`;
const PUBLIC_ID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const projectAttachmentOpenFlights = new Map<string, Promise<void>>();

const numericProjectId = (value: string) => {
  const normalized = String(value).trim();
  if (!/^\d+$/.test(normalized) || Number(normalized) <= 0) {
    throw new Error('INVALID_PROJECT_ID');
  }
  return normalized;
};

const publicRouteId = (value: string, field: string) => {
  const normalized = String(value).trim().toLowerCase();
  if (!PUBLIC_ID_PATTERN.test(normalized)) {
    throw new Error(`INVALID_${field}_ID`);
  }
  return normalized;
};

export type ProjectSubmissionOutcome = {
  passed: boolean;
  synced: boolean;
  provisional: boolean;
  canContinue: boolean;
};

const normaliseFeedbackThread = (
  value: unknown,
): ProjectFeedbackThread | null => {
  const thread = asRecord(value);
  const level = valueAsString(thread.feedback_level);
  if (!thread.id || !['report', 'enhanced'].includes(level)) return null;
  return {
    id: valueAsString(thread.id),
    feedbackLevel: level as 'report' | 'enhanced',
    canReply: valueAsBoolean(thread.can_reply),
    status: valueAsString(thread.status, 'ready'),
    remainingMessages: Math.max(0, Number(thread.remaining_messages) || 0),
    attachmentsEnabled: valueAsBoolean(thread.attachments_enabled),
    attachmentMaxFiles: Math.min(5, Math.max(0, Number(thread.attachment_max_files) || 0)),
    messages: asArray<DataRecord>(thread.messages).flatMap(message => {
      const role = valueAsString(message.role);
      const status = valueAsString(message.status);
      if (
        !['assistant', 'user'].includes(role) ||
        ![
          'queued',
          'sent',
          'streaming',
          'completed',
          'failed',
          'cancelled',
        ].includes(status)
      )
        return [];
      return [
        {
          id: valueAsString(message.id),
          clientRequestId:
            valueAsString(message.client_request_id) || undefined,
          role: role as 'assistant' | 'user',
          status: status as
            | 'queued'
            | 'sent'
            | 'streaming'
            | 'completed'
            | 'failed'
            | 'cancelled',
          errorCode: valueAsString(message.error_code) || undefined,
          text: valueAsString(message.text) || undefined,
          createdAt: valueAsString(message.created_at) || undefined,
          attachments: asArray<DataRecord>(message.attachments).map(file => ({
            uri: '',
            name: valueAsString(file.name, 'مرفق'),
            type: valueAsString(file.mime_type, 'application/octet-stream'),
            size: Number(file.size_bytes) || undefined,
            uploadId: valueAsString(file.id),
            serverId: valueAsString(file.id),
            downloadUrl: valueAsString(file.download_url) || undefined,
            downloadExpiresAt: valueAsString(file.download_url_expires_at) || undefined,
          })),
        },
      ];
    }),
  };
};

export const loadProjectFeedbackThread = async (
  projectId: string,
  threadId?: string,
): Promise<ProjectFeedbackThread | null> => {
  const boundary = await captureAccountSessionBoundary();
  const response = threadId
    ? await publicRequest.get(
        `project-feedback-threads/${publicRouteId(threadId, 'PROJECT_THREAD')}`,
      )
    : await publicRequest.get(`projects/${numericProjectId(projectId)}`);
  assertAccountSessionBoundary(boundary);
  const payload = unwrapResponseData(response);
  return normaliseFeedbackThread(
    threadId ? payload : asRecord(payload.latest_submission).feedback_thread,
  );
};

export const loadProjectResolution = async (projectId: string): Promise<{
  status: ProjectStatus;
  feedbackThread: ProjectFeedbackThread | null;
  attachments: import('../types').ChatAttachmentDraft[];
}> => {
  const boundary = await captureAccountSessionBoundary();
  const response = await publicRequest.get(`projects/${numericProjectId(projectId)}`);
  assertAccountSessionBoundary(boundary);
  const payload = unwrapResponseData(response);
  const submission = asRecord(payload.latest_submission);
  const rawStatus = valueAsString(submission.status).toLowerCase();
  const status: ProjectStatus = rawStatus === 'passed'
    ? 'passed'
    : rawStatus === 'needs_resubmission'
    ? 'needs_retry'
    : rawStatus === 'pending'
    ? 'reviewing'
    : 'not_submitted';
  return {
    status,
    feedbackThread: normaliseFeedbackThread(submission.feedback_thread),
    attachments: asArray<DataRecord>(submission.attachments).map(file => ({
      uri: '',
      name: valueAsString(file.name, 'مرفق'),
      type: valueAsString(file.mime_type, 'application/octet-stream'),
      size: Number(file.size_bytes) || undefined,
      uploadId: valueAsString(file.id),
      serverId: valueAsString(file.id),
      downloadUrl: valueAsString(file.download_url) || undefined,
      downloadExpiresAt: valueAsString(file.download_url_expires_at) || undefined,
    })),
  };
};

export const watchProjectResolution = <T extends {status: ProjectStatus}>({
  projectId,
  resolve,
  onResolution,
  beforeResolve,
  onExhausted,
  isActive = () => true,
  maxAttempts = 30,
  initialDelayMs = 0,
}: {
  projectId: string;
  resolve: (projectId: string) => Promise<T | null>;
  onResolution: (resolution: T) => void;
  beforeResolve?: () => Promise<unknown>;
  onExhausted?: () => void;
  isActive?: () => boolean;
  maxAttempts?: number;
  initialDelayMs?: number;
}): (() => void) => {
  let cancelled = false;
  let timer: ReturnType<typeof setTimeout> | undefined;
  let attempt = 0;
  const jitter = Array.from(projectId).reduce(
    (sum, character) => sum + character.charCodeAt(0),
    0,
  ) % 31;
  const schedule = (delay: number) => {
    timer = setTimeout(() => void poll(), Math.round(delay * (0.85 + jitter / 100)));
  };
  const poll = async () => {
    if (cancelled || !isActive()) return;
    attempt += 1;
    try {
      await beforeResolve?.();
      const resolution = await resolve(projectId);
      if (cancelled || !isActive()) return;
      if (resolution) {
        onResolution(resolution);
        if (resolution.status === 'passed' || resolution.status === 'needs_retry') return;
      }
    } catch {}
    if (attempt >= maxAttempts) {
      onExhausted?.();
      return;
    }
    if (cancelled || !isActive()) return;
    schedule(Math.min(12000, 2200 * Math.pow(1.4, Math.min(7, attempt - 1))));
  };
  if (initialDelayMs > 0) schedule(initialDelayMs);
  else void poll();
  return () => {
    cancelled = true;
    if (timer) clearTimeout(timer);
  };
};

const openProjectInputAttachmentInternal = async ({
  projectId: _projectId,
  threadId: _threadId,
  file,
}: {
  projectId: string;
  threadId?: string;
  file: import('../types').ChatAttachmentDraft;
}, boundary: AccountSessionBoundary) => {
  let candidate = file;
  const expiresAt = Date.parse(String(candidate.downloadExpiresAt || ''));
  if (!candidate.downloadUrl || !Number.isFinite(expiresAt) || expiresAt <= Date.now() + 15000) {
    if (!candidate.serverId) throw new Error('PROJECT_ATTACHMENT_UNAVAILABLE');
    const response = await publicRequest.get(
      `ai-input-attachments/${publicRouteId(candidate.serverId, 'ATTACHMENT')}`,
    );
    assertAccountSessionBoundary(boundary);
    const payload = unwrapResponseData(response);
    candidate = {
      ...file,
      downloadUrl: valueAsString(payload.download_url) || undefined,
      downloadExpiresAt: valueAsString(payload.download_url_expires_at) || undefined,
    };
  }
  if (!candidate.downloadUrl) throw new Error('PROJECT_ATTACHMENT_UNAVAILABLE');
  assertAccountSessionBoundary(boundary);
  await openExternalUrlOnce(
    candidate.downloadUrl,
    undefined,
    `project-input-attachment:${
      file.serverId || file.uploadId || file.downloadUrl || ''
    }`,
  );
};

export const openProjectInputAttachment = (input: {
  projectId: string;
  threadId?: string;
  file: import('../types').ChatAttachmentDraft;
}) => (async () => {
  const boundary = await captureAccountSessionBoundary();
  const attachmentIdentity = String(
    input.file.serverId ||
      input.file.uploadId ||
      input.file.downloadUrl ||
      '',
  ).trim();
  if (!attachmentIdentity) {
    throw new Error('PROJECT_ATTACHMENT_UNAVAILABLE');
  }
  const key = [
    boundary.scope,
    input.projectId,
    input.threadId || 'submission',
    attachmentIdentity,
  ].join(':');
  const existing = projectAttachmentOpenFlights.get(key);
  if (existing) return existing;
  const flight = openProjectInputAttachmentInternal(input, boundary).finally(() => {
    if (projectAttachmentOpenFlights.get(key) === flight) {
      projectAttachmentOpenFlights.delete(key);
    }
  });
  projectAttachmentOpenFlights.set(key, flight);
  return flight;
})();

export const sendProjectFeedbackMessage = async (
  threadId: string,
  message: string,
  clientRequestId = secureRandomUuid(),
  attachmentIds: string[] = [],
): Promise<ProjectFeedbackThread> => {
  const boundary = await captureAccountSessionBoundary();
  const normalizedThreadId = publicRouteId(threadId, 'PROJECT_THREAD');
  const response = await publicRequest.post(
    `project-feedback-threads/${normalizedThreadId}/messages`,
    {
      message: cleanUnicodeText(message),
      client_request_id: clientRequestId,
      attachment_ids: attachmentIds,
    },
    {headers: {'Idempotency-Key': clientRequestId}, timeout: 30000},
  );
  assertAccountSessionBoundary(boundary);
  const thread = normaliseFeedbackThread(unwrapResponseData(response));
  if (!thread) throw new Error('PROJECT_FEEDBACK_THREAD_UNAVAILABLE');
  return thread;
};

export const uploadProjectFeedbackAttachment = async (
  threadId: string,
  file: import('../types').ChatAttachmentDraft,
): Promise<string> => {
  const body = new FormData();
  body.append('client_upload_id', file.uploadId);
  body.append('attachment', {
    uri: file.uri,
    name: file.name,
    type: file.type,
  } as unknown as Blob);
  const response = await publicRequest.post(
    `project-feedback-threads/${publicRouteId(threadId, 'PROJECT_THREAD')}/attachments`,
    body,
    {headers: {'Content-Type': 'multipart/form-data'}, timeout: 45000},
  );
  const id = valueAsString(asRecord(unwrapResponseData(response)).id);
  if (!id) throw new Error('PROJECT_FEEDBACK_ATTACHMENT_UPLOAD_FAILED');
  return id;
};

export const submitProjectAttempt = async (
  projectId: string,
  selectedInput?: SelectedProjectFile | SelectedProjectFile[] | null,
  submissionText?: string,
): Promise<ProjectSubmissionOutcome> => {
  const selectedFiles = Array.isArray(selectedInput)
    ? selectedInput
    : selectedInput ? [selectedInput] : [];
  const generation = projectRuntimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  const accountScope = boundary.scope;
  assertProjectOwner(generation, boundary);
  const storageKey = await projectSubmissionKey(projectId, accountScope);
  if (isLocalDemoId(projectId)) {
    assertProjectOwner(generation, boundary);
    await passProjectLocally(projectId, accountScope, boundary);
    assertProjectOwner(generation, boundary);
    return {
      passed: true,
      synced: false,
      provisional: false,
      canContinue: true,
    };
  }

  await requireProductFeature('project_uploads');
  assertProjectOwner(generation, boundary);

  const pending = await getOrCreatePendingSubmission(
    projectId,
    boundary,
    generation,
    selectedFiles,
    submissionText,
  );
  let foregroundBudgetTimer: ReturnType<typeof setTimeout> | undefined;
  let result: SubmissionSyncResult;
  try {
    result = await Promise.race([
      syncProjectSubmission(pending, generation, boundary),
      new Promise<SubmissionSyncResult>(resolve => {
        foregroundBudgetTimer = setTimeout(
          () =>
            resolve({
              passed: false,
              terminal: false,
              accepted: Boolean(pending.publicId),
            }),
          // The server's normal fallback review is eight seconds. Keep this
          // bounded, but long enough to receive the authoritative unlock in the
          // common case instead of presenting an empty next module.
          15000,
        );
      }),
    ]);
  } catch (error) {
    assertProjectOwner(generation, boundary);
    if (!retryableRequestFailure(error)) {
      await removeCachedProjectFile(pending);
      await AsyncStorage.removeItem(storageKey);
    }
    assertProjectOwner(generation, boundary);
    throw error;
  } finally {
    if (foregroundBudgetTimer) {
      clearTimeout(foregroundBudgetTimer);
    }
  }

  assertProjectOwner(generation, boundary);
  if (result.passed) {
    await passProjectLocally(projectId, accountScope, boundary);
    await removeCachedProjectFile(pending);
    await AsyncStorage.removeItem(storageKey);
    assertProjectOwner(generation, boundary);
    return {
      passed: true,
      synced: true,
      provisional: false,
      canContinue: true,
    };
  }

  // Stay on review until the API unlocks the next module.
  if (!result.terminal) {
    await markProjectProvisional(projectId, accountScope, boundary);
    assertProjectOwner(generation, boundary);
    return {
      passed: false,
      synced: result.accepted,
      provisional: true,
      canContinue: false,
    };
  }

  await clearProjectLocalReviewState(projectId, accountScope, boundary);
  await removeCachedProjectFile(pending);
  await AsyncStorage.removeItem(storageKey);
  assertProjectOwner(generation, boundary);
  return {
    passed: false,
    synced: true,
    provisional: false,
    canContinue: false,
  };
};

type PendingProjectSubmission = {
  projectId: string;
  accountScope?: string;
  clientSubmissionId: string;
  fingerprint: string;
  selectedFiles?: SelectedProjectFile[];
  /** Durable compatibility for submissions queued by mobile v1. */
  selectedFile?: SelectedProjectFile | null;
  submissionText?: string;
  publicId?: string;
  pollAfterSeconds?: number;
};

type SubmissionSyncResult = {
  passed: boolean;
  terminal: boolean;
  accepted: boolean;
};

const projectSubmissionFlights = new Map<
  string,
  Promise<SubmissionSyncResult>
>();
const pendingProjectRetryFlights = new Map<
  string,
  Promise<ProjectSubmissionRetryOutcome[]>
>();
let projectRuntimeGeneration = 0;

const assertProjectRuntime = (generation: number) => {
  if (generation !== projectRuntimeGeneration) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
};

const assertProjectOwner = (
  generation: number,
  boundary: AccountSessionBoundary,
) => {
  assertProjectRuntime(generation);
  assertAccountSessionBoundary(boundary);
};

const projectSubmissionKey = async (
  projectId: string,
  accountScope?: string,
) => {
  const scope = accountScope || (await getCurrentAccountStorageScope());
  return `${PROJECT_SUBMISSION_PREFIX}:${scope}:${projectId}`;
};

const hashText = (value: string) => {
  let hash = 5381;
  for (let index = 0; index < value.length; index += 1) {
    hash = (hash * 33 + value.charCodeAt(index)) % 2147483647;
  }
  return hash.toString(36);
};

const submissionFingerprint = (
  projectId: string,
  selectedFiles: SelectedProjectFile[] = [],
  submissionText?: string,
) =>
  hashText(
    [
      projectId,
      ...selectedFiles.flatMap(file => [file.uri, file.name, file.type, file.size || 0]),
      cleanUnicodeText(submissionText || ''),
    ].join('|'),
  );

const createClientSubmissionId = (projectId: string, fingerprint: string) =>
  `rokn-${projectId
    .replace(/[^a-zA-Z0-9_-]/g, '')
    .slice(-18)}-${Date.now().toString(36)}-${fingerprint}`;

const readPendingSubmission = async (
  projectId: string,
  accountScope?: string,
) => {
  try {
    const value = await AsyncStorage.getItem(
      await projectSubmissionKey(projectId, accountScope),
    );
    if (!value) return null;
    const pending = JSON.parse(value) as PendingProjectSubmission;
    if (!pending.selectedFiles?.length && pending.selectedFile) {
      pending.selectedFiles = [pending.selectedFile];
    }
    return pending;
  } catch {
    return null;
  }
};

const savePendingSubmission = async (
  pending: PendingProjectSubmission,
  generation = projectRuntimeGeneration,
  boundary?: AccountSessionBoundary,
) => {
  if (boundary) assertProjectOwner(generation, boundary);
  else assertProjectRuntime(generation);
  const accountScope =
    pending.accountScope || (await getCurrentAccountStorageScope());
  const storageKey = await projectSubmissionKey(
    pending.projectId,
    accountScope,
  );
  await AsyncStorage.setItem(
    storageKey,
    JSON.stringify({...pending, accountScope}),
  );
  try {
    if (boundary) assertProjectOwner(generation, boundary);
    else assertProjectRuntime(generation);
  } catch (error) {
    await AsyncStorage.removeItem(storageKey);
    throw error;
  }
};

const cachedProjectFilePath = (uri?: string | null) =>
  uri?.replace(/^file:\/\//, '') || '';

const removeCachedProjectFile = async (
  pending?: PendingProjectSubmission | null,
) => {
  const files = pending?.selectedFiles?.length
    ? pending.selectedFiles : pending?.selectedFile ? [pending.selectedFile] : [];
  for (const file of files) {
    const path = cachedProjectFilePath(file.uri);
    if (path && path.startsWith(PROJECT_FILE_CACHE_DIR)) {
      await RNFS.unlink(path).catch(() => undefined);
    }
  }
};

/**
 * Remove durable file copies before account-scoped AsyncStorage keys disappear
 * during logout. In-memory timers and flights are also dropped so an old
 * account cannot resume a write after a new account signs in.
 */
export const clearCurrentAccountLearningFiles = async (
  accountScope?: string,
) => {
  quiesceLearningRuntime();
  const resolvedScope = accountScope || (await getCurrentAccountStorageScope());
  if (!/^[a-z0-9_-]+$/i.test(resolvedScope)) {
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  }
  const prefix = `${PROJECT_SUBMISSION_PREFIX}:${resolvedScope}:`;
  const keys = (await AsyncStorage.getAllKeys()).filter(key =>
    key.startsWith(prefix),
  );
  const entries = keys.length ? await AsyncStorage.multiGet(keys) : [];
  for (const [, value] of entries) {
    try {
      await removeCachedProjectFile(
        value ? (JSON.parse(value) as PendingProjectSubmission) : null,
      );
    } catch {
      // Ignore damaged metadata while clearing the account cache.
    }
  }

  await Promise.all([
    cleanupProjectFileCache(),
    clearAccountLearnerDraftFiles(resolvedScope),
  ]);
};

/** Stop old-session work without deleting retryable progress or draft files. */
export const quiesceLearningRuntime = () => {
  projectRuntimeGeneration += 1;
  resetPlaybackRuntimeState();
  resetPlayerStateRuntime();
  projectSubmissionFlights.clear();
  pendingProjectRetryFlights.clear();
};

const activePendingProjectFilePaths = async () => {
  const pendingKeys = (await AsyncStorage.getAllKeys()).filter(key =>
    key.startsWith(`${PROJECT_SUBMISSION_PREFIX}:`),
  );
  const pendingEntries = pendingKeys.length
    ? await AsyncStorage.multiGet(pendingKeys)
    : [];
  return new Set(
    pendingEntries.flatMap(([, value]) => {
      try {
        const pending = value
          ? (JSON.parse(value) as PendingProjectSubmission)
          : null;
        // Once the server has returned a public id the upload is complete. The
        // queue only polls from then on, so its local file is safe to reclaim.
        const activePath = pending?.publicId
          ? ''
          : (pending?.selectedFiles?.length
              ? pending.selectedFiles : pending?.selectedFile ? [pending.selectedFile] : [])
              .map(file => cachedProjectFilePath(file.uri));
        return Array.isArray(activePath) ? activePath.filter(Boolean) : [];
      } catch {
        return [];
      }
    }),
  );
};

const activePendingProjectFileBytes = async (excludingPath = '') => {
  const activePaths = await activePendingProjectFilePaths();
  let total = 0;
  for (const path of activePaths) {
    if (path === excludingPath) continue;
    const stat = await RNFS.stat(path).catch(() => null);
    total += Math.max(0, Number(stat?.size) || 0);
  }
  return total;
};

const cleanupProjectFileCache = async () => {
  if (!(await RNFS.exists(PROJECT_FILE_CACHE_DIR).catch(() => false))) return;
  try {
    const activePaths = await activePendingProjectFilePaths();
    const files = await RNFS.readDir(PROJECT_FILE_CACHE_DIR);
    for (const file of files) {
      if (activePaths.has(file.path)) {
        continue;
      }
      // This directory contains only durable queue copies. A file not owned by
      // an unsent submission is either uploaded/completed or orphaned by an
      // interrupted write and can be reclaimed immediately.
      await RNFS.unlink(file.path).catch(() => undefined);
    }
  } catch {
    // Cache cleanup runs outside the upload result.
  }
};

const cachePendingProjectFile = async (
  projectId: string,
  fingerprint: string,
  selectedFile?: SelectedProjectFile | null,
  replacingFile?: SelectedProjectFile | null,
): Promise<SelectedProjectFile | null | undefined> => {
  if (!selectedFile) {
    return selectedFile;
  }
  const validatedSize = await validateProjectFile(selectedFile);
  await cleanupProjectFileCache();
  const sourcePath = cachedProjectFilePath(selectedFile.uri);
  if (sourcePath.startsWith(PROJECT_FILE_CACHE_DIR)) {
    return {...selectedFile, size: validatedSize};
  }
  const replacingPath = cachedProjectFilePath(replacingFile?.uri);
  const retainedBytes = await activePendingProjectFileBytes(replacingPath);
  assertPendingProjectCacheCapacity(retainedBytes, validatedSize);
  try {
    await RNFS.mkdir(PROJECT_FILE_CACHE_DIR);
    const safeProject = projectId.replace(/[^a-zA-Z0-9_-]/g, '').slice(-24);
    const extension = selectedFile.name.match(/\.[a-zA-Z0-9]{1,8}$/)?.[0] || '';
    const destination = `${PROJECT_FILE_CACHE_DIR}/${safeProject}-${fingerprint}${extension}`;
    await RNFS.copyFile(selectedFile.uri, destination);
    const copiedSize = Number((await RNFS.stat(destination)).size);
    if (
      !Number.isFinite(copiedSize) ||
      copiedSize <= 0 ||
      copiedSize !== validatedSize ||
      copiedSize > PROJECT_SUBMISSION_MAX_BYTES
    ) {
      await RNFS.unlink(destination).catch(() => undefined);
      throw new Error(
        copiedSize > PROJECT_SUBMISSION_MAX_BYTES
          ? 'PROJECT_FILE_TOO_LARGE'
          : 'PROJECT_FILE_COPY_FAILED',
      );
    }
    return {
      ...selectedFile,
      size: copiedSize,
      uri: `file://${destination}`,
    };
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : '';
    if (
      message === 'PROJECT_FILE_TOO_LARGE' ||
      message === 'PROJECT_PENDING_CACHE_FULL'
    ) {
      throw error;
    }
    // Provider URIs can be revoked as soon as the picker or app process ends.
    // A submission is either durably copied before it enters the outbox or it
    // is not queued at all; retaining an unreadable pending item is worse than
    // asking the learner to choose the file again now.
    throw new Error('PROJECT_FILE_COPY_FAILED');
  }
};

const getOrCreatePendingSubmission = async (
  projectId: string,
  boundary: AccountSessionBoundary,
  generation: number,
  selectedFiles: SelectedProjectFile[] = [],
  submissionText?: string,
) => {
  const accountScope = boundary.scope;
  assertProjectOwner(generation, boundary);
  const normalizedSubmissionText = cleanUnicodeText(submissionText || '');
  const fingerprint = submissionFingerprint(
    projectId,
    selectedFiles,
    normalizedSubmissionText,
  );
  const existing = await readPendingSubmission(projectId, accountScope);
  assertProjectOwner(generation, boundary);
  if (existing?.accountScope && existing.accountScope !== accountScope) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
  if (existing?.fingerprint === fingerprint) {
    return {...existing, accountScope};
  }
  if (existing?.publicId) {
    return {...existing, accountScope};
  }

  const cachedSelectedFiles: SelectedProjectFile[] = [];
  for (const [index, file] of selectedFiles.entries()) {
    const cached = await cachePendingProjectFile(
      projectId, `${fingerprint}-${index}`, file, existing?.selectedFiles?.[index],
    );
    if (cached) cachedSelectedFiles.push(cached);
  }
  assertProjectOwner(generation, boundary);
  const pending: PendingProjectSubmission = {
    projectId,
    accountScope,
    selectedFiles: cachedSelectedFiles,
    selectedFile: null,
    submissionText: normalizedSubmissionText || undefined,
    fingerprint,
    clientSubmissionId: createClientSubmissionId(projectId, fingerprint),
  };
  try {
    await savePendingSubmission(pending, generation, boundary);
    assertProjectOwner(generation, boundary);
  } catch (error) {
    if (
      cachedSelectedFiles.some((file, index) =>
        cachedProjectFilePath(file.uri) !== cachedProjectFilePath(existing?.selectedFiles?.[index]?.uri))
    ) {
      await removeCachedProjectFile({
        ...pending,
        selectedFiles: cachedSelectedFiles,
      });
    }
    throw error;
  }
  if (
    cachedSelectedFiles.some((file, index) =>
      cachedProjectFilePath(file.uri) !== cachedProjectFilePath(existing?.selectedFiles?.[index]?.uri))
  ) {
    await removeCachedProjectFile(existing);
  }
  return pending;
};

const makeSubmissionForm = (pending: PendingProjectSubmission) => {
  const form = new FormData();
  if (pending.submissionText) {
    form.append('submission_text', pending.submissionText);
  }
  pending.selectedFiles?.forEach(file => form.append('submission_files[]', {
    uri: file.uri, name: file.name, type: file.type,
  } as unknown as Blob));
  form.append('client_submission_id', pending.clientSubmissionId);
  return form;
};

const unwrapResponseData = (response: unknown): DataRecord => {
  const root = asRecord(response);
  const data = asRecord(root.data);
  return asRecord(data.data || root.data || response);
};

const normaliseSubmissionResult = (
  payload: DataRecord,
): SubmissionSyncResult => {
  const status = valueAsString(payload?.status).toLowerCase();
  if (
    valueAsBoolean(payload?.can_continue) ||
    valueAsBoolean(payload?.passed) ||
    ['passed', 'approved', 'completed'].includes(status)
  ) {
    return {passed: true, terminal: true, accepted: true};
  }
  if (
    valueAsBoolean(payload?.needs_resubmission) ||
    [
      'needs_resubmission',
      'rejected',
      'failed',
      'needs_retry',
      'needs_revision',
      'invalid',
    ].includes(status)
  ) {
    return {passed: false, terminal: true, accepted: true};
  }
  return {passed: false, terminal: false, accepted: true};
};

const waitFor = (milliseconds: number) =>
  new Promise<void>(resolve => setTimeout(resolve, milliseconds));

const requestStatus = (error: unknown): number | null => {
  if (!error || typeof error !== 'object') return null;
  const candidate = error as {
    status?: unknown;
    response?: {status?: unknown};
  };
  const status = Number(candidate.status ?? candidate.response?.status);
  return Number.isFinite(status) && status > 0 ? status : null;
};

const retryableRequestFailure = (error: unknown) => {
  const status = requestStatus(error);
  return status === null || status === 408 || status === 429 || status >= 500;
};

const pollProjectSubmission = async (
  pending: PendingProjectSubmission,
  attempts = 5,
  generation = projectRuntimeGeneration,
  boundary?: AccountSessionBoundary,
): Promise<SubmissionSyncResult> => {
  if (!pending.publicId) {
    return {passed: false, terminal: false, accepted: false};
  }

  for (let attempt = 0; attempt < attempts; attempt += 1) {
    if (attempt > 0) {
      const delay = Math.min(
        3500,
        Math.max(700, Number(pending.pollAfterSeconds || 1) * 1000),
      );
      await waitFor(delay);
    }
    try {
      if (boundary) assertProjectOwner(generation, boundary);
      else assertProjectRuntime(generation);
      const response = await publicRequest.get(
        `project-submissions/${publicRouteId(
          pending.publicId,
          'PROJECT_SUBMISSION',
        )}`,
        {timeout: 12000},
      );
      if (boundary) assertProjectOwner(generation, boundary);
      else assertProjectRuntime(generation);
      const result = normaliseSubmissionResult(unwrapResponseData(response));
      if (result.terminal) {
        return result;
      }
    } catch {
      if (boundary) assertProjectOwner(generation, boundary);
      else assertProjectRuntime(generation);
      return {passed: false, terminal: false, accepted: true};
    }
  }
  return {passed: false, terminal: false, accepted: true};
};

const performProjectSubmissionSync = async (
  pending: PendingProjectSubmission,
  generation: number,
  boundary: AccountSessionBoundary,
): Promise<SubmissionSyncResult> => {
  assertProjectOwner(generation, boundary);
  if (!pending.accountScope) {
    throw new Error('PROJECT_SUBMISSION_SCOPE_MISSING');
  }
  if (pending.publicId) {
    if (pending.selectedFiles?.length) {
      const cachedFiles = pending.selectedFiles;
      pending.selectedFiles = [];
      await savePendingSubmission(pending, generation, boundary);
      await removeCachedProjectFile({...pending, selectedFiles: cachedFiles});
    }
    const existingResult = await pollProjectSubmission(
      pending,
      5,
      generation,
      boundary,
    );
    // Once the server has accepted a submission, only poll that immutable
    // attempt. Reposting here can duplicate text submissions and cannot
    // recreate a file that was already released from the local cache.
    return existingResult;
  }

  const requestConfig = {
    headers: {
      'Content-Type': 'multipart/form-data',
      'Idempotency-Key': pending.clientSubmissionId,
    },
    timeout: 30000,
  };

  let response: unknown;
  try {
    assertProjectOwner(generation, boundary);
    response = await publicRequest.post(
      `projects/${pending.projectId}/submissions`,
      makeSubmissionForm(pending),
      requestConfig,
    );
    assertProjectOwner(generation, boundary);
  } catch (error) {
    assertProjectOwner(generation, boundary);
    if (retryableRequestFailure(error)) {
      return {passed: false, terminal: false, accepted: false};
    }
    throw error;
  }

  const payload = unwrapResponseData(response);
  const immediateResult = normaliseSubmissionResult(payload);
  if (immediateResult.terminal) {
    return immediateResult;
  }

  const publicId = valueAsString(
    payload?.id || payload?.public_id || payload?.submission_id,
  );
  if (!PUBLIC_ID_PATTERN.test(publicId)) {
    return {passed: false, terminal: false, accepted: false};
  }
  pending.publicId = publicId;
  pending.pollAfterSeconds = Number(payload?.poll_after_seconds) || 1;
  const uploadedFiles = pending.selectedFiles || [];
  pending.selectedFiles = [];
  await savePendingSubmission(pending, generation, boundary);
  await removeCachedProjectFile({...pending, selectedFiles: uploadedFiles});
  assertProjectOwner(generation, boundary);
  return pollProjectSubmission(pending, 5, generation, boundary);
};

const syncProjectSubmission = (
  pending: PendingProjectSubmission,
  generation: number,
  boundary: AccountSessionBoundary,
): Promise<SubmissionSyncResult> => {
  const accountScope = pending.accountScope || 'missing-scope';
  const flightKey = `${accountScope}:${boundary.epoch}:${pending.clientSubmissionId}`;
  const existing = projectSubmissionFlights.get(flightKey);
  if (existing) return existing;
  const flight = performProjectSubmissionSync(
    pending,
    generation,
    boundary,
  ).finally(
    () => {
      if (projectSubmissionFlights.get(flightKey) === flight) {
        projectSubmissionFlights.delete(flightKey);
      }
    },
  );
  projectSubmissionFlights.set(flightKey, flight);
  return flight;
};

const updateProjectState = (
  accountScope: string | undefined,
  update: Parameters<typeof updatePlayerState>[0],
  boundary?: AccountSessionBoundary,
) =>
  accountScope
    ? updatePlayerStateForScope(accountScope, update, boundary)
    : updatePlayerState(update);

const passProjectLocally = (
  projectId: string,
  accountScope?: string,
  boundary?: AccountSessionBoundary,
) =>
  updateProjectState(
    accountScope,
    state => ({
      ...state,
      passedProjects: Array.from(
        new Set([...state.passedProjects, projectId]),
      ),
      provisionalProjects: state.provisionalProjects.filter(
        id => id !== projectId,
      ),
    }),
    boundary,
  );

const markProjectProvisional = (
  projectId: string,
  accountScope?: string,
  boundary?: AccountSessionBoundary,
) =>
  updateProjectState(
    accountScope,
    state => ({
      ...state,
      provisionalProjects: Array.from(
        new Set([...state.provisionalProjects, projectId]),
      ),
    }),
    boundary,
  );

const clearProjectLocalReviewState = (
  projectId: string,
  accountScope?: string,
  boundary?: AccountSessionBoundary,
) =>
  updateProjectState(
    accountScope,
    state => ({
      ...state,
      passedProjects: state.passedProjects.filter(id => id !== projectId),
      provisionalProjects: state.provisionalProjects.filter(
        id => id !== projectId,
      ),
    }),
    boundary,
  );

export type ProjectSubmissionRetryOutcome = {
  projectId: string;
  passed: boolean;
  terminal: boolean;
  accepted: boolean;
};

const performPendingProjectSubmissionRetry = async (
  generation: number,
  boundary: AccountSessionBoundary,
): Promise<ProjectSubmissionRetryOutcome[]> => {
  const accountScope = boundary.scope;
  assertProjectOwner(generation, boundary);
  await cleanupProjectFileCache();
  assertProjectOwner(generation, boundary);
  const outcomes: ProjectSubmissionRetryOutcome[] = [];
  const currentPrefix = `${PROJECT_SUBMISSION_PREFIX}:${accountScope}:`;
  const keys = (await AsyncStorage.getAllKeys()).filter(key =>
    key.startsWith(currentPrefix),
  );
  if (!keys.length) {
    return outcomes;
  }
  const entries = await AsyncStorage.multiGet(keys);
  for (const [key, value] of entries) {
    assertProjectOwner(generation, boundary);
    if (!value) {
      continue;
    }
    let pending: PendingProjectSubmission;
    try {
      pending = {
        ...(JSON.parse(value) as PendingProjectSubmission),
        accountScope,
      };
      if (
        !/^\d+$/.test(String(pending.projectId || '')) ||
        !pending.clientSubmissionId ||
        !pending.fingerprint ||
        (pending.publicId && !PUBLIC_ID_PATTERN.test(pending.publicId))
      ) {
        throw new Error('INVALID_PENDING_PROJECT_SUBMISSION');
      }
    } catch {
      await AsyncStorage.removeItem(key);
      continue;
    }
    try {
      const result = await syncProjectSubmission(
        pending,
        generation,
        boundary,
      );
      assertProjectOwner(generation, boundary);
      outcomes.push({projectId: pending.projectId, ...result});
      if (result.terminal) {
        if (result.passed) {
          await passProjectLocally(
            pending.projectId,
            accountScope,
            boundary,
          );
        } else {
          await clearProjectLocalReviewState(
            pending.projectId,
            accountScope,
            boundary,
          );
        }
        await removeCachedProjectFile(pending);
        await AsyncStorage.removeItem(key);
      }
    } catch {
      try {
        assertProjectOwner(generation, boundary);
      } catch {
        return outcomes;
      }
      // The next course opening will retry without interrupting the learner.
    }
  }
  return outcomes;
};

export const retryPendingProjectSubmissions = async (): Promise<
  ProjectSubmissionRetryOutcome[]
> => {
  const generation = projectRuntimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  assertProjectOwner(generation, boundary);
  const flightKey = `${boundary.scope}:${boundary.epoch}`;
  const existing = pendingProjectRetryFlights.get(flightKey);
  if (existing) return existing;
  const flight = performPendingProjectSubmissionRetry(
    generation,
    boundary,
  ).finally(() => {
    if (pendingProjectRetryFlights.get(flightKey) === flight) {
      pendingProjectRetryFlights.delete(flightKey);
    }
  });
  pendingProjectRetryFlights.set(flightKey, flight);
  return flight;
};

export const unlockAfterProject = (
  course: CourseLearningData,
  projectId: string,
  status: ProjectStatus = 'passed',
): CourseLearningData => {
  const mayUnlock = status === 'passed';
  let unlockNext = false;
  return {
    ...course,
    modules: course.modules.map(module => {
      const isNext = mayUnlock && unlockNext;
      const projects = module.projects?.length
        ? module.projects
        : module.project
        ? [module.project]
        : [];
      const isCurrent = projects.some(project => project.id === projectId);
      const updatedProjects = projects.map(project =>
        project.id === projectId ? {...project, status} : project,
      );
      unlockNext =
        mayUnlock &&
        isCurrent &&
        updatedProjects.every(project => project.status === 'passed');
      return {
        ...module,
        isLocked: isNext ? false : module.isLocked,
        reels: module.reels.map((reel, reelIndex) => ({
          ...reel,
          // A confirmed project pass unlocks the next module, not media that
          // the API has not authorised. Its media URL arrives separately in
          // a short-lived playback manifest.
          isLocked: isNext
            ? reelIndex !== 0 ||
              (!reel.videoUrl && !reel.fallbackVideoUrl)
            : reel.isLocked,
        })),
        projects: updatedProjects,
        project: updatedProjects[0],
      };
    }),
  };
};
