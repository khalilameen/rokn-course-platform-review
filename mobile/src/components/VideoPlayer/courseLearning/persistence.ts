import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  getCurrentAccountStorageScope,
} from '../../../constants/helpers';
import type {CourseLearningData, CourseLearningModule} from '../types';
import {asArray} from './shared';

const PLAYER_STATE_KEY = '@rokn/course-player/v3';
export const WATCH_HISTORY_ENABLED_KEY = 'PREF_WATCH_HISTORY';
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

export const readPlayerState = async (
  scopedStorageKey?: string,
): Promise<PersistedPlayerState> => {
  try {
    const value = await AsyncStorage.getItem(
      scopedStorageKey || (await accountScopedStorageKey(PLAYER_STATE_KEY)),
    );
    if (!value) {
      return {...EMPTY_STATE};
    }
    const parsed = JSON.parse(value);
    return {
      positions: parsed?.positions || {},
      lastWatchedAt: parsed?.lastWatchedAt || {},
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
    };
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
): Promise<void> => {
  const accountScope = await getCurrentAccountStorageScope();
  if (!guestScope || guestScope === accountScope) {
    return;
  }
  const sourceKey = `${PLAYER_STATE_KEY}:${guestScope}`;
  const targetKey = `${PLAYER_STATE_KEY}:${accountScope}`;
  const [[, sourceValue], [, targetValue]] = await AsyncStorage.multiGet([
    sourceKey,
    targetKey,
  ]);
  if (!sourceValue) {
    return;
  }
  try {
    const source = JSON.parse(sourceValue) as Partial<PersistedPlayerState>;
    const target = targetValue
      ? (JSON.parse(targetValue) as Partial<PersistedPlayerState>)
      : {};
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
    const next: PersistedPlayerState = {
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
    };
    await AsyncStorage.setItem(targetKey, JSON.stringify(next));
    await AsyncStorage.removeItem(sourceKey);
  } catch {
    // Discard a damaged guest cache during sign-in.
  }
};

export const updatePlayerState = async (
  update: (state: PersistedPlayerState) => PersistedPlayerState,
) => {
  // Resolve the account once per operation. A global queue that recalculates
  // the key at write time can leak progress from account A into account B if
  // logout/login happens while an update is waiting.
  const storageKey = await accountScopedStorageKey(PLAYER_STATE_KEY);
  const previous = playerStateQueues.get(storageKey) ?? Promise.resolve();
  const operation = previous.then(async () => {
    const current = await readPlayerState(storageKey);
    const next = update(current);
    await AsyncStorage.setItem(storageKey, JSON.stringify(next));
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

export const getLocalLearningState = readPlayerState;

export const isWatchHistoryEnabled = async (): Promise<boolean> => {
  try {
    const stored = await AsyncStorage.getItem(
      await accountScopedStorageKey(WATCH_HISTORY_ENABLED_KEY),
    );
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
  let previousProjectPassed = true;
  return {
    ...course,
    modules: course.modules.map((module, index) => {
      const moduleUnlocked = index === 0 || previousProjectPassed;
      const projectPassed = module.project
        ? module.project.status === 'passed' ||
          state.passedProjects.includes(module.project.id)
        : true;
      const projectProvisional = module.project
        ? state.provisionalProjects.includes(module.project.id)
        : false;
      let allPreviousReelsCompleted = true;
      const reels = module.reels.map((reel, reelIndex) => {
        const isCompleted =
          reel.isCompleted || state.completedSections.includes(reel.sectionId);
        const locallyReached = reelIndex === 0 || allPreviousReelsCompleted;
        const isLocked =
          !moduleUnlocked ||
          !reel.videoUrl.trim() ||
          (reel.isLocked && !locallyReached);
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
      previousProjectPassed = moduleUnlocked && projectPassed;
      return nextModule;
    }),
  };
};
