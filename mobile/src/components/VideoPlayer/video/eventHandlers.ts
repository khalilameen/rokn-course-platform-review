import type {Dispatch, SetStateAction} from 'react';
import type Video from 'react-native-video';
import type {VideoRef} from 'react-native-video';
import {reportClientError} from '../../../services/operationalTelemetry';
import type {CourseReel} from '../types';
import {
  qualityForTrackHeight,
  type PlaybackPlayerEvent,
  type PlaybackRuntimeMetrics,
} from '../playbackTelemetry';
import {sourceHostForLog} from './policy';

type VideoProps = React.ComponentProps<typeof Video>;
type VideoEventHandlers = Pick<
  VideoProps,
  | 'onBandwidthUpdate'
  | 'onBuffer'
  | 'onEnd'
  | 'onError'
  | 'onLoad'
  | 'onLoadStart'
  | 'onPlaybackStateChanged'
  | 'onProgress'
  | 'onVideoTracks'
>;

type MutableValue<T> = {current: T};
type SetValue<T> = Dispatch<SetStateAction<T>>;

type VideoEventContext = {
  bufferCount: MutableValue<number>;
  bufferDurationMs: MutableValue<number>;
  bufferingStartedAt: MutableValue<number | null>;
  data: Pick<CourseReel, 'id'>;
  diagnosticRequest: MutableValue<number>;
  duration: number;
  durationRef: MutableValue<number>;
  emitPlaybackEvent: (
    eventType: PlaybackPlayerEvent['eventType'],
    options?: Pick<
      PlaybackPlayerEvent,
      'endReason' | 'errorCode' | 'diagnostics'
    >,
  ) => void;
  hasRestored: MutableValue<boolean>;
  hasStarted: MutableValue<boolean>;
  isFallbackSource: boolean;
  isPlaying: MutableValue<boolean>;
  isVisible: boolean;
  lastPosition: MutableValue<number>;
  loadStartedAt: MutableValue<number | null>;
  longBufferTimer: MutableValue<ReturnType<typeof setTimeout> | null>;
  onComplete?: () => void;
  onProgressChange?: (currentTime: number, duration: number) => void;
  publishRuntimeMetrics: (updates: Partial<PlaybackRuntimeMetrics>) => void;
  recoverOrFail: (reason: 'source' | 'timeout') => boolean;
  recoveryAttempts: MutableValue<number>;
  reelInitialPosition: MutableValue<number>;
  retryPosition: MutableValue<number | null>;
  setBufferedTime: SetValue<number>;
  setCurrentTime: SetValue<number>;
  setError: SetValue<boolean>;
  setIsBuffering: SetValue<boolean>;
  setIsLoaded: SetValue<boolean>;
  setRecoveryMessage: SetValue<string>;
  setDuration: SetValue<number>;
  sourceType?: string;
  sourceUri: string;
  videoRef: MutableValue<VideoRef | null>;
};

type NativeErrorRecord = Record<string, unknown>;

const asErrorRecord = (value: unknown): NativeErrorRecord =>
  typeof value === 'object' && value !== null
    ? (value as NativeErrorRecord)
    : {};

export const nativeVideoErrorCode = (event: unknown): string => {
  const root = asErrorRecord(event);
  const error = asErrorRecord(root.error || event);
  return String(
    error.errorCode || error.code || error.errorString || 'unknown',
  ).slice(0, 120);
};

