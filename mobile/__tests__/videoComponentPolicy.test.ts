jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('react-native-video', () => {
  const React = require('react');
  return {
    __esModule: true,
    default: React.forwardRef(() => null),
    SelectedVideoTrackType: {AUTO: 'auto', RESOLUTION: 'resolution'},
  };
});
jest.mock('react-native-linear-gradient', () => 'LinearGradient');

import VideoComponent from '../src/components/VideoPlayer/VideoComponent';
import {createVideoEventHandlers} from '../src/components/VideoPlayer/video/eventHandlers';
import {
  formatVideoDuration,
  normalizeVideoUri,
  selectPlaybackErrorCopy,
  selectPlaybackRecoveryStep,
  selectVideoSource,
  selectVideoTimeline,
} from '../src/components/VideoPlayer/video/policy';

describe('VideoComponent facade', () => {
  it('keeps the named forwardRef default export', () => {
    expect(VideoComponent.displayName).toBe('VideoComponent');
    expect(typeof (VideoComponent as unknown as {render: unknown}).render).toBe(
      'function',
    );
  });
});

describe('video component policy', () => {
  it('drops every native event owned by a replaced player instance', () => {
    const setCurrentTime = jest.fn();
    const onProgressChange = jest.fn();
    const onComplete = jest.fn();
    const recoverOrFail = jest.fn();
    const handlers = createVideoEventHandlers({
      bufferCount: {current: 0},
      bufferDurationMs: {current: 0},
      bufferingStartedAt: {current: null},
      data: {id: 'old-reel'},
      diagnosticRequest: {current: 0},
      duration: 60,
      durationRef: {current: 60},
      emitPlaybackEvent: jest.fn(),
      hasRestored: {current: false},
      hasStarted: {current: false},
      isFallbackSource: false,
      isPlaying: {current: false},
      isVisible: true,
      lastPosition: {current: 0},
      loadStartedAt: {current: null},
      longBufferTimer: {current: null},
      onComplete,
      onProgressChange,
      ownsPlayback: () => false,
      pendingSeek: {current: null},
      publishRuntimeMetrics: jest.fn(),
      recoverOrFail,
      recoveryAttempts: {current: 0},
      reelInitialPosition: {current: 0},
      retryPosition: {current: null},
      setBufferedTime: jest.fn(),
      setCurrentTime,
      setDuration: jest.fn(),
      setError: jest.fn(),
      setIsBuffering: jest.fn(),
      setIsLoaded: jest.fn(),
      setRecoveryMessage: jest.fn(),
      sourceType: 'm3u8',
      sourceUri: 'https://cdn.example.com/old.m3u8',
      videoRef: {current: null},
    });

    handlers.onLoadStart?.({isNetwork: true} as never);
    handlers.onProgress?.({
      currentTime: 59,
      playableDuration: 60,
      seekableDuration: 60,
    } as never);
    handlers.onError?.({error: {errorCode: 'late'}} as never);
    handlers.onEnd?.();

    expect(setCurrentTime).not.toHaveBeenCalled();
    expect(onProgressChange).not.toHaveBeenCalled();
    expect(recoverOrFail).not.toHaveBeenCalled();
    expect(onComplete).not.toHaveBeenCalled();
  });

  it('normalizes remote sources without breaking emulator loopback URLs', () => {
    expect(
      normalizeVideoUri(' //cdn.example.com/video.m3u8?a=1&amp;b=2 '),
    ).toBe('https://cdn.example.com/video.m3u8?a=1&b=2');
    expect(normalizeVideoUri('http://cdn.example.com/video.mp4')).toBe(
      'https://cdn.example.com/video.mp4',
    );
    expect(normalizeVideoUri('http://10.0.2.2:8000/video.mp4')).toBe(
      'http://10.0.2.2:8000/video.mp4',
    );
  });

  it('selects quality variants and supported fallbacks with media types', () => {
    const variant = selectVideoSource({
      effectiveQuality: '720p',
      qualitySources: {'720p': 'https://cdn.example.com/video-720.mp4'},
      usingFallback: false,
      videoUrl: 'https://cdn.example.com/master.m3u8',
    });
    expect(variant.source).toEqual({
      uri: 'https://cdn.example.com/video-720.mp4',
      type: 'mp4',
    });
    expect(variant.selectedVariantUri).toContain('video-720.mp4');

    const fallback = selectVideoSource({
      effectiveQuality: 'auto',
      fallbackVideoUrl: 'https://cdn.example.com/fallback.mp4',
      usingFallback: false,
      videoUrl: 'https://youtube.com/watch?v=lesson',
    });
    expect(fallback.isFallbackSource).toBe(true);
    expect(fallback.source).toEqual({
      uri: 'https://cdn.example.com/fallback.mp4',
      type: 'mp4',
    });
    expect(fallback.unsupportedSource).toBe(false);
  });

  it('keeps the bounded recovery order', () => {
    const base = {
      adaptiveSource: true,
      availableQualities: ['auto', '1080p', '720p', '480p'] as const,
      effectiveQuality: 'auto' as const,
      hasSelectedVariant: false,
      hasSupportedFallback: true,
      isFallbackSource: false,
      isVisible: true,
      recoveryAttempts: 0,
      recoveryPending: false,
      sameSourceRetryUsed: false,
    };

    expect(
      selectPlaybackRecoveryStep({
        ...base,
        availableQualities: [...base.availableQualities],
      }),
    ).toEqual({kind: 'quality', quality: '480p'});
    expect(
      selectPlaybackRecoveryStep({
        ...base,
        availableQualities: [...base.availableQualities],
        recoveryAttempts: 2,
      }),
    ).toEqual({kind: 'fallback'});
    expect(
      selectPlaybackRecoveryStep({
        ...base,
        availableQualities: [...base.availableQualities],
        hasSupportedFallback: false,
        recoveryAttempts: 2,
      }),
    ).toEqual({kind: 'retry'});
    expect(
      selectPlaybackRecoveryStep({
        ...base,
        availableQualities: [...base.availableQualities],
        hasSupportedFallback: false,
        recoveryAttempts: 2,
        sameSourceRetryUsed: true,
      }),
    ).toEqual({kind: 'fail'});
    expect(
      selectPlaybackRecoveryStep({
        ...base,
        availableQualities: [...base.availableQualities],
        isVisible: false,
      }),
    ).toEqual({kind: 'defer'});
  });

  it('preserves timeline bounds and learner-facing failure copy', () => {
    expect(
      selectVideoTimeline({
        bufferedTime: 75,
        currentTime: 30,
        duration: 120,
        previewTime: 60,
      }),
    ).toEqual({
      accessibilityDuration: 120,
      accessibilityPosition: 60,
      bufferedProgress: 0.625,
      displayedTime: 60,
      duration: 120,
      progress: 0.5,
      remaining: 60,
    });
    expect(formatVideoDuration(65)).toBe('١:٠٥');
    expect(selectPlaybackErrorCopy('offline', false).title).toBe(
      'أنت غير متصل بالإنترنت',
    );
  });
});
