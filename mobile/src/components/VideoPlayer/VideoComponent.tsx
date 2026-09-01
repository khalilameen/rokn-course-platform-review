import React, {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useMemo,
  useRef,
  useState,
} from 'react';
import {Image, PanResponder, Platform, StyleSheet, View} from 'react-native';
import Video, {
  SelectedVideoTrackType,
  VideoRef,
  ViewType,
} from 'react-native-video';
import {CourseReel, VideoQuality} from './types';
import {probeVideoSource} from './videoSourcePolicy';
import {PlaybackPlayerEvent, PlaybackRuntimeMetrics} from './playbackTelemetry';
import {VideoChrome} from './video/VideoChrome';
import {createVideoEventHandlers} from './video/eventHandlers';
import {
  selectPlaybackRecoveryStep,
  selectVideoSource,
  selectVideoTimeline,
  VIDEO_BITRATE_BY_QUALITY,
  type PlaybackFailure,
} from './video/policy';
import {useAppActiveState} from '../../hooks/useAppActiveState';

export interface VideoComponentHandle {
  seekTo: (seconds: number) => void;
}

interface VideoComponentProps {
  data: CourseReel;
  width: number;
  height: number;
  isVisible: boolean;
  playbackBlocked?: boolean;
  playbackSpeed?: number;
  selectedQuality?: VideoQuality;
  initialPosition?: number;
  bottomInset?: number;
  onProgress?: (currentTime: number, duration: number) => void;
  onComplete?: () => void;
  onRefreshSource?: () => void | Promise<void>;
  onPlaybackEvent?: (event: PlaybackPlayerEvent) => void;
  onPlaybackMetrics?: (metrics: PlaybackRuntimeMetrics) => void;
}

