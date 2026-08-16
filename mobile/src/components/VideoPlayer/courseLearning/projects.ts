import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';
import {
  assertPendingProjectCacheCapacity,
  PROJECT_SUBMISSION_MAX_BYTES,
  validateProjectFile,
} from '../../../config/projects';
import {publicRequest} from '../../../constants/api';
import {getCurrentAccountStorageScope} from '../../../constants/helpers';
import {requireProductFeature} from '../../../services/productFeatures';
import type {
  CourseLearningData,
  ProjectStatus,
  SelectedProjectFile,
} from '../types';
import {resetPlayerStateRuntime, updatePlayerState} from './persistence';
import {resetPlaybackRuntimeState} from './playback';
import {asRecord, DataRecord, valueAsString} from './shared';

const PROJECT_SUBMISSION_PREFIX = '@rokn/project-submission/v2';
const PROJECT_FILE_CACHE_DIR = `${RNFS.CachesDirectoryPath}/rokn_project_submissions`;

export type ProjectSubmissionOutcome = {
  passed: boolean;
  synced: boolean;
  provisional: boolean;
  canContinue: boolean;
};

export const submitProjectAttempt = async (
  projectId: string,
  selectedFile?: SelectedProjectFile | null,
  submissionText?: string,
): Promise<ProjectSubmissionOutcome> => {
  if (projectId.startsWith('demo')) {
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
  const result = await Promise.race([
    syncProjectSubmission(pending),
    new Promise<SubmissionSyncResult>(resolve => {
      foregroundBudgetTimer = setTimeout(
        () => resolve({passed: false, terminal: false}),
        // The server's normal fallback review is eight seconds. Keep this
        // bounded, but long enough to receive the authoritative unlock in the
        // common case instead of presenting an empty next module.
        15000,
      );
    }),
  ]).finally(() => {
    if (foregroundBudgetTimer) {
      clearTimeout(foregroundBudgetTimer);
    }
  });

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
      synced: false,
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
};

const projectSubmissionFlights = new Map<
  string,
  Promise<SubmissionSyncResult>
>();
let pendingProjectRetryFlight: Promise<ProjectSubmissionRetryOutcome[]> | null =
  null;

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
      submissionText?.trim() || '',
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

  resetPlaybackRuntimeState();
  resetPlayerStateRuntime();
  projectSubmissionFlights.clear();
  pendingProjectRetryFlight = null;
  await cleanupProjectFileCache();
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
      copiedSize > PROJECT_SUBMISSION_MAX_BYTES
    ) {
      await RNFS.unlink(destination).catch(() => undefined);
      throw new Error('PROJECT_FILE_TOO_LARGE');
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
    // Foreground upload can still use the picker URI. If Android revokes it
    // later, the learner sees a retry state rather than a fabricated pass.
    return {...selectedFile, size: validatedSize};
  }
};

const getOrCreatePendingSubmission = async (
  projectId: string,
  selectedFile?: SelectedProjectFile | null,
  submissionText?: string,
) => {
  const fingerprint = submissionFingerprint(
    projectId,
    selectedFile,
    submissionText,
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
    submissionText: submissionText?.trim(),
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

const makeSubmissionForm = (
  pending: PendingProjectSubmission,
  legacy = false,
) => {
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
  if (legacy) {
    // Kept only for servers that have not deployed the submissions route yet.
    form.append('score', '100');
    form.append('passed', '1');
  }
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
    payload?.can_continue === true ||
    payload?.passed === true ||
    ['passed', 'approved', 'completed'].includes(status)
  ) {
    return {passed: true, terminal: true};
  }
  if (
    payload?.needs_resubmission === true ||
    [
      'needs_resubmission',
      'rejected',
      'failed',
      'needs_retry',
      'needs_revision',
      'invalid',
    ].includes(status)
  ) {
    return {passed: false, terminal: true};
  }
  return {passed: false, terminal: false};
};

const waitFor = (milliseconds: number) =>
  new Promise<void>(resolve => setTimeout(resolve, milliseconds));

const pollProjectSubmission = async (
  pending: PendingProjectSubmission,
  attempts = 5,
): Promise<SubmissionSyncResult> => {
  if (!pending.publicId) {
    return {passed: false, terminal: false};
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
      const response = await publicRequest.get(
        `project-submissions/${pending.publicId}`,
        {timeout: 12000},
      );
      const result = normaliseSubmissionResult(unwrapResponseData(response));
      if (result.terminal) {
        return result;
      }
    } catch {
      return {passed: false, terminal: false};
    }
  }
  return {passed: false, terminal: false};
};

const performProjectSubmissionSync = async (
  pending: PendingProjectSubmission,
): Promise<SubmissionSyncResult> => {
  if (pending.publicId) {
    if (pending.selectedFile) {
      const cachedFile = pending.selectedFile;
      pending.selectedFile = null;
      await savePendingSubmission(pending);
      await removeCachedProjectFile({...pending, selectedFile: cachedFile});
    }
    const existingResult = await pollProjectSubmission(pending);
    if (existingResult.terminal) {
      return existingResult;
    }
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
    response = await publicRequest.post(
      `projects/${pending.projectId}/submissions`,
      makeSubmissionForm(pending),
      requestConfig,
    );
  } catch {
    try {
      response = await publicRequest.post(
        `projects/${pending.projectId}/evaluate`,
        makeSubmissionForm(pending, true),
        requestConfig,
      );
    } catch {
      return {passed: false, terminal: false};
    }
  }

  const payload = unwrapResponseData(response);
  const immediateResult = normaliseSubmissionResult(payload);
  if (immediateResult.terminal) {
    return immediateResult;
  }

  const publicId = valueAsString(
    payload?.id || payload?.public_id || payload?.submission_id,
  );
  if (!publicId) {
    return {passed: false, terminal: false};
  }
  pending.publicId = publicId;
  pending.pollAfterSeconds = Number(payload?.poll_after_seconds) || 1;
  const uploadedFile = pending.selectedFile;
  pending.selectedFile = null;
  await savePendingSubmission(pending);
  await removeCachedProjectFile({...pending, selectedFile: uploadedFile});
  return pollProjectSubmission(pending);
};

const syncProjectSubmission = (
  pending: PendingProjectSubmission,
): Promise<SubmissionSyncResult> => {
  const existing = projectSubmissionFlights.get(pending.clientSubmissionId);
  if (existing) return existing;
  const flight = performProjectSubmissionSync(pending).finally(() => {
    projectSubmissionFlights.delete(pending.clientSubmissionId);
  });
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
          // the API has not authorised or supplied yet.
          isLocked: isNext
            ? reelIndex !== 0 || !reel.videoUrl.trim()
            : reel.isLocked,
        })),
        project: isCurrent ? {...module.project!, status} : module.project,
      };
    }),
  };
};
