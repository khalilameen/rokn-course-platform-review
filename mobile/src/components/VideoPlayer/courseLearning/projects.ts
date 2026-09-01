import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';
import {
  assertPendingProjectCacheCapacity,
  PROJECT_SUBMISSION_MAX_BYTES,
  validateProjectFile,
} from '../../../config/projects';
import {isLocalDemoId} from '../../../config/runtime';
import {publicRequest} from '../../../constants/api';
import {getCurrentAccountStorageScope} from '../../../constants/helpers';
import {requireProductFeature} from '../../../services/productFeatures';
import {clearAccountLearnerDraftFiles} from '../../../services/learnerDraftFiles';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {cleanUnicodeText} from '../../../utils/unicodeText';
import type {
  CourseLearningData,
  ProjectFeedbackThread,
  ProjectStatus,
  SelectedProjectFile,
} from '../types';
import {resetPlayerStateRuntime, updatePlayerState} from './persistence';
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
        },
      ];
    }),
  };
};

export const loadProjectFeedbackThread = async (
  projectId: string,
  threadId?: string,
): Promise<ProjectFeedbackThread | null> => {
  const response = threadId
    ? await publicRequest.get(
        `project-feedback-threads/${publicRouteId(threadId, 'PROJECT_THREAD')}`,
      )
    : await publicRequest.get(`projects/${numericProjectId(projectId)}`);
  const payload = unwrapResponseData(response);
  return normaliseFeedbackThread(
    threadId ? payload : asRecord(payload.latest_submission).feedback_thread,
  );
};

export const sendProjectFeedbackMessage = async (
  threadId: string,
  message: string,
  clientRequestId = secureRandomUuid(),
): Promise<ProjectFeedbackThread> => {
  const normalizedThreadId = publicRouteId(threadId, 'PROJECT_THREAD');
  const response = await publicRequest.post(
    `project-feedback-threads/${normalizedThreadId}/messages`,
    {message: cleanUnicodeText(message), client_request_id: clientRequestId},
    {headers: {'Idempotency-Key': clientRequestId}, timeout: 30000},
  );
  const thread = normaliseFeedbackThread(unwrapResponseData(response));
  if (!thread) throw new Error('PROJECT_FEEDBACK_THREAD_UNAVAILABLE');
  return thread;
};

