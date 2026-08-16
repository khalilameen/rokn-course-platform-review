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
  VideoFitMode,
  VideoQuality,
} from '../../components/VideoPlayer/types';
import type {
  PlaybackPlayerEvent,
  PlaybackRuntimeMetrics,
} from '../../components/VideoPlayer/playbackTelemetry';

export type ReelsNavigation = {
  goBack: () => void;
  navigate: (
    screen: 'Login',
    params: {
      returnTo: {name: 'Reels'; params: Record<string, unknown>};
    },
  ) => void;
  replace: (screen: 'CourseDetails', params: Record<string, unknown>) => void;
};

export const useReelsFeedRenderer = ({
  bottomInset,
  changeFitMode,
  changePlaybackSpeed,
  changeQuality,
  completeAndAdvance,
  course,
  currentIndex,
  feedLength,
  fitMode,
  frameWidth,
  handlePlaybackEvent,
  handlePlaybackMetrics,
  layout,
  load,
  navigation,
  persistProgress,
  playbackSpeed,
  positions,
  previewGateVisible,
  requestPlaybackManifest,
  savedLessons,
  scheduleDelayedAction,
  scrollToIndex,
  scrollToKey,
  selectedQuality,
  serverSession,
  setChatVisible,
  submitProject,
  toggleSaved,
  topInset,
}: {
  bottomInset: number;
  changeFitMode: (mode: VideoFitMode) => void;
  changePlaybackSpeed: (speed: number) => void;
  changeQuality: (quality: VideoQuality) => void;
  completeAndAdvance: (reel: CourseReel) => void | Promise<void>;
  course: CourseLearningData | null;
  currentIndex: number;
  feedLength: number;
  fitMode: VideoFitMode;
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
  positions: MutableRefObject<Record<string, number>>;
  previewGateVisible: boolean;
  requestPlaybackManifest: (
    reel: CourseReel,
    expectedSessionId?: string,
  ) => void;
  savedLessons: Set<string>;
  scheduleDelayedAction: (action: () => void, delayMs: number) => void;
  scrollToIndex: (index: number) => void;
  scrollToKey: (key: string) => void;
  selectedQuality: VideoQuality;
  serverSession: boolean | null;
  setChatVisible: Dispatch<SetStateAction<boolean>>;
  submitProject: (
    projectId: string,
    file: SelectedProjectFile,
  ) => Promise<ProjectSubmissionOutcome>;
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
          isVisible={index === currentIndex && !previewGateVisible}
          shouldMountVideo={
            index === currentIndex || index === currentIndex + 1
          }
          playbackSpeed={playbackSpeed}
          selectedQuality={selectedQuality}
          fitMode={fitMode}
          saved={reel ? savedLessons.has(reel.lessonId) : false}
          initialPosition={
            reel ? positions.current[`${course.id}:${reel.id}`] || 0 : 0
          }
          topInset={topInset}
          bottomInset={bottomInset}
          onPlaybackSpeedChange={changePlaybackSpeed}
          onQualityChange={changeQuality}
          onFitModeChange={changeFitMode}
          onToggleSave={folder => {
            if (reel) toggleSaved(reel, folder).catch(() => undefined);
          }}
          onBeforeOpenSave={() => {
            if (course.id.startsWith('demo') || serverSession === true) {
              return true;
            }
            navigation.navigate('Login', {
              returnTo: {
                name: 'Reels',
                params: {courseId: course.id, reelId: reel?.id},
              },
            });
            return false;
          }}
          onOpenChat={() => {
            if (course.id.startsWith('demo') || serverSession === true) {
              setChatVisible(true);
              return;
            }
            navigation.navigate('Login', {
              returnTo: {
                name: 'Reels',
                params: {courseId: course.id, reelId: reel?.id},
              },
            });
          }}
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
              requestPlaybackManifest(reel, reel.playbackSessionId);
              return;
            }
            return load();
          }}
          onSubmitProject={file =>
            item.type === 'project'
              ? submitProject(item.project.id, file)
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
        />
      );
    },
    [
      bottomInset,
      changeFitMode,
      changePlaybackSpeed,
      changeQuality,
      completeAndAdvance,
      course,
      currentIndex,
      feedLength,
      fitMode,
      frameWidth,
      handlePlaybackEvent,
      handlePlaybackMetrics,
      layout.height,
      layout.width,
      load,
      navigation,
      persistProgress,
      playbackSpeed,
      positions,
      previewGateVisible,
      requestPlaybackManifest,
      savedLessons,
      scheduleDelayedAction,
      scrollToIndex,
      scrollToKey,
      selectedQuality,
      serverSession,
      setChatVisible,
      submitProject,
      toggleSaved,
      topInset,
    ],
  );
