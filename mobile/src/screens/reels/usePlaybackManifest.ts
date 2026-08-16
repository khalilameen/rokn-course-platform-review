import {useCallback} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import {
  openPlaybackSession,
  reportPlaybackSessionEvent,
} from '../../components/VideoPlayer/courseLearningApi';
import type {
  CourseLearningData,
  CourseReel,
  VideoQuality,
} from '../../components/VideoPlayer/types';
import type {PlaybackRuntimeMetrics} from '../../components/VideoPlayer/playbackTelemetry';
import {PLAYBACK_PREFERENCE_BITRATE_KBPS} from './presentation';
import {
  applyPlaybackManifest,
  disableLessonPlayback,
  playbackFeatureErrorCode,
} from './playbackCourseState';

type PlaybackManifestRefs = {
  course: MutableRefObject<CourseLearningData | null>;
  durations: MutableRefObject<Record<string, number>>;
  flights: MutableRefObject<Set<string>>;
  mounted: MutableRefObject<boolean>;
  positions: MutableRefObject<Record<string, number>>;
  runtime: MutableRefObject<Record<string, PlaybackRuntimeMetrics>>;
  versions: MutableRefObject<Record<string, number>>;
};

export const usePlaybackManifest = ({
  courseId,
  dataSaver,
  getPlaybackSpeed,
  playbackPreferencesReady,
  refs,
  scheduleDelayedAction,
  selectedQuality,
  serverSession,
  setConnectionNote,
  setCourse,
  setManifestRefreshNonce,
}: {
  courseId?: string;
  dataSaver: boolean;
  getPlaybackSpeed: () => number;
  playbackPreferencesReady: boolean;
  refs: PlaybackManifestRefs;
  scheduleDelayedAction: (action: () => void, delayMs: number) => void;
  selectedQuality: VideoQuality;
  serverSession: boolean | null;
  setConnectionNote: Dispatch<SetStateAction<string>>;
  setCourse: Dispatch<SetStateAction<CourseLearningData | null>>;
  setManifestRefreshNonce: Dispatch<SetStateAction<number>>;
}) =>
  useCallback(
    (reel: CourseReel, expectedSessionId?: string) => {
      if (
        !serverSession ||
        !playbackPreferencesReady ||
        refs.flights.current.has(reel.lessonId)
      ) {
        return;
      }
      const sourceCourseId = courseId;
      const lessonId = reel.lessonId;
      const requestVersion = (refs.versions.current[lessonId] || 0) + 1;
      refs.versions.current[lessonId] = requestVersion;
      refs.flights.current.add(lessonId);
      const maxBitrateKbps = dataSaver
        ? 750
        : PLAYBACK_PREFERENCE_BITRATE_KBPS[selectedQuality];
      void openPlaybackSession(lessonId, {
        dataSaver,
        maxBitrateKbps,
        playbackSessionId: expectedSessionId,
      })
        .then(manifest => {
          if (
            !refs.mounted.current ||
            refs.course.current?.id !== sourceCourseId ||
            refs.versions.current[lessonId] !== requestVersion
          ) {
            return;
          }
          if (!manifest) {
            scheduleDelayedAction(
              () => setManifestRefreshNonce(value => value + 1),
              10_000,
            );
            return;
          }

          if (
            expectedSessionId &&
            expectedSessionId !== manifest.playbackSessionId
          ) {
            const runtime = refs.runtime.current[reel.id];
            void reportPlaybackSessionEvent({
              lessonId,
              playbackSessionId: expectedSessionId,
              eventType: 'stop',
              endReason: 'replaced',
              positionSeconds:
                refs.positions.current[`${sourceCourseId}:${reel.id}`] || 0,
              durationSeconds: refs.durations.current[reel.id],
              playbackRate: getPlaybackSpeed(),
              ...runtime,
            });
          }

          setCourse(previous =>
            applyPlaybackManifest(previous, {
              courseId: sourceCourseId,
              lessonId,
              expectedSessionId,
              manifest,
              revision: Date.now(),
            }),
          );
        })
        .catch((error: unknown) => {
          if (
            playbackFeatureErrorCode(error) !== 'FEATURE_PLAYBACK_DISABLED' ||
            !refs.mounted.current ||
            refs.course.current?.id !== sourceCourseId ||
            refs.versions.current[lessonId] !== requestVersion
          ) {
            return;
          }
          setConnectionNote(
            'تشغيل الفيديو متوقف مؤقتًا للصيانة. تقدمك محفوظ ويمكنك المحاولة لاحقًا.',
          );
          setCourse(previous =>
            disableLessonPlayback(previous, sourceCourseId, lessonId),
          );
        })
        .finally(() => {
          if (refs.versions.current[lessonId] === requestVersion) {
            refs.flights.current.delete(lessonId);
          }
        });
    },
    [
      courseId,
      dataSaver,
      getPlaybackSpeed,
      playbackPreferencesReady,
      refs,
      scheduleDelayedAction,
      selectedQuality,
      serverSession,
      setConnectionNote,
      setCourse,
      setManifestRefreshNonce,
    ],
  );
