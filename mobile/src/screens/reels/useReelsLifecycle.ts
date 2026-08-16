import {useEffect} from 'react';
import type {MutableRefObject} from 'react';
import {AppState, NativeModules} from 'react-native';
import {
  flushPendingPlaybackPositions,
  reportPlaybackSessionEvent,
} from '../../components/VideoPlayer/courseLearningApi';
import type {
  CourseLearningData,
  CourseReel,
} from '../../components/VideoPlayer/types';
import type {PlaybackRuntimeMetrics} from '../../components/VideoPlayer/playbackTelemetry';

type ReelsLifecycleRefs = {
  activeReel: MutableRefObject<CourseReel | undefined>;
  course: MutableRefObject<CourseLearningData | null>;
  delayedActions: MutableRefObject<Set<ReturnType<typeof setTimeout>>>;
  loadRequest: MutableRefObject<number>;
  mounted: MutableRefObject<boolean>;
  playbackDurations: MutableRefObject<Record<string, number>>;
  playbackRuntime: MutableRefObject<Record<string, PlaybackRuntimeMetrics>>;
  positions: MutableRefObject<Record<string, number>>;
  reviewWatcher: MutableRefObject<number>;
};

const reportActiveSession = (
  refs: ReelsLifecycleRefs,
  eventType: 'stop' | 'background',
  getPlaybackSpeed: () => number,
) => {
  const reel = refs.activeReel.current;
  if (!reel?.playbackSessionId) return;
  const runtime = refs.playbackRuntime.current[reel.id];
  void reportPlaybackSessionEvent({
    lessonId: reel.lessonId,
    playbackSessionId: reel.playbackSessionId,
    eventType,
    endReason: eventType === 'stop' ? 'navigation' : 'app_closed',
    positionSeconds:
      refs.positions.current[`${refs.course.current?.id || ''}:${reel.id}`] ||
      0,
    durationSeconds: refs.playbackDurations.current[reel.id],
    playbackRate: getPlaybackSpeed(),
    ...runtime,
  });
};

export const useReelsLifecycle = (
  refs: ReelsLifecycleRefs,
  getPlaybackSpeed: () => number,
) => {
  useEffect(() => {
    const delayedActions = refs.delayedActions.current;
    refs.mounted.current = true;
    NativeModules.RoknOrientation?.lockPortrait?.();
    return () => {
      reportActiveSession(refs, 'stop', getPlaybackSpeed);
      refs.mounted.current = false;
      refs.loadRequest.current += 1;
      refs.reviewWatcher.current += 1;
      delayedActions.forEach(clearTimeout);
      delayedActions.clear();
      void flushPendingPlaybackPositions();
      NativeModules.RoknOrientation?.unlock?.();
    };
  }, [getPlaybackSpeed, refs]);

  useEffect(() => {
    const subscription = AppState.addEventListener('change', nextState => {
      if (nextState !== 'active') {
        reportActiveSession(refs, 'background', getPlaybackSpeed);
        void flushPendingPlaybackPositions();
      }
    });
    return () => subscription.remove();
  }, [getPlaybackSpeed, refs]);
};
