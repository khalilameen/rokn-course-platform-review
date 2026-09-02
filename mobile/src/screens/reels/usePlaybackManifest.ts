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
  playbackManifestHttpStatus,
} from './playbackCourseState';

type PlaybackManifestRefs = {
  course: MutableRefObject<CourseLearningData | null>;
  durations: MutableRefObject<Record<string, number>>;
  flights: MutableRefObject<Map<string, Promise<void>>>;
  mounted: MutableRefObject<boolean>;
  ownerGeneration: MutableRefObject<number>;
  positions: MutableRefObject<Record<string, number>>;
  revisionReloadPending: MutableRefObject<boolean>;
  runtime: MutableRefObject<Record<string, PlaybackRuntimeMetrics>>;
  versions: MutableRefObject<Record<string, number>>;
};

export const usePlaybackManifest = ({
  courseId,
  dataSaver,
  getPlaybackSpeed,
  onCourseRevisionChanged,
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
  onCourseRevisionChanged: () => void;
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
    (
      reel: CourseReel,
      expectedSessionId?: string,
      reuseExpectedSession = true,
    ) => {
      if (refs.revisionReloadPending.current) {
        onCourseRevisionChanged();
        return;
      }
      if (!serverSession || !playbackPreferencesReady) {
        return;
      }
      const sourceCourseId = courseId;
      const ownerGeneration = refs.ownerGeneration.current;
      const lessonId = reel.lessonId;
      const maxBitrateKbps = dataSaver
        ? 750
        : PLAYBACK_PREFERENCE_BITRATE_KBPS[selectedQuality];
      const flightKey = JSON.stringify({
        lessonId,
        dataSaver,
        maxBitrateKbps: maxBitrateKbps ?? null,
        playbackSessionId: reuseExpectedSession
          ? expectedSessionId ?? null
          : null,
        reuseExpectedSession,
      });
      const existingFlight = refs.flights.current.get(flightKey);
      if (existingFlight) return existingFlight;
      const requestVersion = (refs.versions.current[lessonId] || 0) + 1;
      refs.versions.current[lessonId] = requestVersion;
      const flight = openPlaybackSession(lessonId, {
        dataSaver,
        maxBitrateKbps,
        playbackSessionId: reuseExpectedSession
          ? expectedSessionId
          : undefined,
      })
        .then(manifest => {
          if (
            !refs.mounted.current ||
            refs.ownerGeneration.current !== ownerGeneration ||
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

          setConnectionNote(current =>
            current ===
              'تعذّر تجديد رابط الفيديو\nسنحاول مرة أخرى' ||
            current === 'الفيديو قيد التجهيز\nحاول بعد قليل'
              ? ''
              : current,
          );
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
            !refs.mounted.current ||
            refs.ownerGeneration.current !== ownerGeneration ||
            refs.course.current?.id !== sourceCourseId ||
            refs.versions.current[lessonId] !== requestVersion
          ) {
            return;
          }
          const code = playbackFeatureErrorCode(error).toLowerCase();
          const status = playbackManifestHttpStatus(error);
          if (code === 'course_revision_changed') {
            onCourseRevisionChanged();
            return;
          }
          if (code === 'feature_playback_disabled') {
            setConnectionNote('تشغيل الفيديو متوقف مؤقتًا للصيانة');
            setCourse(previous =>
              disableLessonPlayback(previous, sourceCourseId, lessonId),
            );
            return;
          }
          if (status === 403 || status === 404 || code === 'lesson_locked') {
            setConnectionNote('هذا المقطع غير متاح لحسابك');
            setCourse(previous =>
              disableLessonPlayback(previous, sourceCourseId, lessonId),
            );
            return;
          }
          if (status === 401) {
            setConnectionNote('انتهى تسجيل الدخول\nسجّل الدخول ثم أكمل');
            return;
          }
          setConnectionNote(
            code === 'video_processing'
              ? 'الفيديو قيد التجهيز\nحاول بعد قليل'
              : 'تعذّر تجديد رابط الفيديو\nسنحاول مرة أخرى',
          );
          scheduleDelayedAction(
            () => setManifestRefreshNonce(value => value + 1),
            status === 409 ? 15_000 : 4_000,
          );
        })
        .finally(() => {
          // Delete only the flight we own. A replacement request registered
          // during a course transition must remain awaitable by the player.
          if (refs.flights.current.get(flightKey) === flight) {
            refs.flights.current.delete(flightKey);
          }
        });
      refs.flights.current.set(flightKey, flight);
      return flight;
    },
    [
      courseId,
      dataSaver,
      getPlaybackSpeed,
      onCourseRevisionChanged,
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
