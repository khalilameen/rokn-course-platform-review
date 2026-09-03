import {useCallback} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import {
  applyLocalLearningState,
  getLocalLearningState,
  loadCourseLearningData,
  reconcileServerSavedLessons,
} from '../../components/VideoPlayer/courseLearningApi';
import type {CourseLearningData} from '../../components/VideoPlayer/types';
import type {PlaybackRuntimeMetrics} from '../../components/VideoPlayer/playbackTelemetry';
import {isLocalDemoId, LOCAL_DEMO_ENABLED} from '../../config/runtime';
import {
  DEMO_COURSE_ID,
  claimDemoCourseCompletionReward,
  claimDemoFirstProjectReward,
  hasDemoCourseAccess,
} from '../../services/demoExperience';
import {
  friendlyNetworkMessage,
  networkFailureKind,
} from '../../services/networkExperience';
import {hasSession} from '../../services/roknApi';
import type {RootNavigation} from '../../navigation/types';
import {
  buildAccessibleFeed,
  buildPreviewFeed,
  type ReelsRouteParams,
} from './presentation';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';

type CourseLoaderRefs = {
  closedPlaybackSessions: MutableRefObject<Set<string>>;
  demoRewardsEnabled: MutableRefObject<boolean>;
  loadRequest: MutableRefObject<number>;
  loadAbort: MutableRefObject<AbortController | null>;
  loadedCourse: MutableRefObject<CourseLearningData | null>;
  loadedCourseOwner: MutableRefObject<string>;
  manifestVersions: MutableRefObject<Record<string, number>>;
  pendingInitialIndex: MutableRefObject<number | null>;
  pendingInitialKey: MutableRefObject<string | null>;
  playbackDurations: MutableRefObject<Record<string, number>>;
  playbackRuntime: MutableRefObject<Record<string, PlaybackRuntimeMetrics>>;
  positions: MutableRefObject<Record<string, number>>;
};

export type CourseReloadTarget = {
  lessonId?: string;
  index?: number;
  onResult?: (succeeded: boolean) => void;
};

