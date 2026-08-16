import type {
  CourseLearningData,
  CourseReel,
} from '../../components/VideoPlayer/types';
import type {PlaybackManifest} from '../../components/VideoPlayer/courseLearningApi';

type ManifestUpdate = {
  courseId?: string;
  lessonId: string;
  expectedSessionId?: string;
  manifest: PlaybackManifest;
  revision: number;
};

export const applyPlaybackManifest = (
  course: CourseLearningData | null,
  update: ManifestUpdate,
): CourseLearningData | null => {
  if (!course || course.id !== update.courseId) return course;
  let changed = false;
  const modules = course.modules.map(module => ({
    ...module,
    reels: module.reels.map(existing => {
      if (existing.lessonId !== update.lessonId) return existing;
      const sessionIsCurrent = update.expectedSessionId
        ? existing.playbackSessionId === update.expectedSessionId
        : !existing.playbackSessionId;
      if (!sessionIsCurrent) return existing;
      changed = true;
      return {
        ...existing,
        videoUrl: update.manifest.sourceUrl,
        fallbackVideoUrl:
          update.manifest.fallbackUrl || existing.fallbackVideoUrl,
        qualitySources: update.manifest.qualitySources,
        availableQualities: update.manifest.availableQualities,
        durationSeconds:
          update.manifest.durationSeconds || existing.durationSeconds,
        playbackSessionId: update.manifest.playbackSessionId,
        playbackProtocol: update.manifest.protocol,
        playbackExpiresAt: update.manifest.expiresAt,
        playbackRefreshAfter: update.manifest.refreshAfter,
        playbackManifestRevision: update.revision,
        mediaStatus: update.manifest.mediaStatus,
      } satisfies CourseReel;
    }),
  }));
  return changed ? {...course, modules} : course;
};

export const disableLessonPlayback = (
  course: CourseLearningData | null,
  courseId: string | undefined,
  lessonId: string,
): CourseLearningData | null => {
  if (!course || course.id !== courseId) return course;
  return {
    ...course,
    modules: course.modules.map(module => ({
      ...module,
      reels: module.reels.map(existing =>
        existing.lessonId === lessonId
          ? {
              ...existing,
              videoUrl: '',
              fallbackVideoUrl: undefined,
              playbackSessionId: undefined,
            }
          : existing,
      ),
    })),
  };
};

export const playbackFeatureErrorCode = (error: unknown): string => {
  if (!error || typeof error !== 'object' || !('code' in error)) return '';
  return String(error.code || '');
};
