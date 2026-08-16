import {useCallback, useEffect} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import {
  applyLocalLearningState,
  loadCourseLearningData,
  retryPendingProjectSubmissions,
} from '../../components/VideoPlayer/courseLearningApi';
import type {CourseLearningData} from '../../components/VideoPlayer/types';
import {buildAccessibleFeed} from './presentation';

type ProjectReviewRefs = {
  loadedCourse: MutableRefObject<CourseLearningData | null>;
  mounted: MutableRefObject<boolean>;
  reviewWatcher: MutableRefObject<number>;
  watchedProject: MutableRefObject<string | null>;
};

export const useProjectReview = ({
  course,
  previewMode,
  refs,
  setCourse,
}: {
  course: CourseLearningData | null;
  previewMode: boolean;
  refs: ProjectReviewRefs;
  setCourse: Dispatch<SetStateAction<CourseLearningData | null>>;
}) => {
  const refreshProjectState = useCallback(
    async (projectId: string) => {
      const activeCourseId = course?.id;
      if (!activeCourseId || activeCourseId.startsWith('demo')) return null;
      try {
        const result = await loadCourseLearningData(activeCourseId, {
          reconcilePending: false,
        });
        const refreshed = await applyLocalLearningState(result.course);
        if (
          !refs.mounted.current ||
          refs.loadedCourse.current?.id !== activeCourseId
        ) {
          return null;
        }
        const project = refreshed.modules
          .map(module => module.project)
          .find(item => item?.id === projectId);
        const refreshedFeed = buildAccessibleFeed(refreshed);
        const projectFeedIndex = refreshedFeed.findIndex(
          item => item.type === 'project' && item.project.id === projectId,
        );
        setCourse(refreshed);
        return {
          status: project?.status || 'not_submitted',
          canContinue:
            projectFeedIndex >= 0 &&
            projectFeedIndex < refreshedFeed.length - 1,
        };
      } catch {
        return null;
      }
    },
    [course?.id, refs, setCourse],
  );

  const watchProjectUntilResolved = useCallback(
    (projectId: string) => {
      if (refs.watchedProject.current === projectId) return;
      refs.watchedProject.current = projectId;
      const watcher = ++refs.reviewWatcher.current;
      void (async () => {
        for (let attempt = 0; attempt < 4; attempt += 1) {
          await new Promise<void>(resolve =>
            setTimeout(resolve, attempt === 0 ? 2500 : 5000),
          );
          if (refs.reviewWatcher.current !== watcher) return;
          await retryPendingProjectSubmissions().catch(() => []);
          if (refs.reviewWatcher.current !== watcher) return;
          const refreshed = await refreshProjectState(projectId);
          if (refs.reviewWatcher.current !== watcher) return;
          if (
            refreshed?.status === 'passed' ||
            refreshed?.status === 'needs_retry'
          ) {
            refs.watchedProject.current = null;
            return;
          }
        }
        refs.watchedProject.current = null;
      })();
    },
    [refreshProjectState, refs],
  );

  useEffect(() => {
    if (!course || previewMode) return;
    const reviewingProject = course.modules
      .map(module => module.project)
      .find(project => project?.status === 'reviewing');
    if (reviewingProject) watchProjectUntilResolved(reviewingProject.id);
  }, [course, previewMode, watchProjectUntilResolved]);

  return {refreshProjectState, watchProjectUntilResolved};
};