export const useReelsCourseLoader = ({
  navigation,
  identityKey,
  params,
  previewMode,
  refs,
  setConnectionNote,
  setCourse,
  setLoadError,
  setLoading,
  setPreviewGateVisible,
  setSavedLessons,
  setServerSession,
}: {
  navigation: Pick<RootNavigation, 'replace'>;
  identityKey: string;
  params: ReelsRouteParams;
  previewMode: boolean;
  refs: CourseLoaderRefs;
  setConnectionNote: Dispatch<SetStateAction<string>>;
  setCourse: Dispatch<SetStateAction<CourseLearningData | null>>;
  setLoadError: Dispatch<SetStateAction<string>>;
  setLoading: Dispatch<SetStateAction<boolean>>;
  setPreviewGateVisible: Dispatch<SetStateAction<boolean>>;
  setSavedLessons: Dispatch<SetStateAction<Set<string>>>;
  setServerSession: Dispatch<SetStateAction<boolean | null>>;
}) =>
  useCallback(async (reloadTarget?: CourseReloadTarget) => {
    refs.loadAbort.current?.abort();
    const controller = new AbortController();
    refs.loadAbort.current = controller;
    const requestId = ++refs.loadRequest.current;
    const requestedCourseId = String(
      params.courseId || (LOCAL_DEMO_ENABLED ? DEMO_COURSE_ID : ''),
    );
    const hasCurrentCourse =
      refs.loadedCourse.current?.id === requestedCourseId &&
      refs.loadedCourseOwner.current === identityKey;
    if (!hasCurrentCourse) {
      refs.closedPlaybackSessions.current.clear();
      refs.playbackRuntime.current = {};
      refs.playbackDurations.current = {};
      refs.manifestVersions.current = {};
      refs.loadedCourse.current = null;
      refs.loadedCourseOwner.current = identityKey;
      setCourse(null);
      setLoading(true);
      setLoadError('');
    }
    setPreviewGateVisible(false);
    const boundary = await captureAccountSessionBoundary().catch(() => null);
    const ownsLoadSlot = () =>
      requestId === refs.loadRequest.current &&
      refs.loadedCourseOwner.current === identityKey;
    const isCurrentOwner = () => {
      if (!boundary || !ownsLoadSlot()) return false;
      try {
        assertAccountSessionBoundary(boundary);
        return true;
      } catch {
        return false;
      }
    };
    try {
      if (!boundary) {
        if (!ownsLoadSlot()) return;
        if (hasCurrentCourse) {
          setConnectionNote(
            'تعذّر تحديث محتوى الكورس\nحاول مرة أخرى من زر الفيديو',
          );
        } else {
          setLoadError(
            'تعذّر فتح محتوى الكورس\nمكانك محفوظ\nحاول مرة أخرى',
          );
        }
        if (refs.loadAbort.current === controller) {
          refs.loadAbort.current = null;
        }
        setLoading(false);
        reloadTarget?.onResult?.(false);
        return;
      }
      if (
        LOCAL_DEMO_ENABLED &&
        isLocalDemoId(requestedCourseId) &&
        !previewMode &&
        !(await hasDemoCourseAccess(requestedCourseId))
      ) {
        if (!isCurrentOwner()) return;
        navigation.replace('CourseDetails', {courseId: requestedCourseId});
        reloadTarget?.onResult?.(false);
        return;
      }
      const result = await loadCourseLearningData(
        requestedCourseId || undefined,
        {signal: controller.signal},
      );
      if (!isCurrentOwner()) return;
      // A public details payload contains the free samples plus the outline.
      // It is not a learning entitlement. If a stale CTA/deep link opens the
      // player without preview mode, return to the commercial course page
      // instead of turning the first paid reel into a project-style gate.
      const accessType = String(result.course.accessType || '')
        .trim()
        .toLowerCase();
      if (!previewMode && (!accessType || accessType === 'none' || accessType === 'preview')) {
        navigation.replace('CourseDetails', {courseId: requestedCourseId});
        reloadTarget?.onResult?.(false);
        return;
      }
      const [withLocalState, localState, sessionAvailable] = await Promise.all([
        applyLocalLearningState(result.course),
        getLocalLearningState(),
        hasSession(),
      ]);
      if (!isCurrentOwner()) return;
      setServerSession(sessionAvailable);
      refs.demoRewardsEnabled.current =
        !sessionAvailable && isLocalDemoId(withLocalState.id);
      if (refs.demoRewardsEnabled.current) {
        const passedProjects = withLocalState.modules
          .flatMap(module =>
            module.projects?.length
              ? module.projects
              : module.project
              ? [module.project]
              : [],
          )
          .filter(project => project?.status === 'passed');
        if (passedProjects[0]) {
          void claimDemoFirstProjectReward(passedProjects[0].id).catch(
            () => undefined,
          );
        }
        const finalProject = withLocalState.modules
          .flatMap(module =>
            module.projects?.length
              ? module.projects
              : module.project
              ? [module.project]
              : [],
          )
          .at(-1);
        if (finalProject?.status === 'passed') {
          void claimDemoCourseCompletionReward(withLocalState.id).catch(
            () => undefined,
          );
        }
      }
      refs.positions.current = localState.positions;
      setSavedLessons(new Set(localState.savedLessons));
      refs.loadedCourse.current = withLocalState;
      refs.loadedCourseOwner.current = identityKey;
      setCourse(withLocalState);
      setConnectionNote('');
      if (sessionAvailable) {
        const lessonIds = withLocalState.modules.flatMap(module =>
          module.reels.map(reel => reel.lessonId),
        );
        void reconcileServerSavedLessons(lessonIds)
          .then(serverSaved => {
            if (isCurrentOwner()) {
              setSavedLessons(new Set(serverSaved));
            }
          })
          .catch(() => undefined);
      }
      const requestedReel =
        String(reloadTarget?.lessonId || '').trim() ||
        params.reelId ||
        params.lessonId;
      const requestedPosition = Number(params.initialPositionSeconds);
      if (
        !reloadTarget &&
        requestedReel &&
        Number.isFinite(requestedPosition) &&
        requestedPosition > 0
      ) {
        refs.positions.current[
          `${withLocalState.id}:${String(requestedReel)}`
        ] = requestedPosition;
      }
      const accessibleItems = previewMode
        ? buildPreviewFeed(withLocalState, Number(params.previewCount) || 0)
        : buildAccessibleFeed(withLocalState);
      const requestedKey = requestedReel
        ? `reel-${String(requestedReel)}`
        : null;
      const requestedReelExists = Boolean(
        requestedKey && accessibleItems.some(item => item.key === requestedKey),
      );
      refs.pendingInitialKey.current = requestedReelExists
        ? requestedKey
        : null;
      const requestedIndex = Number(
        reloadTarget?.index ?? params.initialReelIndex,
      );
      const firstPendingIndex = accessibleItems.findIndex(item =>
        item.type === 'project'
          ? item.project.status !== 'passed'
          : item.type === 'quiz'
          ? !item.quiz.passed
          : !item.reel.isCompleted,
      );
      refs.pendingInitialIndex.current =
        !requestedReelExists && Number.isFinite(requestedIndex)
          ? Math.max(0, Math.floor(requestedIndex))
          : !requestedReelExists && accessibleItems.length
          ? firstPendingIndex >= 0
            ? firstPendingIndex
            : accessibleItems.length - 1
          : null;
      reloadTarget?.onResult?.(true);
    } catch (error) {
      if (!isCurrentOwner()) return;
      if (networkFailureKind(error) === 'cancelled') return;
      if (hasCurrentCourse) {
        setConnectionNote(friendlyNetworkMessage(error, 'الفيديو'));
      } else {
        refs.loadedCourse.current = null;
        setCourse(null);
        setLoadError(
          'تعذّر فتح محتوى الكورس\nمكانك محفوظ\nتحقق من الاتصال ثم حاول مرة أخرى',
        );
      }
      reloadTarget?.onResult?.(false);
    } finally {
      if (isCurrentOwner()) {
        if (refs.loadAbort.current === controller) refs.loadAbort.current = null;
        setLoading(false);
      }
    }
  }, [
    navigation,
    identityKey,
    params.courseId,
    params.initialReelIndex,
    params.initialPositionSeconds,
    params.lessonId,
    params.previewCount,
    params.reelId,
    previewMode,
    refs,
    setConnectionNote,
    setCourse,
    setLoadError,
    setLoading,
    setPreviewGateVisible,
    setSavedLessons,
    setServerSession,
  ]);
