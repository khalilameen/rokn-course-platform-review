import type {PlaybackEvidenceContext} from '../../components/VideoPlayer/courseLearningApi';
import type {PlaybackRuntimeMetrics} from '../../components/VideoPlayer/playbackTelemetry';
import type {
  CourseLearningData,
  CourseReel,
} from '../../components/VideoPlayer/types';

export const buildPlaybackEvidence = (
  reel: Pick<CourseReel, 'playbackSessionId'>,
  runtime: PlaybackRuntimeMetrics | undefined,
  playbackRate: number,
): PlaybackEvidenceContext => ({
  playbackSessionId: reel.playbackSessionId,
  effectiveQuality: runtime?.effectiveQuality,
  effectiveBitrateKbps: runtime?.effectiveBitrateKbps,
  playbackRate,
  recoveryCount: runtime?.recoveryCount,
  bufferCount: runtime?.bufferCount,
  bufferDurationMs: runtime?.bufferDurationMs,
  startupLatencyMs: runtime?.startupLatencyMs,
  diagnostics: runtime?.diagnostics,
});

export const nextLearningTitle = (
  course: CourseLearningData,
  reel: CourseReel,
) => {
  const moduleIndex = course.modules.findIndex(
    module => module.id === reel.moduleId,
  );
  const module = course.modules[moduleIndex];
  const reelIndex = module?.reels.findIndex(item => item.id === reel.id) ?? -1;
  const firstPendingQuiz = (module?.quizzes || []).find(quiz => !quiz.passed);
  const projects = module?.projects?.length
    ? module.projects
    : module?.project
    ? [module.project]
    : [];
  const firstPendingProject = projects.find(project => project.status !== 'passed');
  return (
    module?.reels[reelIndex + 1]?.title ||
    (reelIndex === module?.reels.length - 1
      ? firstPendingQuiz?.title ||
        (firstPendingProject
          ? `مشروع العبور\n${firstPendingProject.title}`
          : course.modules[moduleIndex + 1]?.reels[0]?.title)
      : undefined)
  );
};

export const markReelCompleted = (
  course: CourseLearningData,
  reel: CourseReel,
): CourseLearningData => {
  const moduleIndex = course.modules.findIndex(
    module => module.id === reel.moduleId,
  );
  const activeModule = course.modules[moduleIndex];
  const reelIndex = activeModule?.reels.findIndex(item => item.id === reel.id);
  const projects = activeModule?.projects?.length
    ? activeModule.projects
    : activeModule?.project
    ? [activeModule.project]
    : [];
  const unlockFollowingModule =
    reelIndex === activeModule?.reels.length - 1 &&
    !projects.length &&
    !(activeModule?.quizzes || []).length;

  return {
    ...course,
    modules: course.modules.map((module, index) => {
      if (module.id === reel.moduleId) {
        return {
          ...module,
          reels: module.reels.map((item, itemIndex) =>
            item.id === reel.id
              ? {...item, isCompleted: true}
              : itemIndex === reelIndex + 1
              ? {...item, isLocked: false, lockReason: undefined}
              : item,
          ),
          projects:
            itemIsLastReel(reelIndex, module.reels.length) &&
            !(module.quizzes || []).length
              ? projects.map((project, projectIndex) =>
                  projectIndex === 0
                    ? {...project, isLocked: false, lockReason: undefined}
                    : project,
                )
              : module.projects,
          project:
            itemIsLastReel(reelIndex, module.reels.length) &&
            !(module.quizzes || []).length && module.project
              ? {...module.project, isLocked: false, lockReason: undefined}
              : module.project,
        };
      }
      if (unlockFollowingModule && index === moduleIndex + 1) {
        return {
          ...module,
          isLocked: false,
          reels: module.reels.map((item, itemIndex) =>
            itemIndex === 0
              ? {...item, isLocked: false, lockReason: undefined}
              : item,
          ),
        };
      }
      return module;
    }),
  };
};

const itemIsLastReel = (index: number | undefined, length: number): boolean =>
  typeof index === 'number' && index >= 0 && index === length - 1;
