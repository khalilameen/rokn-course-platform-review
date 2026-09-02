import AsyncStorage from '@react-native-async-storage/async-storage';
import {isLocalDemoId} from '../../../config/runtime';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getCurrentAccountStorageScope,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import type {CourseLearningData, CourseLearningModule} from '../types';
import {asArray} from './shared';

const PLAYER_STATE_KEY = '@rokn/course-player/v3';
export const WATCH_HISTORY_ENABLED_KEY = 'PREF_WATCH_HISTORY';
const MAX_LOCAL_RESUME_ENTRIES = 300;
const playerStateQueues = new Map<string, Promise<unknown>>();

type PersistedPlayerState = {
  positions: Record<string, number>;
  lastWatchedAt: Record<string, string>;
  completedSections: string[];
  savedLessons: string[];
  savedFolderLessons: Record<string, string[]>;
  passedProjects: string[];
  provisionalProjects: string[];
  activityDays: string[];
};

const EMPTY_STATE: PersistedPlayerState = {
  positions: {},
  lastWatchedAt: {},
  completedSections: [],
  savedLessons: [],
  savedFolderLessons: {},
  passedProjects: [],
  provisionalProjects: [],
  activityDays: [],
};

const compactResumeState = (
  rawPositions: unknown,
  rawLastWatchedAt: unknown,
) => {
  const positionsSource =
    rawPositions &&
    typeof rawPositions === 'object' &&
    !Array.isArray(rawPositions)
      ? (rawPositions as Record<string, unknown>)
      : {};
  const watchedSource =
    rawLastWatchedAt &&
    typeof rawLastWatchedAt === 'object' &&
    !Array.isArray(rawLastWatchedAt)
      ? (rawLastWatchedAt as Record<string, unknown>)
      : {};
  const keys = Array.from(
    new Set([...Object.keys(positionsSource), ...Object.keys(watchedSource)]),
  )
    .map((key, index) => ({
      index,
      key,
      watchedAt: Date.parse(String(watchedSource[key] || '')) || 0,
    }))
    .sort(
      (left, right) =>
        right.watchedAt - left.watchedAt || right.index - left.index,
    )
    .slice(0, MAX_LOCAL_RESUME_ENTRIES);

  const positions: Record<string, number> = {};
  const lastWatchedAt: Record<string, string> = {};
  keys.forEach(({key, watchedAt}) => {
    const seconds = Number(positionsSource[key]);
    if (Number.isFinite(seconds) && seconds >= 0) {
      positions[key] = seconds;
    }
    if (watchedAt > 0) {
      lastWatchedAt[key] = new Date(watchedAt).toISOString();
    }
  });
  return {positions, lastWatchedAt};
};

const compactPlayerState = (
  state: PersistedPlayerState,
): PersistedPlayerState => ({
  ...state,
  ...compactResumeState(state.positions, state.lastWatchedAt),
  activityDays: Array.from(new Set(state.activityDays)).slice(-60),
});

export const readPlayerState = async (
  scopedStorageKey?: string,
  accountBoundary?: AccountSessionBoundary,
): Promise<PersistedPlayerState> => {
  const boundary =
    accountBoundary ||
    (scopedStorageKey ? undefined : await captureAccountSessionBoundary());
  const storageKey =
    scopedStorageKey ||
    (await accountScopedStorageKey(PLAYER_STATE_KEY, boundary));
  let value: string | null;
  try {
    value = await AsyncStorage.getItem(storageKey);
  } catch {
    return {...EMPTY_STATE};
  }
  if (boundary) assertAccountSessionBoundary(boundary);
  try {
    if (!value) {
      return {...EMPTY_STATE};
    }
    const parsed = JSON.parse(value);
    const compactResume = compactResumeState(
      parsed?.positions,
      parsed?.lastWatchedAt,
    );
    const state = compactPlayerState({
      ...compactResume,
      completedSections: asArray(parsed?.completedSections),
      savedLessons: asArray(parsed?.savedLessons),
      savedFolderLessons:
        parsed?.savedFolderLessons &&
        typeof parsed.savedFolderLessons === 'object'
          ? Object.fromEntries(
              Object.entries(parsed.savedFolderLessons).map(
                ([folderId, lessons]) => [
                  folderId,
                  asArray(lessons as string[]),
                ],
              ),
            )
          : {},
      passedProjects: asArray(parsed?.passedProjects),
      provisionalProjects: asArray(parsed?.provisionalProjects),
      activityDays: asArray(parsed?.activityDays),
    });
    if (
      Object.keys(parsed?.positions || {}).length > MAX_LOCAL_RESUME_ENTRIES ||
      Object.keys(parsed?.lastWatchedAt || {}).length >
        MAX_LOCAL_RESUME_ENTRIES ||
      asArray(parsed?.activityDays).length > state.activityDays.length
    ) {
      if (boundary) assertAccountSessionBoundary(boundary);
      await AsyncStorage.setItem(storageKey, JSON.stringify(state));
      if (boundary) assertAccountSessionBoundary(boundary);
    }
    return state;
  } catch {
    return {...EMPTY_STATE};
  }
};

