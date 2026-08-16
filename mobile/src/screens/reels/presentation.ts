import type {
  CourseFeedItem,
  CourseLearningData,
  VideoQuality,
} from '../../components/VideoPlayer/types';
export type {ReelsRouteParams} from '../../navigation/types';

export const PLAYBACK_PREFERENCE_BITRATE_KBPS: Record<
  VideoQuality,
  number | undefined
> = {
  auto: undefined,
  '1080p': 5000,
  '720p': 2800,
  '480p': 1300,
  '360p': 750,
};

export const buildAccessibleFeed = (
  course: CourseLearningData,
): CourseFeedItem[] => {
  const items: CourseFeedItem[] = [];
  for (const module of course.modules) {
    if (module.isLocked) break;

    let reachedLockedReel = false;
    for (const reel of module.reels) {
      if (reel.isLocked || !reel.videoUrl.trim()) {
        reachedLockedReel = true;
        break;
      }
      items.push({
        key: `reel-${reel.id}`,
        type: 'reel',
        moduleId: module.id,
        reel,
      });
    }
    if (reachedLockedReel) break;

    if (module.project) {
      const lastReel = module.reels[module.reels.length - 1];
      if (lastReel && !lastReel.isCompleted) break;

      items.push({
        key: `project-${module.project.id}`,
        type: 'project',
        moduleId: module.id,
        project: module.project,
      });
      if (module.project.status !== 'passed') break;
    }
  }
  return items;
};

export const buildPreviewFeed = (
  course: CourseLearningData,
  fallbackCount = 0,
): CourseFeedItem[] => {
  const allReels = course.modules
    .flatMap(module => module.reels.map(reel => ({moduleId: module.id, reel})))
    .filter(item => item.reel.videoUrl.trim());
  const markedPreviewReels = allReels.filter(item => item.reel.isPreview);
  const previewReels = markedPreviewReels.length
    ? markedPreviewReels
    : allReels.slice(0, Math.max(0, fallbackCount));

  return previewReels.map(({moduleId, reel}) => ({
    key: `reel-${reel.id}`,
    type: 'reel' as const,
    moduleId,
    reel: {...reel, isLocked: false},
  }));
};

export const updateProjectStatusOnly = (
  course: CourseLearningData,
  projectId: string,
  status: 'reviewing' | 'passed' | 'needs_retry',
): CourseLearningData => ({
  ...course,
  modules: course.modules.map(module => ({
    ...module,
    project:
      module.project?.id === projectId
        ? {...module.project, status}
        : module.project,
  })),
});

export const resolveReelsFrameWidth = ({
  width,
  height,
}: {
  width: number;
  height: number;
}) => {
  if (!width || !height) return 0;
  if (width < 700) return width;
  return Math.min(width, 620, Math.round(height * 0.625));
};
