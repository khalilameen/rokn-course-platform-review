import React, {useCallback, useEffect, useRef, useState} from 'react';
import {ActivityIndicator, Image, StyleSheet, View} from 'react-native';
import {CourseLearningData, CourseFeedItem, VideoQuality} from './types';
import VideoComponent from './VideoComponent';
import FeedFooter from './FeedFooter';
import FeedHeader from './FeedHeader';
import FeedSideBar from './FeedSideBar';
import ProjectTransition from './ProjectTransition';
import QuizTransition from './QuizTransition';
import type {
  ProjectSubmissionOutcome,
  SavedFolderOption,
} from './courseLearningApi';
import type {
  PlaybackPlayerEvent,
  PlaybackRuntimeMetrics,
} from './playbackTelemetry';

interface FeedRowProps {
  item: CourseFeedItem;
  course: CourseLearningData;
  pageWidth: number;
  pageHeight: number;
  frameWidth: number;
  isVisible: boolean;
  playbackBlocked: boolean;
  shouldMountVideo: boolean;
  playbackSpeed: number;
  selectedQuality: VideoQuality;
  saved: boolean;
  savePending: boolean;
  initialPosition: number;
  topInset: number;
  bottomInset: number;
  onPlaybackSpeedChange: (speed: number) => void;
  onQualityChange: (quality: VideoQuality) => void;
  onToggleSave: (folder?: SavedFolderOption | null) => void;
  onBeforeOpenSave: () => boolean;
  onOpenChat: () => void;
  onOverlayVisibilityChange?: (scopeKey: string, visible: boolean) => void;
  onSelectFeedItem: (key: string) => void;
  onProgress: (currentTime: number, duration: number) => void;
  onComplete: () => void;
  onRefreshVideo: () => void | Promise<void>;
  onPlaybackEvent: (event: PlaybackPlayerEvent) => void;
  onPlaybackMetrics: (metrics: PlaybackRuntimeMetrics) => void;
  onSubmitProject: (
    files: import('./types').SelectedProjectFile[],
    note?: string,
  ) => Promise<ProjectSubmissionOutcome>;
  onContinueAfterProject?: () => void;
  onQuizPassed: () => Promise<void> | void;
  onContinueAfterQuiz?: () => void;
}