const mergeStringArrays = (left: string[], right: string[]) =>
  Array.from(new Set([...left, ...right]));

/**
 * Keeps free-preview progress when a guest creates an account, while never
 * importing demo project passes into a real learner record.
 */
export const migrateGuestLearningState = async (
  guestScope: string,
  accountBoundary?: AccountSessionBoundary,
): Promise<boolean> => {
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  const accountScope =
    accountBoundary?.scope ?? (await getCurrentAccountStorageScope());
  if (!guestScope || guestScope === accountScope) {
    return false;
  }
  const sourceKey = `${PLAYER_STATE_KEY}:${guestScope}`;
  const targetKey = `${PLAYER_STATE_KEY}:${accountScope}`;
  const [[, sourceValue], [, targetValue]] = await AsyncStorage.multiGet([
    sourceKey,
    targetKey,
  ]);
  if (!sourceValue) {
    return true;
  }
  let source: Partial<PersistedPlayerState>;
  let target: Partial<PersistedPlayerState>;
  try {
    source = JSON.parse(sourceValue) as Partial<PersistedPlayerState>;
    target = targetValue
      ? (JSON.parse(targetValue) as Partial<PersistedPlayerState>)
      : {};
  } catch {
    // Keep a damaged guest cache for a later app migration.
    return false;
  }
  const sourceFolders = source.savedFolderLessons || {};
  const targetFolders = target.savedFolderLessons || {};
  const folderIds = new Set([
    ...Object.keys(sourceFolders),
    ...Object.keys(targetFolders),
  ]);
  const savedFolderLessons = Object.fromEntries(
    Array.from(folderIds).map(folderId => [
      folderId,
      mergeStringArrays(
        asArray(sourceFolders[folderId]),
        asArray(targetFolders[folderId]),
      ),
    ]),
  );
  const next = compactPlayerState({
    positions: {...(source.positions || {}), ...(target.positions || {})},
    lastWatchedAt: {
      ...(source.lastWatchedAt || {}),
      ...(target.lastWatchedAt || {}),
    },
    completedSections: mergeStringArrays(
      asArray(source.completedSections),
      asArray(target.completedSections),
    ),
    savedLessons: mergeStringArrays(
      asArray(source.savedLessons),
      asArray(target.savedLessons),
    ),
    savedFolderLessons,
    passedProjects: asArray<string>(target.passedProjects).filter(
      id => !id.startsWith('demo'),
    ),
    provisionalProjects: asArray<string>(target.provisionalProjects).filter(
      id => !id.startsWith('demo'),
    ),
    activityDays: mergeStringArrays(
      asArray(source.activityDays),
      asArray(target.activityDays),
    ).slice(-60),
  });
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  await AsyncStorage.setItem(targetKey, JSON.stringify(next));
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  return true;
};

export const updatePlayerState = async (
  update: (state: PersistedPlayerState) => PersistedPlayerState,
  scopedStorageKey?: string,
  accountBoundary?: AccountSessionBoundary,
) => {
  // Resolve the account once per operation. A global queue that recalculates
  // the key at write time can leak progress from account A into account B if
  // logout/login happens while an update is waiting.
  const boundary =
    accountBoundary ||
    (scopedStorageKey ? undefined : await captureAccountSessionBoundary());
  const storageKey =
    scopedStorageKey ||
    (await accountScopedStorageKey(PLAYER_STATE_KEY, boundary));
  const previous = playerStateQueues.get(storageKey) ?? Promise.resolve();
  const operation = previous.then(async () => {
    if (boundary) assertAccountSessionBoundary(boundary);
    const current = await readPlayerState(storageKey, boundary);
    const next = compactPlayerState(update(current));
    if (boundary) assertAccountSessionBoundary(boundary);
    await AsyncStorage.setItem(storageKey, JSON.stringify(next));
    if (boundary) assertAccountSessionBoundary(boundary);
    return next;
  });
  const settled = operation.catch(() => undefined);
  playerStateQueues.set(storageKey, settled);
  void settled.finally(() => {
    if (playerStateQueues.get(storageKey) === settled) {
      playerStateQueues.delete(storageKey);
    }
  });
  return operation;
};

