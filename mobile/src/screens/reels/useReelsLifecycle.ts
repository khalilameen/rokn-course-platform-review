import {useEffect, useRef} from 'react';
import type {MutableRefObject} from 'react';
import {
  flushPendingPlaybackPositions,
  persistLocalPlaybackPosition,
  reportPlaybackSessionEvent,
} from '../../components/VideoPlayer/courseLearningApi';
import type {
  CourseLearningData,
  CourseReel,
} from '../../components/VideoPlayer/types';
import type {PlaybackRuntimeMetrics} from '../../components/VideoPlayer/playbackTelemetry';
import {useAppActiveState} from '../../hooks/useAppActiveState';

type ReelsLifecycleRefs = {
  activeReel: MutableRefObject<CourseReel | undefined>;
  course: MutableRefObject<CourseLearningData | null>;
  delayedActions: MutableRefObject<Set<ReturnType<typeof setTimeout>>>;
  loadAbort: MutableRefObject<AbortController | null>;
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
  if (!reel) return;
  const courseId = refs.course.current?.id || '';
  const positionSeconds = refs.positions.current[`${courseId}:${reel.id}`] || 0;
  if (courseId) {
    void persistLocalPlaybackPosition(courseId, reel.id, positionSeconds).catch(
      () => undefined,
    );
  }
  if (!reel.playbackSessionId) return;
  const runtime = refs.playbackRuntime.current[reel.id];
  void reportPlaybackSessionEvent({
    lessonId: reel.lessonId,
    playbackSessionId: reel.playbackSessionId,
    eventType,
    endReason: eventType === 'stop' ? 'navigation' : 'app_closed',
    positionSeconds,
    durationSeconds: refs.playbackDurations.current[reel.id],
    playbackRate: getPlaybackSpeed(),
    ...runtime,
  });
};

export const useReelsLifecycle = (
  refs: ReelsLifecycleRefs,
  getPlaybackSpeed: () => number,
  onForeground: () => void,
) => {
  const appIsActive = useAppActiveState();
  const previouslyActiveRef = useRef(appIsActive);

  useEffect(() => {
    const delayedActions = refs.delayedActions.current;
    refs.mounted.current = true;
    return () => {
      reportActiveSession(refs, 'stop', getPlaybackSpeed);
      refs.mounted.current = false;
      refs.loadAbort.current?.abort();
      refs.loadAbort.current = null;
      refs.loadRequest.current += 1;
      refs.reviewWatcher.current += 1;
      delayedActions.forEach(clearTimeout);
      delayedActions.clear();
      void flushPendingPlaybackPositions();
    };
  }, [getPlaybackSpeed, refs]);

  useEffect(() => {
    const wasActive = previouslyActiveRef.current;
    previouslyActiveRef.current = appIsActive;
    if (appIsActive) {
      if (!wasActive) onForeground();
      return;
    }
    if (wasActive) {
      // Autoplay advances, manifest retries and transition callbacks are only
      // meaningful while the learner can see this screen. Let foreground
      // reconciliation recreate current work instead of allowing an old timer
      // to move the feed or touch an expired source behind the lock screen.
      refs.delayedActions.current.forEach(clearTimeout);
      refs.delayedActions.current.clear();
      reportActiveSession(refs, 'background', getPlaybackSpeed);
      void flushPendingPlaybackPositions();
    }
  }, [appIsActive, getPlaybackSpeed, onForeground, refs]);

  return appIsActive;
};