const VideoComponent = forwardRef<VideoComponentHandle, VideoComponentProps>(
  (
    {
      data,
      width,
      height,
      isVisible,
      playbackBlocked = false,
      playbackSpeed = 1,
      selectedQuality = 'auto',
      initialPosition = 0,
      bottomInset = 0,
      onProgress,
      onComplete,
      onRefreshSource,
      onPlaybackEvent,
      onPlaybackMetrics,
    },
    forwardedRef,
  ) => {
    const videoRef = useRef<VideoRef>(null);
    const declaredDurationRef = useRef(
      Number.isFinite(Number(data.durationSeconds))
        ? Math.max(0, Number(data.durationSeconds))
        : 0,
    );
    const reelIdentityRef = useRef(data.id);
    const reelInitialPositionRef = useRef(initialPosition);
    const lastPositionRef = useRef(initialPosition);
    const durationRef = useRef(0);
    const hasRestoredRef = useRef(false);
    const retryPositionRef = useRef<number | null>(null);
    const pendingSeekRef = useRef<number | null>(null);
    const resumeAfterFocusLossRef = useRef(false);
    const preferredQualityRef = useRef(selectedQuality);
    const recoveryAttemptsRef = useRef(0);
    const sameSourceRetryUsedRef = useRef(false);
    const deferredPreloadFailureRef = useRef(false);
    const diagnosticRequestRef = useRef(0);
    const playbackLifecycleGenerationRef = useRef(0);
    const previousVisibleRef = useRef(isVisible);
    const previousManifestIdentityRef = useRef(
      `${data.playbackSessionId || 'local'}:${
        data.playbackManifestRevision || 0
      }`,
    );
    const isPlayingRef = useRef(false);
    const hasStartedRef = useRef(false);
    const loadStartedAtRef = useRef<number | null>(null);
    const bufferingStartedAtRef = useRef<number | null>(null);
    const bufferCountRef = useRef(0);
    const bufferDurationMsRef = useRef(0);
    const runtimeMetricsRef = useRef<PlaybackRuntimeMetrics>({
      recoveryCount: 0,
    });
    const [duration, setDuration] = useState(declaredDurationRef.current);
    const [bufferedTime, setBufferedTime] = useState(0);
    const [currentTime, setCurrentTime] = useState(initialPosition);
    const [previewTime, setPreviewTime] = useState<number | null>(null);
    const [pausedByUser, setPausedByUser] = useState(false);
    const [pausedForInterruption, setPausedForInterruption] = useState(false);
    const [isBuffering, setIsBuffering] = useState(true);
    const [isLoaded, setIsLoaded] = useState(false);
    const [error, setError] = useState(false);
    const [failureKind, setFailureKind] = useState<PlaybackFailure>('source');
    const [recoveryMessage, setRecoveryMessage] = useState('');
    const longBufferTimerRef = useRef<ReturnType<typeof setTimeout> | null>(
      null,
    );
    const recoveryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [retryKey, setRetryKey] = useState(0);
    const [usingFallback, setUsingFallback] = useState(false);
    const [effectiveQuality, setEffectiveQuality] =
      useState<VideoQuality>(selectedQuality);
    const [trackWidth, setTrackWidth] = useState(0);
    const appIsActive = useAppActiveState();

    if (reelIdentityRef.current !== data.id) {
      reelIdentityRef.current = data.id;
      playbackLifecycleGenerationRef.current += 1;
      reelInitialPositionRef.current = initialPosition;
      declaredDurationRef.current = Number.isFinite(
        Number(data.durationSeconds),
      )
        ? Math.max(0, Number(data.durationSeconds))
        : 0;
    }

    useImperativeHandle(
      forwardedRef,
      () => ({
        seekTo: seconds => {
          const target = Math.max(0, seconds);
          pendingSeekRef.current = target;
          lastPositionRef.current = target;
          videoRef.current?.seek(target);
        },
      }),
      [],
    );

    useEffect(() => {
      hasRestoredRef.current = false;
      retryPositionRef.current = null;
      pendingSeekRef.current = null;
      resumeAfterFocusLossRef.current = false;
      lastPositionRef.current = reelInitialPositionRef.current;
      setCurrentTime(reelInitialPositionRef.current);
      setDuration(declaredDurationRef.current);
      durationRef.current = declaredDurationRef.current;
      setBufferedTime(0);
      setError(false);
      setFailureKind('source');
      setRecoveryMessage('');
      setIsLoaded(false);
      setIsBuffering(true);
      setPausedByUser(false);
      setPausedForInterruption(false);
      if (recoveryTimerRef.current) {
        clearTimeout(recoveryTimerRef.current);
        recoveryTimerRef.current = null;
      }
      setUsingFallback(false);
      setEffectiveQuality(preferredQualityRef.current);
      recoveryAttemptsRef.current = 0;
      sameSourceRetryUsedRef.current = false;
      deferredPreloadFailureRef.current = false;
      diagnosticRequestRef.current += 1;
      previousVisibleRef.current = false;
      previousManifestIdentityRef.current = '';
      isPlayingRef.current = false;
      hasStartedRef.current = false;
      loadStartedAtRef.current = null;
      bufferingStartedAtRef.current = null;
      bufferCountRef.current = 0;
      bufferDurationMsRef.current = 0;
      runtimeMetricsRef.current = {recoveryCount: 0};
    }, [data.id]);

    useEffect(() => {
      const manifestIdentity = `${data.playbackSessionId || 'local'}:${
        data.playbackManifestRevision || 0
      }`;
      const previousIdentity = previousManifestIdentityRef.current;
      if (previousIdentity && previousIdentity !== manifestIdentity) {
        retryPositionRef.current = lastPositionRef.current;
        hasRestoredRef.current = false;
        isPlayingRef.current = false;
        setIsLoaded(false);
        setIsBuffering(true);
        setError(false);
      }
      previousManifestIdentityRef.current = manifestIdentity;
    }, [data.playbackManifestRevision, data.playbackSessionId]);

    useEffect(() => {
      preferredQualityRef.current = selectedQuality;
      recoveryAttemptsRef.current = 0;
      sameSourceRetryUsedRef.current = false;
      setUsingFallback(false);
      setEffectiveQuality(selectedQuality);
    }, [selectedQuality]);

    const playbackEligible = isVisible && !playbackBlocked && appIsActive;
    const playbackPaused = pausedByUser || pausedForInterruption;

    useEffect(() => {
      if (!playbackEligible) {
        setPreviewTime(null);
        if (longBufferTimerRef.current) {
          clearTimeout(longBufferTimerRef.current);
          longBufferTimerRef.current = null;
        }
        if (bufferingStartedAtRef.current !== null) {
          bufferDurationMsRef.current += Math.max(
            0,
            Date.now() - bufferingStartedAtRef.current,
          );
          bufferingStartedAtRef.current = null;
        }
      } else if (deferredPreloadFailureRef.current) {
        deferredPreloadFailureRef.current = false;
        hasRestoredRef.current = false;
        retryPositionRef.current = lastPositionRef.current;
        setError(false);
        setIsBuffering(true);
        setRetryKey(value => value + 1);
      }
    }, [playbackEligible]);

    useEffect(() => {
      if (!appIsActive) {
        setPreviewTime(null);
        if (longBufferTimerRef.current) {
          clearTimeout(longBufferTimerRef.current);
          longBufferTimerRef.current = null;
        }
      }
    }, [appIsActive]);

    useEffect(
      () => () => {
        if (longBufferTimerRef.current) {
          clearTimeout(longBufferTimerRef.current);
        }
        if (recoveryTimerRef.current) {
          clearTimeout(recoveryTimerRef.current);
        }
        diagnosticRequestRef.current += 1;
        playbackLifecycleGenerationRef.current += 1;
      },
      [],
    );

    const {
      adaptiveSource,
      hasSupportedFallback,
      isFallbackSource,
      selectedVariantUri,
      source,
      sourceType,
      unsupportedSource,
    } = useMemo(
      () =>
        selectVideoSource({
          effectiveQuality,
          fallbackVideoUrl: data.fallbackVideoUrl,
          qualitySources: data.qualitySources,
          usingFallback,
          videoUrl: data.videoUrl,
        }),
      [
        data.fallbackVideoUrl,
        data.qualitySources,
        data.videoUrl,
        effectiveQuality,
        usingFallback,
      ],
    );
    const sourceFailed = unsupportedSource || error;
    const selectedVideoTrack = useMemo(
      () =>
        !isVisible || effectiveQuality === 'auto'
          ? {type: SelectedVideoTrackType.AUTO}
          : {
              type: SelectedVideoTrackType.RESOLUTION,
              value: Number(effectiveQuality.replace('p', '')),
            },
      [effectiveQuality, isVisible],
    );

    const publishRuntimeMetrics = useCallback(
      (updates: Partial<PlaybackRuntimeMetrics>) => {
        const next: PlaybackRuntimeMetrics = {
          ...runtimeMetricsRef.current,
          ...updates,
          recoveryCount: recoveryAttemptsRef.current,
        };
        const previous = runtimeMetricsRef.current;
        runtimeMetricsRef.current = next;
        if (
          previous.effectiveQuality !== next.effectiveQuality ||
          previous.effectiveBitrateKbps !== next.effectiveBitrateKbps ||
          previous.recoveryCount !== next.recoveryCount ||
          previous.bufferCount !== next.bufferCount ||
          previous.bufferDurationMs !== next.bufferDurationMs ||
          previous.startupLatencyMs !== next.startupLatencyMs ||
          previous.diagnostics?.stage !== next.diagnostics?.stage ||
          previous.diagnostics?.source_type !== next.diagnostics?.source_type
        ) {
          onPlaybackMetrics?.(next);
        }
      },
      [onPlaybackMetrics],
    );

    const emitPlaybackEvent = useCallback(
      (
        eventType: PlaybackPlayerEvent['eventType'],
        options: Pick<
          PlaybackPlayerEvent,
          'endReason' | 'errorCode' | 'diagnostics'
        > = {},
      ) => {
        onPlaybackEvent?.({
          eventType,
          positionSeconds: Math.max(0, lastPositionRef.current),
          ...(durationRef.current > 0
            ? {durationSeconds: durationRef.current}
            : {}),
          ...runtimeMetricsRef.current,
          recoveryCount: recoveryAttemptsRef.current,
          ...options,
        });
      },
      [onPlaybackEvent],
    );

    const handleAudioBecomingNoisy = useCallback(() => {
      if (!isVisible) return;
      resumeAfterFocusLossRef.current = false;
      setPausedForInterruption(false);
      setPausedByUser(true);
      if (isPlayingRef.current) {
        isPlayingRef.current = false;
        emitPlaybackEvent('pause');
      }
    }, [emitPlaybackEvent, isVisible]);

    const handleAudioFocusChanged = useCallback(
      ({hasAudioFocus}: {hasAudioFocus: boolean}) => {
        if (!isVisible) return;
        if (!hasAudioFocus) {
          if (!playbackEligible || pausedByUser) return;
          resumeAfterFocusLossRef.current = true;
          setPausedForInterruption(true);
          if (isPlayingRef.current) {
            isPlayingRef.current = false;
            emitPlaybackEvent('pause');
          }
          return;
        }
        if (resumeAfterFocusLossRef.current) {
          resumeAfterFocusLossRef.current = false;
          setPausedForInterruption(false);
        }
      },
      [emitPlaybackEvent, isVisible, pausedByUser, playbackEligible],
    );

    useEffect(() => {
      const wasVisible = previousVisibleRef.current;
      previousVisibleRef.current = isVisible;
      if (wasVisible && !isVisible) {
        isPlayingRef.current = false;
        emitPlaybackEvent('stop', {endReason: 'lesson_changed'});
      }
    }, [emitPlaybackEvent, isVisible]);

    useEffect(() => {
      if (selectedVariantUri && effectiveQuality !== 'auto') {
        publishRuntimeMetrics({effectiveQuality});
      }
    }, [effectiveQuality, publishRuntimeMetrics, selectedVariantUri]);

    const restartPlayback = useCallback((message: string, delayMs = 650) => {
      const lifecycleGeneration = playbackLifecycleGenerationRef.current;
      retryPositionRef.current = lastPositionRef.current;
      hasRestoredRef.current = false;
      setError(false);
      setFailureKind('source');
      setRecoveryMessage(message);
      setIsLoaded(false);
      setIsBuffering(true);
      if (recoveryTimerRef.current) {
        clearTimeout(recoveryTimerRef.current);
      }
      recoveryTimerRef.current = setTimeout(() => {
        if (
          lifecycleGeneration !== playbackLifecycleGenerationRef.current
        ) {
          return;
        }
        recoveryTimerRef.current = null;
        setRetryKey(value => value + 1);
      }, delayMs);
    }, []);

    const finishWithDiagnostic = useCallback(
      (initialFailure: PlaybackFailure) => {
        setIsBuffering(false);
        setRecoveryMessage('');
        setFailureKind(initialFailure);
        setError(true);
        const request = diagnosticRequestRef.current + 1;
        diagnosticRequestRef.current = request;
        if (initialFailure === 'unsupported') return;
        void probeVideoSource(source.uri).then(result => {
          if (diagnosticRequestRef.current !== request) return;
          setFailureKind(result === 'reachable' ? initialFailure : result);
        });
      },
      [source.uri],
    );

    const recoverOrFail = useCallback(
      (reason: 'source' | 'timeout') => {
        const step = selectPlaybackRecoveryStep({
          adaptiveSource,
          availableQualities: data.availableQualities,
          effectiveQuality,
          hasSelectedVariant: Boolean(selectedVariantUri),
          hasSupportedFallback,
          isFallbackSource,
          isVisible,
          recoveryAttempts: recoveryAttemptsRef.current,
          recoveryPending: Boolean(recoveryTimerRef.current),
          sameSourceRetryUsed: sameSourceRetryUsedRef.current,
        });
        if (step.kind === 'pending') return true;
        if (step.kind === 'defer') {
          deferredPreloadFailureRef.current = true;
          setIsBuffering(false);
          return true;
        }
        if (step.kind === 'quality') {
          recoveryAttemptsRef.current += 1;
          publishRuntimeMetrics({});
          setEffectiveQuality(step.quality);
          restartPlayback('الاتصال بطيء\nنضبط الجودة');
          return true;
        }
        if (step.kind === 'fallback') {
          recoveryAttemptsRef.current += 1;
          publishRuntimeMetrics({});
          setUsingFallback(true);
          restartPlayback('جارٍ استعادة المقطع\nمكانك محفوظ');
          return true;
        }
        if (step.kind === 'retry') {
          sameSourceRetryUsedRef.current = true;
          recoveryAttemptsRef.current += 1;
          publishRuntimeMetrics({});
          setRecoveryMessage('نحاول الوصول إلى الفيديو');
          const lifecycleGeneration =
            playbackLifecycleGenerationRef.current;
          const reelId = data.id;
          // A network switch or expired signature can make the old source
          // fail immediately. Wait for the manifest refresh attempt before
          // rebuilding the native player instead of replaying that stale URL.
          void Promise.resolve(onRefreshSource?.())
            .catch(() => undefined)
            .then(() => {
              if (
                lifecycleGeneration !==
                  playbackLifecycleGenerationRef.current ||
                reelIdentityRef.current !== reelId
              ) {
                return;
              }
              restartPlayback('نحاول الوصول إلى الفيديو', 120);
            });
          return true;
        }

        finishWithDiagnostic(reason);
        return false;
      },
      [
        adaptiveSource,
        data.availableQualities,
        data.id,
        effectiveQuality,
        selectedVariantUri,
        hasSupportedFallback,
        finishWithDiagnostic,
        isFallbackSource,
        isVisible,
        onRefreshSource,
        restartPlayback,
        publishRuntimeMetrics,
      ],
    );

    const seekFromX = useCallback(
      (x: number, commit: boolean) => {
        if (!trackWidth || !duration) {
          return;
        }
        const ratio = Math.max(0, Math.min(1, x / trackWidth));
        const seconds = ratio * duration;
        setPreviewTime(seconds);
        if (commit) {
          pendingSeekRef.current = seconds;
          videoRef.current?.seek(seconds);
          lastPositionRef.current = seconds;
          setCurrentTime(seconds);
          setPreviewTime(null);
        }
      },
      [duration, trackWidth],
    );

    const panResponder = useMemo(
      () =>
        PanResponder.create({
          onStartShouldSetPanResponder: () => true,
          onMoveShouldSetPanResponder: () => true,
          onPanResponderGrant: event =>
            seekFromX(event.nativeEvent.locationX, false),
          onPanResponderMove: event =>
            seekFromX(event.nativeEvent.locationX, false),
          onPanResponderRelease: event =>
            seekFromX(event.nativeEvent.locationX, true),
          onPanResponderTerminate: () => setPreviewTime(null),
        }),
      [seekFromX],
    );

    const timeline = selectVideoTimeline({
      bufferedTime,
      currentTime,
      duration,
      previewTime,
    });

    const seekBy = (offsetSeconds: number) => {
      if (!duration) {
        return;
      }
      const seconds = Math.max(
        0,
        Math.min(duration, timeline.displayedTime + offsetSeconds),
      );
      videoRef.current?.seek(seconds);
      pendingSeekRef.current = seconds;
      lastPositionRef.current = seconds;
      setCurrentTime(seconds);
      setPreviewTime(null);
    };

    const togglePaused = () => {
      resumeAfterFocusLossRef.current = false;
      if (!playbackPaused) {
        isPlayingRef.current = false;
        emitPlaybackEvent('pause');
      }
      setPausedForInterruption(false);
      setPausedByUser(!playbackPaused);
    };

    const retryPlayback = () => {
      const lifecycleGeneration = playbackLifecycleGenerationRef.current;
      const reelId = data.id;
      retryPositionRef.current = lastPositionRef.current;
      hasRestoredRef.current = false;
      recoveryAttemptsRef.current = 0;
      sameSourceRetryUsedRef.current = false;
      diagnosticRequestRef.current += 1;
      setError(false);
      setFailureKind('source');
      setRecoveryMessage('نحاول الوصول إلى الفيديو');
      setIsBuffering(true);
      setUsingFallback(false);
      setEffectiveQuality(selectedQuality);
      void Promise.resolve(onRefreshSource?.())
        .catch(() => undefined)
        .then(() => {
          if (
            lifecycleGeneration !== playbackLifecycleGenerationRef.current ||
            reelIdentityRef.current !== reelId
          ) {
            return;
          }
          setRetryKey(value => value + 1);
        });
    };

    const videoEventHandlers = createVideoEventHandlers({
      bufferCount: bufferCountRef,
      bufferDurationMs: bufferDurationMsRef,
      bufferingStartedAt: bufferingStartedAtRef,
      data,
      diagnosticRequest: diagnosticRequestRef,
      duration,
      durationRef,
      emitPlaybackEvent,
      hasRestored: hasRestoredRef,
      hasStarted: hasStartedRef,
      isFallbackSource,
      isPlaying: isPlayingRef,
      isVisible,
      lastPosition: lastPositionRef,
      loadStartedAt: loadStartedAtRef,
      longBufferTimer: longBufferTimerRef,
      onComplete,
      onProgressChange: onProgress,
      pendingSeek: pendingSeekRef,
      publishRuntimeMetrics,
      recoverOrFail,
      recoveryAttempts: recoveryAttemptsRef,
      reelInitialPosition: reelInitialPositionRef,
      retryPosition: retryPositionRef,
      setBufferedTime,
      setCurrentTime,
      setDuration,
      setError,
      setIsBuffering,
      setIsLoaded,
      setRecoveryMessage,
      sourceType,
      sourceUri: source.uri,
      videoRef,
    });

    return (
      <View style={[styles.container, {width, height}]}>
        {!isLoaded && !!data.thumbnailUrl && (
          <Image
            accessibilityElementsHidden
            accessibilityIgnoresInvertColors
            importantForAccessibility="no"
            blurRadius={3}
            source={{uri: data.thumbnailUrl}}
            style={StyleSheet.absoluteFill}
          />
        )}
        {!unsupportedSource && (
          <Video
            key={`${data.id}-${data.playbackSessionId || 'local'}-${
              data.playbackManifestRevision || 0
            }-${retryKey}`}
            ref={videoRef}
            source={source}
            resizeMode="cover"
            viewType={Platform.OS === 'android' ? ViewType.TEXTURE : undefined}
            shutterColor="#030507"
            paused={!playbackEligible || playbackPaused}
            muted={!playbackEligible}
            repeat={false}
            rate={playbackSpeed}
            selectedVideoTrack={selectedVideoTrack}
            controls={false}
            playInBackground={false}
            playWhenInactive={false}
            progressUpdateInterval={playbackEligible ? 1000 : 2500}
            reportBandwidth={playbackEligible}
            ignoreSilentSwitch="ignore"
            mixWithOthers="inherit"
            disableFocus={!playbackEligible || pausedByUser}
            automaticallyWaitsToMinimizeStalling
            preferredForwardBufferDuration={playbackEligible ? 6 : 1}
            preventsDisplaySleepDuringVideoPlayback={
              playbackEligible && !playbackPaused
            }
            onAudioBecomingNoisy={handleAudioBecomingNoisy}
            onAudioFocusChanged={handleAudioFocusChanged}
            style={StyleSheet.absoluteFill}
            bufferConfig={
              playbackEligible
                ? {
                    minBufferMs: 4000,
                    maxBufferMs: 18000,
                    bufferForPlaybackMs: 1200,
                    bufferForPlaybackAfterRebufferMs: 2500,
                  }
                : {
                    minBufferMs: 900,
                    maxBufferMs: 2600,
                    bufferForPlaybackMs: 600,
                    bufferForPlaybackAfterRebufferMs: 900,
                  }
            }
            maxBitRate={
              playbackEligible
                ? VIDEO_BITRATE_BY_QUALITY[effectiveQuality]
                : 750_000
            }
            {...videoEventHandlers}
          />
        )}

        <VideoChrome
          bottomInset={bottomInset}
          currentTime={currentTime}
          failureKind={failureKind}
          isBuffering={isBuffering}
          isLoaded={isLoaded}
          onRetry={retryPlayback}
          onSeekBy={seekBy}
          onTogglePaused={togglePaused}
          onTrackWidth={setTrackWidth}
          panHandlers={panResponder.panHandlers}
          pausedByUser={playbackPaused}
          previewTime={previewTime}
          recoveryMessage={recoveryMessage}
          sourceFailed={sourceFailed}
          timeline={timeline}
          trackWidth={trackWidth}
          unsupportedSource={unsupportedSource}
        />
      </View>
    );
  },
);

VideoComponent.displayName = 'VideoComponent';
export default VideoComponent;

const styles = StyleSheet.create({
  container: {
    backgroundColor: '#030507',
    overflow: 'hidden',
  },
});
