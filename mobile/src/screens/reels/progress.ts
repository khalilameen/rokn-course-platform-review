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
  return (
    module?.reels[reelIndex + 1]?.title ||
    (module?.project && reelIndex === module.reels.length - 1
      ? `مشروع العبور: ${module.project.title}`
      : course.modules[moduleIndex + 1]?.reels[0]?.title)
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
  const unlockFollowingModule =
    reelIndex === activeModule?.reels.length - 1 && !activeModule?.project;

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
              ? {...item, isLocked: !item.videoUrl.trim()}
              : item,
          ),
        };
      }
      if (unlockFollowingModule && index === moduleIndex + 1) {
        return {
          ...module,
          isLocked: false,
          reels: module.reels.map((item, itemIndex) =>
            itemIndex === 0 ? {...item, isLocked: !item.videoUrl.trim()} : item,
          ),
        };
      }
      return module;
    }),
  };
};
