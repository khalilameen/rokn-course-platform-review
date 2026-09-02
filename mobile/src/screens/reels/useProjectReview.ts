import {useCallback, useEffect} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import {
  applyLocalLearningState,
  loadCourseLearningData,
  retryPendingProjectSubmissions,
} from '../../components/VideoPlayer/courseLearningApi';
import type {CourseLearningData} from '../../components/VideoPlayer/types';
import {buildAccessibleFeed} from './presentation';
import {useAppActiveState} from '../../hooks/useAppActiveState';
import {isLocalDemoId} from '../../config/runtime';
import {watchProjectResolution} from '../../components/VideoPlayer/courseLearning/projects';

type ProjectReviewRefs = {
  loadedCourse: MutableRefObject<CourseLearningData | null>;
  mounted: MutableRefObject<boolean>;
  ownerGeneration: MutableRefObject<number>;
  reviewWatcher: MutableRefObject<number>;
  watchedProject: MutableRefObject<string | null>;
};

export const useProjectReview = ({
  active,
  course,
  previewMode,
  refs,
  setCourse,
}: {
  active: boolean;
  course: CourseLearningData | null;
  previewMode: boolean;
  refs: ProjectReviewRefs;
  setCourse: Dispatch<SetStateAction<CourseLearningData | null>>;
}) => {
  const appIsActive = useAppActiveState();
  const reviewActive = active && appIsActive;
  const refreshProjectState = useCallback(
    async (projectId: string) => {
      const activeCourseId = course?.id;
      const ownerGeneration = refs.ownerGeneration.current;
      if (!activeCourseId || isLocalDemoId(activeCourseId)) return null;
      try {
        const result = await loadCourseLearningData(activeCourseId, {
          reconcilePending: false,
        });
        const refreshed = await applyLocalLearningState(result.course);
        if (
          !refs.mounted.current ||
          refs.ownerGeneration.current !== ownerGeneration ||
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
      if (!reviewActive) return;
      if (refs.watchedProject.current === projectId) return;
      refs.watchedProject.current = projectId;
      const watcher = ++refs.reviewWatcher.current;
      watchProjectResolution({
        projectId,
        resolve: refreshProjectState,
        beforeResolve: () => retryPendingProjectSubmissions().catch(() => []),
        isActive: () => reviewActive && refs.reviewWatcher.current === watcher,
        initialDelayMs: 2500,
        onExhausted: () => {
          if (refs.reviewWatcher.current === watcher) {
            refs.watchedProject.current = null;
          }
        },
        onResolution: refreshed => {
          if (refreshed.status === 'passed' || refreshed.status === 'needs_retry') {
            refs.watchedProject.current = null;
          }
        },
      });
    },
    [refreshProjectState, refs, reviewActive],
  );

  useEffect(() => {
    if (reviewActive) return;
    refs.reviewWatcher.current += 1;
    refs.watchedProject.current = null;
  }, [refs, reviewActive]);

  useEffect(() => {
    if (!course || previewMode || !reviewActive) return;
    const reviewingProject = course.modules
      .map(module => module.project)
      .find(project => project?.status === 'reviewing');
    if (reviewingProject) watchProjectUntilResolved(reviewingProject.id);
  }, [course, previewMode, reviewActive, watchProjectUntilResolved]);

  return {refreshProjectState, watchProjectUntilResolved};
};
