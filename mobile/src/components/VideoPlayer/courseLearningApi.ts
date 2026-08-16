export {
  applyLocalLearningState,
  clearLocalWatchHistory,
  getLocalLearningState,
  isWatchHistoryEnabled,
  migrateGuestLearningState,
  WATCH_HISTORY_ENABLED_KEY,
} from './courseLearning/persistence';
export {
  loadCourseLearningData,
  mapCoursePayload,
} from './courseLearning/mapping';
export {
  flushPendingPlaybackPositions,
  markSectionComplete,
  openPlaybackSession,
  reportPlaybackSessionEvent,
  retryPendingPlaybackPositions,
  retryPendingSectionCompletions,
  savePlaybackPosition,
} from './courseLearning/playback';
export type {
  PlaybackClientPreference,
  PlaybackEvidenceContext,
  PlaybackManifest,
  PlaybackSessionEvent,
} from './courseLearning/playback';
export {
  createSavedFolderOption,
  deleteSavedFolderOption,
  getSavedFolderOptions,
  removeLessonFromSavedFolder,
  saveLessonToFolder,
  toggleWatchLater,
} from './courseLearning/savedCollections';
export type {SavedFolderOption} from './courseLearning/savedCollections';
export {
  clearCurrentAccountLearningFiles,
  retryPendingProjectSubmissions,
  submitProjectAttempt,
  unlockAfterProject,
} from './courseLearning/projects';
export type {
  ProjectSubmissionOutcome,
  ProjectSubmissionRetryOutcome,
} from './courseLearning/projects';
export {
  askCourseAssistant,
  courseIncludesAssistant,
} from './courseLearning/assistant';