export const updatePlayerStateForScope = async (
  accountScope: string,
  update: (state: PersistedPlayerState) => PersistedPlayerState,
  boundary?: AccountSessionBoundary,
) => {
  if (!/^[a-z0-9_-]+$/i.test(accountScope)) {
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  }
  if (boundary) assertAccountSessionBoundary(boundary);
  const storageKey = `${PLAYER_STATE_KEY}:${accountScope}`;
  const result = await updatePlayerState(update, storageKey, boundary);
  if (!boundary) return result;
  try {
    assertAccountSessionBoundary(boundary);
    return result;
  } catch (error) {
    // A different owner may have cleared this scope while the native storage
    // write was already in progress. Remove only the obsolete owner's key;
    // a same-account token refresh keeps its valid learning record.
    if ((await getCurrentAccountStorageScope()) !== accountScope) {
      await AsyncStorage.removeItem(storageKey);
    }
    throw error;
  }
};

export const readPlayerStateForScope = async (
  accountScope: string,
  boundary?: AccountSessionBoundary,
) => {
  if (!/^[a-z0-9_-]+$/i.test(accountScope)) {
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  }
  if (boundary) assertAccountSessionBoundary(boundary);
  const state = await readPlayerState(
    `${PLAYER_STATE_KEY}:${accountScope}`,
    boundary,
  );
  if (boundary) assertAccountSessionBoundary(boundary);
  return state;
};

export const getLocalLearningState = readPlayerState;

export const isWatchHistoryEnabled = async (): Promise<boolean> => {
  const boundary = await captureAccountSessionBoundary();
  let stored: string | null;
  try {
    stored = await AsyncStorage.getItem(
      await accountScopedStorageKey(WATCH_HISTORY_ENABLED_KEY, boundary),
    );
  } catch {
    return true;
  }
  assertAccountSessionBoundary(boundary);
  try {
    return stored === null ? true : JSON.parse(stored) !== false;
  } catch {
    return true;
  }
};

/**
 * Removes only the optional viewing trail and resume positions. Course
 * completion, unlocked modules, projects, certificates and saved lists stay
 * untouched because they are part of the learning record, not watch history.
 */
export const clearLocalWatchHistory = async () =>
  updatePlayerState(state => ({
    ...state,
    positions: {},
    lastWatchedAt: {},
  }));

export const resetPlayerStateRuntime = () => {
  playerStateQueues.clear();
};

export const applyLocalLearningState = async (
  course: CourseLearningData,
): Promise<CourseLearningData> => {
  const state = await readPlayerState();
  const canAuthoriseLocally = isLocalDemoId(course.id);
  let previousProjectPassed = true;
  return {
    ...course,
    modules: course.modules.map((module, index) => {
      // Local state remembers presentation and retryable writes. It is never
      // an entitlement for production content: only the API may expose a
      // module and its signed media source.
      const locallyUnlocked = index === 0 || previousProjectPassed;
      const moduleUnlocked = canAuthoriseLocally
        ? locallyUnlocked
        : !module.isLocked;
      const projectPassed = module.project
        ? module.project.status === 'passed' ||
          (canAuthoriseLocally &&
            state.passedProjects.includes(module.project.id))
        : true;
      const projectProvisional = module.project
        ? state.provisionalProjects.includes(module.project.id)
        : false;
      let allPreviousReelsCompleted = true;
      const reels = module.reels.map((reel, reelIndex) => {
        const isCompleted =
          reel.isCompleted || state.completedSections.includes(reel.sectionId);
        const locallyReached = reelIndex === 0 || allPreviousReelsCompleted;
        const isLocked = canAuthoriseLocally
          ? !moduleUnlocked || !reel.videoUrl.trim() || !locallyReached
          : !moduleUnlocked || reel.isLocked;
        allPreviousReelsCompleted = allPreviousReelsCompleted && isCompleted;
        return {
          ...reel,
          isLocked,
          isCompleted,
        };
      });
      const nextModule: CourseLearningModule = {
        ...module,
        isLocked: !moduleUnlocked,
        reels,
        project: module.project
          ? {
              ...module.project,
              status: projectPassed
                ? 'passed'
                : projectProvisional
                ? 'reviewing'
                : module.project.status,
            }
          : undefined,
      };
      // Reviewing is a visible saved state, not an entitlement. Only an
      // authoritative pass may expose the following module and its media.
      previousProjectPassed = locallyUnlocked && projectPassed;
      return nextModule;
    }),
  };
};