const FeedRow = ({
  item,
  course,
  pageWidth,
  pageHeight,
  frameWidth,
  isVisible,
  playbackBlocked,
  shouldMountVideo,
  playbackSpeed,
  selectedQuality,
  saved,
  savePending,
  initialPosition,
  topInset,
  bottomInset,
  onPlaybackSpeedChange,
  onQualityChange,
  onToggleSave,
  onBeforeOpenSave,
  onOpenChat,
  onOverlayVisibilityChange,
  onSelectFeedItem,
  onProgress,
  onComplete,
  onRefreshVideo,
  onPlaybackEvent,
  onPlaybackMetrics,
  onSubmitProject,
  onContinueAfterProject,
  onQuizPassed,
  onContinueAfterQuiz,
}: FeedRowProps) => {
  const [currentTime, setCurrentTime] = useState(0);
  const attachmentClockRef = useRef(0);
  const [headerOverlayVisible, setHeaderOverlayVisible] = useState(false);
  const [sidebarOverlayVisible, setSidebarOverlayVisible] = useState(false);
  const localOverlayVisible = headerOverlayVisible || sidebarOverlayVisible;

  useEffect(() => {
    onOverlayVisibilityChange?.(item.key, isVisible && localOverlayVisible);
    return () => onOverlayVisibilityChange?.(item.key, false);
  }, [isVisible, item.key, localOverlayVisible, onOverlayVisibilityChange]);
  const attachmentPromptAt = course.attachmentPrompt?.enabled
    ? Math.max(0, Number(course.attachmentPrompt.atSeconds || 0))
    : null;
  const handleProgress = useCallback(
    (time: number, duration: number) => {
      // VideoComponent owns the frame-by-frame playback clock. FeedRow only
      // needs a coarse clock until the one-time attachment prompt threshold;
      // mirroring every progress event here rerendered the full side rail and
      // bottom sheets throughout every reel on low-end phones.
      if (
        attachmentPromptAt !== null &&
        attachmentClockRef.current <= attachmentPromptAt
      ) {
        const next = Math.min(attachmentPromptAt, Math.max(0, Math.floor(time)));
        if (next !== attachmentClockRef.current) {
          attachmentClockRef.current = next;
          setCurrentTime(next);
        }
      }
      onProgress(time, duration);
    },
    [attachmentPromptAt, onProgress],
  );

  if (item.type === 'project') {
    const module = course.modules.find(entry => entry.id === item.moduleId);
    return (
      <View style={[styles.page, {width: pageWidth, height: pageHeight}]}>
        <ProjectTransition
          active={isVisible}
          project={item.project}
          moduleTitle={module?.title || ''}
          width={pageWidth}
          height={pageHeight}
          topInset={topInset}
          bottomInset={bottomInset}
          onSubmit={onSubmitProject}
          onContinue={onContinueAfterProject}
        />
      </View>
    );
  }

  if (item.type === 'quiz') {
    const module = course.modules.find(entry => entry.id === item.moduleId);
    return (
      <View style={[styles.page, {width: pageWidth, height: pageHeight}]}>
        <QuizTransition
          courseId={course.id}
          quiz={item.quiz}
          moduleTitle={module?.title || ''}
          width={pageWidth}
          height={pageHeight}
          topInset={topInset}
          bottomInset={bottomInset}
          onPassed={async () => {
            await onQuizPassed();
            onContinueAfterQuiz?.();
          }}
        />
      </View>
    );
  }

  const availableQualities = item.reel.availableQualities?.length
    ? item.reel.availableQualities
    : (['auto'] as VideoQuality[]);
  const effectiveQuality = availableQualities.includes(selectedQuality)
    ? selectedQuality
    : 'auto';

  return (
    <View style={[styles.page, {width: pageWidth, height: pageHeight}]}>
      <View
        style={[styles.videoFrame, {width: frameWidth, height: pageHeight}]}>
        {shouldMountVideo ? (
          <VideoComponent
            data={item.reel}
            width={frameWidth}
            height={pageHeight}
            isVisible={isVisible}
            playbackBlocked={playbackBlocked || localOverlayVisible}
            playbackSpeed={playbackSpeed}
            selectedQuality={effectiveQuality}
            initialPosition={initialPosition}
            bottomInset={bottomInset}
            onProgress={handleProgress}
            onComplete={onComplete}
            onRefreshSource={onRefreshVideo}
            onPlaybackEvent={onPlaybackEvent}
            onPlaybackMetrics={onPlaybackMetrics}
          />
        ) : (
          <View style={[StyleSheet.absoluteFill, styles.sourcePending]}>
            {!!item.reel.thumbnailUrl && (
              <Image
                accessibilityElementsHidden
                accessibilityIgnoresInvertColors
                importantForAccessibility="no"
                blurRadius={3}
                source={{uri: item.reel.thumbnailUrl}}
                style={StyleSheet.absoluteFill}
              />
            )}
            {isVisible && !item.reel.isLocked && (
              <ActivityIndicator color="#FFFFFF" size="small" />
            )}
          </View>
        )}
        {isVisible && (
          <>
            <FeedHeader
              playbackSpeed={playbackSpeed}
              onPlaybackSpeedChange={onPlaybackSpeedChange}
              selectedQuality={effectiveQuality}
              qualityOptions={availableQualities}
              onQualityChange={onQualityChange}
              onOpenChange={setHeaderOverlayVisible}
              topInset={topInset}
            />
            <FeedSideBar
              course={course}
              currentReel={item.reel}
              currentFeedKey={item.key}
              isSaved={saved}
              savePending={savePending}
              bottomInset={bottomInset}
              onToggleSave={onToggleSave}
              onBeforeOpenSave={onBeforeOpenSave}
              onOpenChat={onOpenChat}
              onOverlayVisibilityChange={setSidebarOverlayVisible}
              onSelectFeedItem={onSelectFeedItem}
              currentTime={currentTime}
            />
            <FeedFooter data={item.reel} bottomInset={bottomInset} />
          </>
        )}
      </View>
    </View>
  );
};

export default React.memo(FeedRow);

const styles = StyleSheet.create({
  page: {
    backgroundColor: '#000000',
    alignItems: 'center',
    justifyContent: 'center',
  },
  videoFrame: {
    position: 'relative',
    overflow: 'hidden',
    backgroundColor: '#030507',
  },
  sourcePending: {
    alignItems: 'center',
    justifyContent: 'center',
  },
});