export const submitProjectAttempt = async (
  projectId: string,
  selectedFile?: SelectedProjectFile | null,
  submissionText?: string,
): Promise<ProjectSubmissionOutcome> => {
  if (isLocalDemoId(projectId)) {
    await passProjectLocally(projectId);
    return {
      passed: true,
      synced: false,
      provisional: false,
      canContinue: true,
    };
  }

  await requireProductFeature('project_uploads');

  const pending = await getOrCreatePendingSubmission(
    projectId,
    selectedFile,
    submissionText,
  );
  let foregroundBudgetTimer: ReturnType<typeof setTimeout> | undefined;
  let result: SubmissionSyncResult;
  try {
    result = await Promise.race([
      syncProjectSubmission(pending),
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
    if (!retryableRequestFailure(error)) {
      await removeCachedProjectFile(pending);
      await AsyncStorage.removeItem(await projectSubmissionKey(projectId));
    }
    throw error;
  } finally {
    if (foregroundBudgetTimer) {
      clearTimeout(foregroundBudgetTimer);
    }
  }

  if (result.passed) {
    await passProjectLocally(projectId);
    await removeCachedProjectFile(pending);
    await AsyncStorage.removeItem(await projectSubmissionKey(projectId));
    return {
      passed: true,
      synced: true,
      provisional: false,
      canContinue: true,
    };
  }

  // Stay on review until the API unlocks the next module.
  if (!result.terminal) {
    await markProjectProvisional(projectId);
    return {
      passed: false,
      synced: result.accepted,
      provisional: true,
      canContinue: false,
    };
  }

  await clearProjectLocalReviewState(projectId);
  await removeCachedProjectFile(pending);
  await AsyncStorage.removeItem(await projectSubmissionKey(projectId));
  return {
    passed: false,
    synced: true,
    provisional: false,
    canContinue: false,
  };
};

type PendingProjectSubmission = {
  projectId: string;
  clientSubmissionId: string;
  fingerprint: string;
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
let pendingProjectRetryFlight: Promise<ProjectSubmissionRetryOutcome[]> | null =
  null;
let projectRuntimeGeneration = 0;

const assertProjectRuntime = (generation: number) => {
  if (generation !== projectRuntimeGeneration) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
};

const projectSubmissionPrefix = async () =>
  `${PROJECT_SUBMISSION_PREFIX}:${await getCurrentAccountStorageScope()}:`;

const projectSubmissionKey = async (projectId: string) =>
  `${await projectSubmissionPrefix()}${projectId}`;

const hashText = (value: string) => {
  let hash = 5381;
  for (let index = 0; index < value.length; index += 1) {
    hash = (hash * 33 + value.charCodeAt(index)) % 2147483647;
  }
  return hash.toString(36);
};

const submissionFingerprint = (
  projectId: string,
  selectedFile?: SelectedProjectFile | null,
  submissionText?: string,
) =>
  hashText(
    [
      projectId,
      selectedFile?.uri || '',
      selectedFile?.name || '',
      selectedFile?.type || '',
      selectedFile?.size || 0,
      cleanUnicodeText(submissionText || ''),
    ].join('|'),
  );

const createClientSubmissionId = (projectId: string, fingerprint: string) =>
  `rokn-${projectId
    .replace(/[^a-zA-Z0-9_-]/g, '')
    .slice(-18)}-${Date.now().toString(36)}-${fingerprint}`;

const readPendingSubmission = async (projectId: string) => {
  try {
    const value = await AsyncStorage.getItem(
      await projectSubmissionKey(projectId),
    );
    return value ? (JSON.parse(value) as PendingProjectSubmission) : null;
  } catch {
    return null;
  }
};

const savePendingSubmission = async (pending: PendingProjectSubmission) => {
  await AsyncStorage.setItem(
    await projectSubmissionKey(pending.projectId),
    JSON.stringify(pending),
  );
};

const cachedProjectFilePath = (uri?: string | null) =>
  uri?.replace(/^file:\/\//, '') || '';

const removeCachedProjectFile = async (
  pending?: PendingProjectSubmission | null,
) => {
  const path = cachedProjectFilePath(pending?.selectedFile?.uri);
  if (!path || !path.startsWith(PROJECT_FILE_CACHE_DIR)) {
    return;
  }
  await RNFS.unlink(path).catch(() => undefined);
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
  pendingProjectRetryFlight = null;
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
          : cachedProjectFilePath(pending?.selectedFile?.uri);
        return activePath ? [activePath] : [];
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
  selectedFile?: SelectedProjectFile | null,
  submissionText?: string,
) => {
  const normalizedSubmissionText = cleanUnicodeText(submissionText || '');
  const fingerprint = submissionFingerprint(
    projectId,
    selectedFile,
    normalizedSubmissionText,
  );
  const existing = await readPendingSubmission(projectId);
  if (existing?.fingerprint === fingerprint) {
    return existing;
  }
  if (existing?.publicId) {
    return existing;
  }

  const cachedSelectedFile = await cachePendingProjectFile(
    projectId,
    fingerprint,
    selectedFile,
    existing?.selectedFile,
  );
  const pending: PendingProjectSubmission = {
    projectId,
    selectedFile: cachedSelectedFile,
    submissionText: normalizedSubmissionText || undefined,
    fingerprint,
    clientSubmissionId: createClientSubmissionId(projectId, fingerprint),
  };
  try {
    await savePendingSubmission(pending);
  } catch (error) {
    if (
      cachedProjectFilePath(cachedSelectedFile?.uri) !==
      cachedProjectFilePath(existing?.selectedFile?.uri)
    ) {
      await removeCachedProjectFile({
        ...pending,
        selectedFile: cachedSelectedFile,
      });
    }
    throw error;
  }
  if (
    cachedProjectFilePath(cachedSelectedFile?.uri) !==
    cachedProjectFilePath(existing?.selectedFile?.uri)
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
  if (pending.selectedFile) {
    form.append('submission_file', {
      uri: pending.selectedFile.uri,
      name: pending.selectedFile.name,
      type: pending.selectedFile.type,
    } as unknown as Blob);
  }
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
      assertProjectRuntime(generation);
      const response = await publicRequest.get(
        `project-submissions/${publicRouteId(
          pending.publicId,
          'PROJECT_SUBMISSION',
        )}`,
        {timeout: 12000},
      );
      assertProjectRuntime(generation);
      const result = normaliseSubmissionResult(unwrapResponseData(response));
      if (result.terminal) {
        return result;
      }
    } catch {
      return {passed: false, terminal: false, accepted: true};
    }
  }
  return {passed: false, terminal: false, accepted: true};
};

const performProjectSubmissionSync = async (
  pending: PendingProjectSubmission,
  generation: number,
): Promise<SubmissionSyncResult> => {
  assertProjectRuntime(generation);
  if (pending.publicId) {
    if (pending.selectedFile) {
      const cachedFile = pending.selectedFile;
      pending.selectedFile = null;
      await savePendingSubmission(pending);
      await removeCachedProjectFile({...pending, selectedFile: cachedFile});
    }
    const existingResult = await pollProjectSubmission(pending, 5, generation);
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
    assertProjectRuntime(generation);
    response = await publicRequest.post(
      `projects/${pending.projectId}/submissions`,
      makeSubmissionForm(pending),
      requestConfig,
    );
    assertProjectRuntime(generation);
  } catch (error) {
    assertProjectRuntime(generation);
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
  const uploadedFile = pending.selectedFile;
  pending.selectedFile = null;
  await savePendingSubmission(pending);
  await removeCachedProjectFile({...pending, selectedFile: uploadedFile});
  assertProjectRuntime(generation);
  return pollProjectSubmission(pending, 5, generation);
};

const syncProjectSubmission = (
  pending: PendingProjectSubmission,
): Promise<SubmissionSyncResult> => {
  const existing = projectSubmissionFlights.get(pending.clientSubmissionId);
  if (existing) return existing;
  const generation = projectRuntimeGeneration;
  const flight = performProjectSubmissionSync(pending, generation).finally(
    () => {
      projectSubmissionFlights.delete(pending.clientSubmissionId);
    },
  );
  projectSubmissionFlights.set(pending.clientSubmissionId, flight);
  return flight;
};

const passProjectLocally = (projectId: string) =>
  updatePlayerState(state => ({
    ...state,
    passedProjects: Array.from(new Set([...state.passedProjects, projectId])),
    provisionalProjects: state.provisionalProjects.filter(
      id => id !== projectId,
    ),
  }));

const markProjectProvisional = (projectId: string) =>
  updatePlayerState(state => ({
    ...state,
    provisionalProjects: Array.from(
      new Set([...state.provisionalProjects, projectId]),
    ),
  }));

const clearProjectLocalReviewState = (projectId: string) =>
  updatePlayerState(state => ({
    ...state,
    passedProjects: state.passedProjects.filter(id => id !== projectId),
    provisionalProjects: state.provisionalProjects.filter(
      id => id !== projectId,
    ),
  }));

export type ProjectSubmissionRetryOutcome = {
  projectId: string;
  passed: boolean;
  terminal: boolean;
  accepted: boolean;
};

const performPendingProjectSubmissionRetry = async (): Promise<
  ProjectSubmissionRetryOutcome[]
> => {
  await cleanupProjectFileCache();
  const outcomes: ProjectSubmissionRetryOutcome[] = [];
  const currentPrefix = await projectSubmissionPrefix();
  const keys = (await AsyncStorage.getAllKeys()).filter(key =>
    key.startsWith(currentPrefix),
  );
  if (!keys.length) {
    return outcomes;
  }
  const entries = await AsyncStorage.multiGet(keys);
  for (const [key, value] of entries) {
    if (!value) {
      continue;
    }
    try {
      const pending = JSON.parse(value) as PendingProjectSubmission;
      const result = await syncProjectSubmission(pending);
      outcomes.push({projectId: pending.projectId, ...result});
      if (result.terminal) {
        if (result.passed) {
          await passProjectLocally(pending.projectId);
        } else {
          await clearProjectLocalReviewState(pending.projectId);
        }
        await removeCachedProjectFile(pending);
        await AsyncStorage.removeItem(key);
      }
    } catch {
      // The next course opening will retry without interrupting the learner.
    }
  }
  return outcomes;
};

export const retryPendingProjectSubmissions = (): Promise<
  ProjectSubmissionRetryOutcome[]
> => {
  if (!pendingProjectRetryFlight) {
    pendingProjectRetryFlight = performPendingProjectSubmissionRetry().finally(
      () => {
        pendingProjectRetryFlight = null;
      },
    );
  }
  return pendingProjectRetryFlight;
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
      const isCurrent = module.project?.id === projectId;
      unlockNext = mayUnlock && isCurrent;
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
        project: isCurrent ? {...module.project!, status} : module.project,
      };
    }),
  };
};
