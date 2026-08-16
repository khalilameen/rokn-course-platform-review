import {createDemoCourse} from './demoCourse';
import {VideoQuality} from './types';

/**
 * Legacy shape kept for screens that may still import VIDEO_DATA in older builds.
 * The active player uses CourseLearningData and never creates a discovery feed.
 */
export interface VideoData {
  id: number;
  videoUrl: string;
  videoId?: string;
  libraryId?: string;
  thumbnailUrl: string;
  title: string;
  description: string;
  availableQualities?: VideoQuality[];
  friends?: Array<{imageUrl: string}>;
}

export const VIDEO_DATA: VideoData[] = createDemoCourse().modules.flatMap(
  module =>
    module.reels.map(reel => ({
      id: reel.reelNumber,
      videoUrl: reel.videoUrl,
      thumbnailUrl: reel.thumbnailUrl || '',
      title: reel.title,
      description: reel.caption,
      availableQualities: reel.availableQualities,
    })),
);