export const createVideoEventHandlers = (
  context: VideoEventContext,
): VideoEventHandlers => ({
  onLoadStart: () => {
    if (!context.hasStarted.current && context.loadStartedAt.current === null) {
      context.loadStartedAt.current = Date.now();
    }
    context.setError(false);
    context.setIsBuffering(true);
  },
  onLoad: event => {
    if (context.longBufferTimer.current) {
      clearTimeout(context.longBufferTimer.current);
      context.longBufferTimer.current = null;
    }
    const loadedDuration = Number(event.duration || 0);
    context.setDuration(loadedDuration);
    context.durationRef.current = loadedDuration;
    context.setBufferedTime(0);
    context.setIsLoaded(true);
    context.setIsBuffering(false);
    context.setRecoveryMessage('');
    context.diagnosticRequest.current += 1;
    if (!context.hasRestored.current) {
      const requestedPosition =
        context.retryPosition.current ?? context.reelInitialPosition.current;
      const resumeAt =
        loadedDuration > 0 && requestedPosition >= loadedDuration - 3
          ? 0
          : Math.max(0, requestedPosition);
      if (resumeAt > 0) context.videoRef.current?.seek(resumeAt);
      context.setCurrentTime(resumeAt);
      context.lastPosition.current = resumeAt;
      context.hasRestored.current = true;
      context.retryPosition.current = null;
    }
  },
  onProgress: event => {
    const nextTime = Number(event.currentTime || 0);
    const nextDuration =
      context.duration || Number(event.seekableDuration || 0);
    context.setBufferedTime(
      Math.max(nextTime, Number(event.playableDuration || 0)),
    );
    context.lastPosition.current = nextTime;
    context.setCurrentTime(nextTime);
    if (nextDuration && !context.duration) {
      context.setDuration(nextDuration);
      context.durationRef.current = nextDuration;
    }
    context.onProgressChange?.(nextTime, nextDuration);
  },
  onBandwidthUpdate: event => {
    const bitrate = Number(event.bitrate || 0);
    const trackHeightPx = Number(event.height || 0);
    const effectiveQuality = qualityForTrackHeight(trackHeightPx);
    context.publishRuntimeMetrics({
      ...(effectiveQuality ? {effectiveQuality} : {}),
      ...(bitrate > 0
        ? {effectiveBitrateKbps: Math.max(1, Math.round(bitrate / 1000))}
        : {}),
      diagnostics: {
        source_type: context.sourceType || 'unknown',
        stage: context.isFallbackSource ? 'fallback' : 'primary',
      },
    });
  },
  onVideoTracks: event => {
    const selected = event.videoTracks.find(track => track.selected);
    if (!selected) return;
    const bitrate = Number(selected.bitrate || 0);
    const effectiveQuality = qualityForTrackHeight(
      Number(selected.height || 0),
    );
    context.publishRuntimeMetrics({
      ...(effectiveQuality ? {effectiveQuality} : {}),
      ...(bitrate > 0
        ? {effectiveBitrateKbps: Math.max(1, Math.round(bitrate / 1000))}
        : {}),
    });
  },
  onPlaybackStateChanged: event => {
    if (event.isPlaying && !context.isPlaying.current) {
      if (
        !context.hasStarted.current &&
        context.loadStartedAt.current !== null
      ) {
        context.publishRuntimeMetrics({
          startupLatencyMs: Math.max(
            0,
            Date.now() - context.loadStartedAt.current,
          ),
        });
      }
      context.emitPlaybackEvent('start', {
        diagnostics: {
          stage: context.hasStarted.current ? 'resume' : 'initial',
        },
      });
      context.hasStarted.current = true;
    }
    context.isPlaying.current = event.isPlaying;
  },
  onBuffer: event => {
    context.setIsBuffering(event.isBuffering);
    if (
      event.isBuffering &&
      context.hasStarted.current &&
      context.bufferingStartedAt.current === null
    ) {
      context.bufferingStartedAt.current = Date.now();
      context.bufferCount.current += 1;
      context.publishRuntimeMetrics({
        bufferCount: context.bufferCount.current,
      });
    } else if (
      !event.isBuffering &&
      context.bufferingStartedAt.current !== null
    ) {
      context.bufferDurationMs.current += Math.max(
        0,
        Date.now() - context.bufferingStartedAt.current,
      );
      context.bufferingStartedAt.current = null;
      context.publishRuntimeMetrics({
        bufferCount: context.bufferCount.current,
        bufferDurationMs: context.bufferDurationMs.current,
      });
    }
    if (context.longBufferTimer.current) {
      clearTimeout(context.longBufferTimer.current);
      context.longBufferTimer.current = null;
    }
    if (event.isBuffering && context.isVisible) {
      const timeoutMs = context.recoveryAttempts.current ? 7000 : 12_000;
      context.longBufferTimer.current = setTimeout(() => {
        if (context.bufferingStartedAt.current !== null) {
          context.bufferDurationMs.current += Math.max(
            0,
            Date.now() - context.bufferingStartedAt.current,
          );
          context.bufferingStartedAt.current = null;
          context.publishRuntimeMetrics({
            bufferCount: context.bufferCount.current,
            bufferDurationMs: context.bufferDurationMs.current,
          });
        }
        reportClientError(
          new Error(`video_buffer_timeout:${context.data.id}`),
          {
            source: 'video_player',
          },
        );
        const willRecover = context.recoverOrFail('timeout');
        context.emitPlaybackEvent('error', {
          errorCode: 'buffer_timeout',
          ...(willRecover ? {} : {endReason: 'playback_error'}),
          diagnostics: {
            source_type: context.sourceType || 'unknown',
            stage: context.isFallbackSource ? 'fallback' : 'primary',
            reason: 'buffer_timeout',
            retry_stage: willRecover ? 'automatic_recovery' : 'exhausted',
          },
        });
      }, timeoutMs);
    }
  },
  onError: event => {
    if (context.longBufferTimer.current) {
      clearTimeout(context.longBufferTimer.current);
      context.longBufferTimer.current = null;
    }
    context.setIsBuffering(false);
    if (context.bufferingStartedAt.current !== null) {
      context.bufferDurationMs.current += Math.max(
        0,
        Date.now() - context.bufferingStartedAt.current,
      );
      context.bufferingStartedAt.current = null;
      context.publishRuntimeMetrics({
        bufferCount: context.bufferCount.current,
        bufferDurationMs: context.bufferDurationMs.current,
      });
    }
    const errorCode = nativeVideoErrorCode(event);
    if (__DEV__) {
      console.warn('[RoknVideo] playback failed', {
        reelId: context.data.id,
        host: sourceHostForLog(context.sourceUri),
        code: errorCode,
        fallback: context.isFallbackSource,
      });
    }
    reportClientError(
      new Error(`video_playback:${errorCode}:reel:${context.data.id}`),
      {
        source: context.isFallbackSource ? 'video_fallback' : 'video_primary',
      },
    );
    const willRecover = context.recoverOrFail('source');
    context.emitPlaybackEvent('error', {
      errorCode,
      ...(willRecover ? {} : {endReason: 'playback_error'}),
      diagnostics: {
        source_type: context.sourceType || 'unknown',
        stage: context.isFallbackSource ? 'fallback' : 'primary',
        reason: 'native_error',
        player_error: errorCode,
        retry_stage: willRecover ? 'automatic_recovery' : 'exhausted',
      },
    });
  },
  onEnd: () => {
    if (context.longBufferTimer.current) {
      clearTimeout(context.longBufferTimer.current);
      context.longBufferTimer.current = null;
    }
    context.setCurrentTime(context.duration);
    context.onProgressChange?.(context.duration, context.duration);
    context.onComplete?.();
  },
});
