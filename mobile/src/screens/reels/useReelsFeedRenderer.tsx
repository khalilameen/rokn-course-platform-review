import {useCallback} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import FeedRow from '../../components/VideoPlayer/FeedRow';
import type {
  ProjectSubmissionOutcome,
  SavedFolderOption,
} from '../../components/VideoPlayer/courseLearningApi';
import type {
  CourseFeedItem,
  CourseLearningData,
  CourseReel,
  SelectedProjectFile,
  VideoQuality,
} from '../../components/VideoPlayer/types';
import type {
  PlaybackPlayerEvent,
  PlaybackRuntimeMetrics,
} from '../../components/VideoPlayer/playbackTelemetry';
import type {RootNavigation} from '../../navigation/types';
import {isLocalDemoId} from '../../config/runtime';

export type ReelsNavigation = RootNavigation;

export const useReelsFeedRenderer = ({
  bottomInset,
  changePlaybackSpeed,
  changeQuality,
  completeAndAdvance,
  course,
  currentIndex,
  feedLength,
  frameWidth,
  handlePlaybackEvent,
  handlePlaybackMetrics,
  layout,
  load,
  navigation,
  persistProgress,
  playbackSpeed,
  playbackBlocked,
  preloadNext,
  positions,
  preview,
  previewCount,
  previewGateVisible,
  requestPlaybackManifest,
  screenFocused,
  savedLessons,
  savingLessons,
  scheduleDelayedAction,
  scrollToIndex,
  scrollToKey,
  selectedQuality,
  serverSession,
  setChatVisible,
  onContentOverlayVisibilityChange,
  submitProject,
  passQuiz,
  toggleSaved,
  topInset,
}: {
  bottomInset: number;
  changePlaybackSpeed: (speed: number) => void;
  changeQuality: (quality: VideoQuality) => void;
  completeAndAdvance: (reel: CourseReel) => void | Promise<void>;
  course: CourseLearningData | null;
  currentIndex: number;
  feedLength: number;
  frameWidth: number;
  handlePlaybackEvent: (reel: CourseReel, event: PlaybackPlayerEvent) => void;
  handlePlaybackMetrics: (
    reel: CourseReel,
    metrics: PlaybackRuntimeMetrics,
  ) => void;
  layout: {width: number; height: number};
  load: () => Promise<void>;
  navigation: ReelsNavigation;
  persistProgress: (
    reel: CourseReel,
    currentTime: number,
    duration: number,
  ) => void;
  playbackSpeed: number;
  playbackBlocked: boolean;
  preloadNext: boolean;
  positions: MutableRefObject<Record<string, number>>;
  preview: boolean;
  previewCount?: number;
  previewGateVisible: boolean;
  requestPlaybackManifest: (
    reel: CourseReel,
    expectedSessionId?: string,
  ) => void | Promise<void>;
  screenFocused: boolean;
  savedLessons: Set<string>;
  savingLessons: Set<string>;
  scheduleDelayedAction: (action: () => void, delayMs: number) => void;
  scrollToIndex: (index: number) => void;
  scrollToKey: (key: string) => void;
  selectedQuality: VideoQuality;
  serverSession: boolean | null;
  setChatVisible: Dispatch<SetStateAction<boolean>>;
  onContentOverlayVisibilityChange: (
    scopeKey: string,
    visible: boolean,
  ) => void;
  submitProject: (
    projectId: string,
    files: SelectedProjectFile[],
    note?: string,
  ) => Promise<ProjectSubmissionOutcome>;
  passQuiz: (quizId: string) => Promise<void> | void;
  toggleSaved: (
    reel: CourseReel,
    folder?: SavedFolderOption | null,
  ) => Promise<unknown>;
  topInset: number;
}) =>
  useCallback(
    ({item, index}: {item: CourseFeedItem; index: number}) => {
      if (!course || !layout.height || !frameWidth) return null;
      const reel = item.type === 'reel' ? item.reel : undefined;
      return (
        <FeedRow
          item={item}
          course={course}
          pageWidth={layout.width}
          pageHeight={layout.height}
          frameWidth={frameWidth}
          isVisible={index === currentIndex && screenFocused}
          playbackBlocked={playbackBlocked || previewGateVisible}
          shouldMountVideo={
            Boolean(reel?.videoUrl.trim()) &&
            (index === currentIndex ||
              (preloadNext && index === currentIndex + 1))
          }
          playbackSpeed={playbackSpeed}
          selectedQuality={selectedQuality}
          saved={reel ? savedLessons.has(reel.lessonId) : false}
          savePending={reel ? savingLessons.has(reel.lessonId) : false}
          initialPosition={
            reel ? positions.current[`${course.id}:${reel.id}`] || 0 : 0
          }
          topInset={topInset}
          bottomInset={bottomInset}
          onPlaybackSpeedChange={changePlaybackSpeed}
          onQualityChange={changeQuality}
          onToggleSave={folder => {
            if (reel) toggleSaved(reel, folder).catch(() => undefined);
          }}
          onBeforeOpenSave={() => {
            if (isLocalDemoId(course.id) || serverSession === true) {
              return true;
            }
            navigation.navigate('Login', {
              returnTo: {
                name: 'Reels',
                params: {
                  courseId: course.id,
                  reelId: reel?.id,
                  preview,
                  previewCount,
                },
              },
            });
            return false;
          }}
          onOpenChat={() => {
            if (isLocalDemoId(course.id) || serverSession === true) {
              setChatVisible(true);
              return;
            }
            navigation.navigate('Login', {
              returnTo: {
                name: 'Reels',
                params: {
                  courseId: course.id,
                  reelId: reel?.id,
                  preview,
                  previewCount,
                },
              },
            });
          }}
          onOverlayVisibilityChange={onContentOverlayVisibilityChange}
          onSelectFeedItem={scrollToKey}
          onProgress={(time, duration) =>
            reel && persistProgress(reel, time, duration)
          }
          onPlaybackEvent={event => reel && handlePlaybackEvent(reel, event)}
          onPlaybackMetrics={metrics =>
            reel && handlePlaybackMetrics(reel, metrics)
          }
          onComplete={() => reel && completeAndAdvance(reel)}
          onRefreshVideo={() => {
            if (reel && serverSession) {
              return requestPlaybackManifest(reel, reel.playbackSessionId);
            }
            return load();
          }}
          onSubmitProject={(file, note) =>
            item.type === 'project'
              ? submitProject(item.project.id, file, note)
              : Promise.resolve({
                  passed: false,
                  synced: false,
                  provisional: false,
                  canContinue: false,
                })
          }
          onContinueAfterProject={
            index < feedLength - 1
              ? () => scheduleDelayedAction(() => scrollToIndex(index + 1), 80)
              : undefined
          }
          onQuizPassed={() =>
            item.type === 'quiz' ? passQuiz(item.quiz.id) : undefined
          }
          onContinueAfterQuiz={
            index < feedLength - 1
              ? () => scheduleDelayedAction(() => scrollToIndex(index + 1), 80)
              : undefined
          }
        />
      );
    },
    [
      bottomInset,
      changePlaybackSpeed,
      changeQuality,
      completeAndAdvance,
      course,
      currentIndex,
      feedLength,
      frameWidth,
      handlePlaybackEvent,
      handlePlaybackMetrics,
      layout.height,
      layout.width,
      load,
      navigation,
      persistProgress,
      playbackSpeed,
      playbackBlocked,
      preloadNext,
      positions,
      preview,
      previewCount,
      previewGateVisible,
      requestPlaybackManifest,
      screenFocused,
      savedLessons,
      savingLessons,
      scheduleDelayedAction,
      scrollToIndex,
      scrollToKey,
      selectedQuality,
      serverSession,
      setChatVisible,
      onContentOverlayVisibilityChange,
      submitProject,
      passQuiz,
      toggleSaved,
      topInset,
    ],
  );
