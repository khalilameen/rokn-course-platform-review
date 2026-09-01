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
  persistLocalPlaybackPosition,
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
  migrateGuestSavedCollections,
  reconcileServerSavedLessons,
  removeLessonFromSavedFolder,
  saveLessonToFolder,
  toggleWatchLater,
} from './courseLearning/savedCollections';
export type {SavedFolderOption} from './courseLearning/savedCollections';
export {
  clearCurrentAccountLearningFiles,
  quiesceLearningRuntime,
  retryPendingProjectSubmissions,
  loadProjectFeedbackThread,
  sendProjectFeedbackMessage,
  submitProjectAttempt,
  unlockAfterProject,
} from './courseLearning/projects';
export {
  finishCourseQuiz,
  loadCourseQuiz,
  startCourseQuiz,
  submitCourseQuizAnswer,
} from './courseLearning/quizzes';
export type {
  QuizData,
  QuizQuestion,
  QuizResult,
} from './courseLearning/quizzes';
export type {
  ProjectSubmissionOutcome,
  ProjectSubmissionRetryOutcome,
} from './courseLearning/projects';
export {
  askCourseAssistant,
  courseIncludesAssistant,
  loadCourseAssistantHistory,
} from './courseLearning/assistant';
