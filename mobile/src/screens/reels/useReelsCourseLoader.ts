import {useCallback} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import {
  applyLocalLearningState,
  getLocalLearningState,
  loadCourseLearningData,
} from '../../components/VideoPlayer/courseLearningApi';
import type {CourseLearningData} from '../../components/VideoPlayer/types';
import type {PlaybackRuntimeMetrics} from '../../components/VideoPlayer/playbackTelemetry';
import {LOCAL_DEMO_ENABLED} from '../../config/runtime';
import {
  DEMO_COURSE_ID,
  claimDemoCourseCompletionReward,
  claimDemoFirstProjectReward,
  hasDemoCourseAccess,
} from '../../services/demoExperience';
import {friendlyNetworkMessage} from '../../services/networkExperience';
import {hasSession} from '../../services/roknApi';
import {
  buildAccessibleFeed,
  buildPreviewFeed,
  type ReelsRouteParams,
} from './presentation';

type CourseLoaderRefs = {
  closedPlaybackSessions: MutableRefObject<Set<string>>;
  demoRewardsEnabled: MutableRefObject<boolean>;
  loadRequest: MutableRefObject<number>;
  loadedCourse: MutableRefObject<CourseLearningData | null>;
  manifestVersions: MutableRefObject<Record<string, number>>;
  pendingInitialIndex: MutableRefObject<number | null>;
  pendingInitialKey: MutableRefObject<string | null>;
  playbackDurations: MutableRefObject<Record<string, number>>;
  playbackRuntime: MutableRefObject<Record<string, PlaybackRuntimeMetrics>>;
  positions: MutableRefObject<Record<string, number>>;
};

type CourseDetailsNavigation = {
  replace: (screen: 'CourseDetails', params: Record<string, unknown>) => void;
};

export const useReelsCourseLoader = ({
  navigation,
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
  navigation: CourseDetailsNavigation;
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
  useCallback(async () => {
    const requestId = ++refs.loadRequest.current;
    const requestedCourseId = String(
      params.courseId || (LOCAL_DEMO_ENABLED ? DEMO_COURSE_ID : ''),
    );
    const hasCurrentCourse =
      refs.loadedCourse.current?.id === requestedCourseId;
    if (!hasCurrentCourse) {
      refs.closedPlaybackSessions.current.clear();
      refs.playbackRuntime.current = {};
      refs.playbackDurations.current = {};
      refs.manifestVersions.current = {};
      refs.loadedCourse.current = null;
      setCourse(null);
      setLoading(true);
      setLoadError('');
    }
    setPreviewGateVisible(false);
    if (
      LOCAL_DEMO_ENABLED &&
      requestedCourseId.startsWith('demo') &&
      !previewMode &&
      !(await hasDemoCourseAccess(requestedCourseId))
    ) {
      if (requestId !== refs.loadRequest.current) return;
      setLoading(false);
      navigation.replace('CourseDetails', {courseId: requestedCourseId});
      return;
    }
    try {
      const result = await loadCourseLearningData(
        requestedCourseId || undefined,
      );
      if (requestId !== refs.loadRequest.current) return;
      const [withLocalState, localState, sessionAvailable] = await Promise.all([
        applyLocalLearningState(result.course),
        getLocalLearningState(),
        hasSession(),
      ]);
      if (requestId !== refs.loadRequest.current) return;
      setServerSession(sessionAvailable);
      refs.demoRewardsEnabled.current =
        !sessionAvailable && withLocalState.id.startsWith('demo');
      if (refs.demoRewardsEnabled.current) {
        const passedProjects = withLocalState.modules
          .map(module => module.project)
          .filter(project => project?.status === 'passed');
        if (passedProjects[0]) {
          void claimDemoFirstProjectReward(passedProjects[0].id).catch(
            () => undefined,
          );
        }
        const finalProject = [...withLocalState.modules]
          .reverse()
          .find(module => module.project)?.project;
        if (finalProject?.status === 'passed') {
          void claimDemoCourseCompletionReward(withLocalState.id).catch(
            () => undefined,
          );
        }
      }
      refs.positions.current = localState.positions;
      setSavedLessons(new Set(localState.savedLessons));
      refs.loadedCourse.current = withLocalState;
      setCourse(withLocalState);
      const requestedReel = params.reelId || params.lessonId;
      const requestedPosition = Number(params.initialPositionSeconds);
      if (
        requestedReel &&
        Number.isFinite(requestedPosition) &&
        requestedPosition > 0
      ) {
        refs.positions.current[
          `${withLocalState.id}:${String(requestedReel)}`
        ] = requestedPosition;
      }
      refs.pendingInitialKey.current = requestedReel
        ? `reel-${String(requestedReel)}`
        : null;
      const requestedIndex = Number(params.initialReelIndex);
      const accessibleItems = previewMode
        ? buildPreviewFeed(withLocalState, Number(params.previewCount) || 0)
        : buildAccessibleFeed(withLocalState);
      const firstPendingIndex = accessibleItems.findIndex(item =>
        item.type === 'project'
          ? item.project.status !== 'passed'
          : !item.reel.isCompleted,
      );
      refs.pendingInitialIndex.current =
        !requestedReel && Number.isFinite(requestedIndex)
          ? Math.max(0, Math.floor(requestedIndex))
          : !requestedReel && accessibleItems.length
          ? firstPendingIndex >= 0
            ? firstPendingIndex
            : accessibleItems.length - 1
          : null;
    } catch (error) {
      if (requestId !== refs.loadRequest.current) return;
      if (hasCurrentCourse) {
        setConnectionNote(friendlyNetworkMessage(error, 'الفيديو'));
      } else {
        refs.loadedCourse.current = null;
        setCourse(null);
        setLoadError(
          'لم نتمكن من فتح محتوى الكورس الآن. مكانك محفوظ؛ تأكد من الاتصال وحاول مجددًا.',
        );
      }
    } finally {
      if (requestId === refs.loadRequest.current) setLoading(false);
    }
  }, [
    navigation,
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
