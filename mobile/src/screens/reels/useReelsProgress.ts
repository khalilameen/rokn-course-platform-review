import {
  useCallback,
  useEffect,
  type Dispatch,
  type MutableRefObject,
  type SetStateAction,
} from 'react';
import {
  flushPendingPlaybackPositions,
  markSectionComplete,
  savePlaybackPosition,
} from '../../components/VideoPlayer/courseLearningApi';
import type {PlaybackRuntimeMetrics} from '../../components/VideoPlayer/playbackTelemetry';
import type {
  CourseFeedItem,
  CourseLearningData,
  CourseReel,
} from '../../components/VideoPlayer/types';
import {recordDemoQualifiedStudy} from '../../services/demoExperience';
import {scheduleNextLearningReminder} from '../../services/smartReminders';
import {
  buildPlaybackEvidence,
  markReelCompleted,
  nextLearningTitle,
} from './progress';

type StudySample = {
  reelId: string;
  mediaTime: number;
  sampledAt: number;
};

type ReelsProgressRefs = {
  completionSent: MutableRefObject<Set<string>>;
  demoRewardsEnabled: MutableRefObject<boolean>;
  feedLength: MutableRefObject<number>;
  lastPersisted: MutableRefObject<Record<string, number>>;
  pendingStudySeconds: MutableRefObject<number>;
  playbackDurations: MutableRefObject<Record<string, number>>;
  playbackRuntime: MutableRefObject<Record<string, PlaybackRuntimeMetrics>>;
  positions: MutableRefObject<Record<string, number>>;
  studySample: MutableRefObject<StudySample | null>;
};

