import React, {useCallback, useState} from 'react';
import {StyleSheet, View} from 'react-native';
import {
  CourseLearningData,
  CourseFeedItem,
  VideoFitMode,
  VideoQuality,
} from './types';
import VideoComponent from './VideoComponent';
import FeedFooter from './FeedFooter';
import FeedHeader from './FeedHeader';
import FeedSideBar from './FeedSideBar';
import ProjectTransition from './ProjectTransition';
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
  shouldMountVideo: boolean;
  playbackSpeed: number;
  selectedQuality: VideoQuality;
  fitMode: VideoFitMode;
  saved: boolean;
  initialPosition: number;
  topInset: number;
  bottomInset: number;
  onPlaybackSpeedChange: (speed: number) => void;
  onQualityChange: (quality: VideoQuality) => void;
  onFitModeChange: (mode: VideoFitMode) => void;
  onToggleSave: (folder?: SavedFolderOption | null) => void;
  onBeforeOpenSave: () => boolean;
  onOpenChat: () => void;
  onSelectFeedItem: (key: string) => void;
  onProgress: (currentTime: number, duration: number) => void;
  onComplete: () => void;
  onRefreshVideo: () => void | Promise<void>;
  onPlaybackEvent: (event: PlaybackPlayerEvent) => void;
  onPlaybackMetrics: (metrics: PlaybackRuntimeMetrics) => void;
  onSubmitProject: (
    file: import('./types').SelectedProjectFile,
  ) => Promise<ProjectSubmissionOutcome>;
  onContinueAfterProject?: () => void;
}

const FeedRow = ({
  item,
  course,
  pageWidth,
  pageHeight,
  frameWidth,
  isVisible,
  shouldMountVideo,
  playbackSpeed,
  selectedQuality,
  fitMode,
  saved,
  initialPosition,
  topInset,
  bottomInset,
  onPlaybackSpeedChange,
  onQualityChange,
  onFitModeChange,
  onToggleSave,
  onBeforeOpenSave,
  onOpenChat,
  onSelectFeedItem,
  onProgress,
  onComplete,
  onRefreshVideo,
  onPlaybackEvent,
  onPlaybackMetrics,
  onSubmitProject,
  onContinueAfterProject,
}: FeedRowProps) => {
  const [currentTime, setCurrentTime] = useState(0);
  const handleProgress = useCallback(
    (time: number, duration: number) => {
      setCurrentTime(time);
      onProgress(time, duration);
    },
    [onProgress],
  );

  if (item.type === 'project') {
    const module = course.modules.find(entry => entry.id === item.moduleId);
    return (
      <View style={[styles.page, {width: pageWidth, height: pageHeight}]}>
        <ProjectTransition
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

  const availableQualities = item.reel.availableQualities?.length
    ? item.reel.availableQualities
    : (['auto'] as VideoQuality[]);
  const effectiveQuality = availableQualities.includes(selectedQuality)
    ? selectedQuality
    : 'auto';

  return (
    <View style={[styles.page, {width: pageWidth, height: pageHeight}]}>
      <View style={[styles.videoFrame, {width: frameWidth, height: pageHeight}]}>
        {shouldMountVideo ? (
          <VideoComponent
            data={item.reel}
            width={frameWidth}
            height={pageHeight}
            isVisible={isVisible}
            playbackSpeed={playbackSpeed}
            selectedQuality={effectiveQuality}
            fitMode={fitMode}
            initialPosition={initialPosition}
            bottomInset={bottomInset}
            onProgress={handleProgress}
            onComplete={onComplete}
            onRefreshSource={onRefreshVideo}
            onPlaybackEvent={onPlaybackEvent}
            onPlaybackMetrics={onPlaybackMetrics}
          />
        ) : (
          <View style={StyleSheet.absoluteFill} />
        )}
        {isVisible && (
          <>
            <FeedHeader
              playbackSpeed={playbackSpeed}
              onPlaybackSpeedChange={onPlaybackSpeedChange}
              selectedQuality={effectiveQuality}
              qualityOptions={availableQualities}
              onQualityChange={onQualityChange}
              fitMode={fitMode}
              onFitModeChange={onFitModeChange}
              topInset={topInset}
            />
            <FeedSideBar
              course={course}
              currentReel={item.reel}
              currentFeedKey={item.key}
              isSaved={saved}
              bottomInset={bottomInset}
              onToggleSave={onToggleSave}
              onBeforeOpenSave={onBeforeOpenSave}
              onOpenChat={onOpenChat}
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
});
