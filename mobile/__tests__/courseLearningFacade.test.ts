jest.mock('react-native-fs', () => ({
  CachesDirectoryPath: '/cache',
  copyFile: jest.fn(),
  exists: jest.fn(),
  mkdir: jest.fn(),
  readDir: jest.fn(),
  stat: jest.fn(),
  unlink: jest.fn(),
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    delete: jest.fn(),
    get: jest.fn(),
    post: jest.fn(),
  },
}));

jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(),
}));

jest.mock('../src/config/runtime', () => ({
  LOCAL_DEMO_ENABLED: false,
}));

import * as facade from '../src/components/VideoPlayer/courseLearningApi';
import * as assistant from '../src/components/VideoPlayer/courseLearning/assistant';
import * as mapping from '../src/components/VideoPlayer/courseLearning/mapping';
import * as persistence from '../src/components/VideoPlayer/courseLearning/persistence';
import * as playback from '../src/components/VideoPlayer/courseLearning/playback';
import * as projects from '../src/components/VideoPlayer/courseLearning/projects';
import * as savedCollections from '../src/components/VideoPlayer/courseLearning/savedCollections';

describe('course learning facade', () => {
  it('keeps the established runtime exports without exposing internals', () => {
    const expected = {
      WATCH_HISTORY_ENABLED_KEY: persistence.WATCH_HISTORY_ENABLED_KEY,
      applyLocalLearningState: persistence.applyLocalLearningState,
      askCourseAssistant: assistant.askCourseAssistant,
      clearCurrentAccountLearningFiles:
        projects.clearCurrentAccountLearningFiles,
      clearLocalWatchHistory: persistence.clearLocalWatchHistory,
      courseIncludesAssistant: assistant.courseIncludesAssistant,
      createSavedFolderOption: savedCollections.createSavedFolderOption,
      deleteSavedFolderOption: savedCollections.deleteSavedFolderOption,
      flushPendingPlaybackPositions: playback.flushPendingPlaybackPositions,
      getLocalLearningState: persistence.getLocalLearningState,
      getSavedFolderOptions: savedCollections.getSavedFolderOptions,
      isWatchHistoryEnabled: persistence.isWatchHistoryEnabled,
      loadCourseLearningData: mapping.loadCourseLearningData,
      mapCoursePayload: mapping.mapCoursePayload,
      markSectionComplete: playback.markSectionComplete,
      migrateGuestLearningState: persistence.migrateGuestLearningState,
      openPlaybackSession: playback.openPlaybackSession,
      removeLessonFromSavedFolder: savedCollections.removeLessonFromSavedFolder,
      reportPlaybackSessionEvent: playback.reportPlaybackSessionEvent,
      retryPendingPlaybackPositions: playback.retryPendingPlaybackPositions,
      retryPendingProjectSubmissions: projects.retryPendingProjectSubmissions,
      retryPendingSectionCompletions: playback.retryPendingSectionCompletions,
      saveLessonToFolder: savedCollections.saveLessonToFolder,
      savePlaybackPosition: playback.savePlaybackPosition,
      submitProjectAttempt: projects.submitProjectAttempt,
      toggleWatchLater: savedCollections.toggleWatchLater,
      unlockAfterProject: projects.unlockAfterProject,
    };

    expect(facade).toMatchObject(expected);
    expect(Object.keys(facade).sort()).toEqual(Object.keys(expected).sort());
  });
});