export const useReelsProgress = ({
  autoplay,
  course,
  currentIndex,
  feedItems,
  maybeOfferReminders,
  playbackSpeed,
  previewMode,
  refs,
  scheduleDelayedAction,
  scrollToIndex,
  setChatVisible,
  setCourse,
  setPreviewGateVisible,
}: {
  autoplay: boolean;
  course: CourseLearningData | null;
  currentIndex: number;
  feedItems: CourseFeedItem[];
  maybeOfferReminders: () => void;
  playbackSpeed: number;
  previewMode: boolean;
  refs: ReelsProgressRefs;
  scheduleDelayedAction: (action: () => void, delayMs: number) => void;
  scrollToIndex: (index: number, animated?: boolean) => void;
  setChatVisible: Dispatch<SetStateAction<boolean>>;
  setCourse: Dispatch<SetStateAction<CourseLearningData | null>>;
  setPreviewGateVisible: Dispatch<SetStateAction<boolean>>;
}) => {
  const updateReelCompletion = useCallback(
    (reel: CourseReel) => {
      setCourse(current =>
        current ? markReelCompleted(current, reel) : current,
      );
    },
    [setCourse],
  );

  const flushDemoStudy = useCallback(
    (all = false) => {
      if (!refs.demoRewardsEnabled.current) return;
      const available = refs.pendingStudySeconds.current;
      const seconds = all
        ? Math.floor(available)
        : Math.floor(available / 30) * 30;
      if (seconds <= 0) return;
      refs.pendingStudySeconds.current = Math.max(0, available - seconds);
      void recordDemoQualifiedStudy(seconds).catch(() => undefined);
    },
    [refs.demoRewardsEnabled, refs.pendingStudySeconds],
  );

  useEffect(
    () => () => {
      flushDemoStudy(true);
    },
    [flushDemoStudy],
  );

  const persistProgress = useCallback(
    (reel: CourseReel, currentTime: number, duration: number) => {
      if (!course) return;
      refs.positions.current[`${course.id}:${reel.id}`] = currentTime;
      if (duration > 0) refs.playbackDurations.current[reel.id] = duration;
      const runtime = refs.playbackRuntime.current[reel.id];
      if (refs.demoRewardsEnabled.current && !previewMode && duration > 0) {
        const now = Date.now();
        const previous = refs.studySample.current;
        refs.studySample.current = {
          reelId: reel.id,
          mediaTime: currentTime,
          sampledAt: now,
        };
        if (previous?.reelId === reel.id) {
          const wallDelta = (now - previous.sampledAt) / 1000;
          const mediaDelta = currentTime - previous.mediaTime;
          const seekLimit = wallDelta * Math.max(1, playbackSpeed) * 2.5 + 2;
          if (
            wallDelta > 0 &&
            wallDelta <= 20 &&
            mediaDelta > 0 &&
            mediaDelta <= seekLimit
          ) {
            refs.pendingStudySeconds.current += Math.min(
              wallDelta,
              mediaDelta / Math.max(0.5, playbackSpeed),
            );
            flushDemoStudy();
          }
        }
      }
      const hasSavedSample = Object.prototype.hasOwnProperty.call(
        refs.lastPersisted.current,
        reel.id,
      );
      const lastSaved = refs.lastPersisted.current[reel.id] || 0;
      const reachedCompletion = duration > 0 && currentTime / duration >= 0.95;
      const evidence = buildPlaybackEvidence(reel, runtime, playbackSpeed);
      let progressSave: Promise<void> | null = null;
      if (
        !hasSavedSample ||
        Math.abs(currentTime - lastSaved) >= 15 ||
        reachedCompletion
      ) {
        refs.lastPersisted.current[reel.id] = currentTime;
        progressSave = savePlaybackPosition(
          course.id,
          reel.id,
          currentTime,
          reel.lessonId,
          duration,
          reachedCompletion,
          evidence,
        );
        if (!reachedCompletion) progressSave.catch(() => undefined);
      }
      if (
        reachedCompletion &&
        !refs.completionSent.current.has(reel.sectionId)
      ) {
        refs.completionSent.current.add(reel.sectionId);
        updateReelCompletion(reel);
        maybeOfferReminders();
        const finalEvidenceSave =
          progressSave ??
          savePlaybackPosition(
            course.id,
            reel.id,
            currentTime,
            reel.lessonId,
            duration,
            true,
            evidence,
          );
        void finalEvidenceSave
          .then(flushPendingPlaybackPositions)
          .then(() => markSectionComplete(course.id, reel.sectionId))
          .catch(() => undefined);
        const nextTitle = nextLearningTitle(course, reel);
        const lastPreviewItem = feedItems[feedItems.length - 1];
        const isLastPreviewReel =
          previewMode &&
          lastPreviewItem?.type === 'reel' &&
          lastPreviewItem.reel.id === reel.id;
        if (nextTitle && !isLastPreviewReel) {
          scheduleNextLearningReminder({
            nextReelTitle: nextTitle,
            courseTitle: course.title,
            courseId: course.id,
          }).catch(() => undefined);
        }
      }
    },
    [
      course,
      feedItems,
      flushDemoStudy,
      maybeOfferReminders,
      playbackSpeed,
      previewMode,
      refs,
      updateReelCompletion,
    ],
  );

  const completeAndAdvance = useCallback(
    (reel: CourseReel) => {
      if (!course) return;
      updateReelCompletion(reel);
      flushDemoStudy(true);
      maybeOfferReminders();
      if (!refs.completionSent.current.has(reel.sectionId)) {
        refs.completionSent.current.add(reel.sectionId);
        const position = Math.max(
          0,
          refs.positions.current[`${course.id}:${reel.id}`] || 0,
        );
        const duration = Math.max(position, reel.durationSeconds || 0);
        const runtime = refs.playbackRuntime.current[reel.id];
        void savePlaybackPosition(
          course.id,
          reel.id,
          position,
          reel.lessonId,
          duration || undefined,
          true,
          buildPlaybackEvidence(reel, runtime, playbackSpeed),
        )
          .then(flushPendingPlaybackPositions)
          .then(() => markSectionComplete(course.id, reel.sectionId))
          .catch(() => undefined);
      }
      const isLastPreviewReel =
        previewMode && currentIndex >= refs.feedLength.current - 1;
      const nextTitle = nextLearningTitle(course, reel);
      if (nextTitle && !isLastPreviewReel) {
        scheduleNextLearningReminder({
          nextReelTitle: nextTitle,
          courseTitle: course.title,
          courseId: course.id,
        }).catch(() => undefined);
      }
      if (isLastPreviewReel) {
        setChatVisible(false);
        setPreviewGateVisible(true);
        return;
      }
      if (autoplay) {
        scheduleDelayedAction(() => scrollToIndex(currentIndex + 1), 280);
      }
    },
    [
      autoplay,
      course,
      currentIndex,
      flushDemoStudy,
      maybeOfferReminders,
      playbackSpeed,
      previewMode,
      refs,
      scheduleDelayedAction,
      scrollToIndex,
      setChatVisible,
      setPreviewGateVisible,
      updateReelCompletion,
    ],
  );

  return {completeAndAdvance, persistProgress};
};
