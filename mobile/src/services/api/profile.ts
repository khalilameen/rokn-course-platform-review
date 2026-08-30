import {publicRequest} from '../../constants/api';
import {ApiRecord, payload, resourceList} from './common';

type WatchHistoryDto = ApiRecord;
type PortfolioMediaDto = ApiRecord & {file_type?: unknown; image_url?: unknown};
type PortfolioCourseDto = {name?: unknown; id?: unknown; image?: unknown};
type PortfolioItemDto = ApiRecord & {
  media?: unknown;
  course?: PortfolioCourseDto;
};
type EligibleProjectDto = ApiRecord & {
  course?: {id?: unknown; title?: unknown; title_en?: unknown; image?: unknown};
  module?: {title?: unknown};
};

const hasEligibleCourse = (
  item: EligibleProjectDto,
): item is EligibleProjectDto & {
  course: NonNullable<EligibleProjectDto['course']>;
} => Boolean(item.project_id && item.course?.id);

export type Profile = {
  id: string;
  name: string;
  email: string;
  jobTitle: string;
  avatar?: string;
  watchHistoryEnabled: boolean;
  marketingNotificationsEnabled: boolean;
  autoplayNextEnabled: boolean;
  videoQualityPreference: string;
  videoFitMode: 'cover' | 'contain';
  playbackSpeed: number;
};

export type PortfolioProfile = {
  slug: string;
  headline: string;
  location: string;
  skills: string[];
  publicUrl: string;
  shareMode: 'unlisted';
};

export type PortfolioItem = {
  id: string;
  title: string;
  summary: string;
  coverUri?: string;
  skills: string[];
  courseName?: string;
  courseId?: string;
  courseImage?: string;
  sourceProjectId?: string;
  featured: boolean;
};

export type EligibleProject = {
  projectId: string;
  courseId: string;
  title: string;
  summary: string;
  courseName: string;
  courseImage?: string;
  moduleName?: string;
  passedAt?: string;
};

export const getProfile = async (): Promise<Profile> => {
  const data = payload(await publicRequest.get('user/profile'));
  return {
    id: String(data.id),
    name: String(data.name || 'طالب ركن'),
    email: String(data.email || ''),
    jobTitle: String(data.job_title || ''),
    avatar:
      data.profile_image || data.image
        ? String(data.profile_image || data.image)
        : undefined,
    watchHistoryEnabled: data.watch_history_enabled !== false,
    marketingNotificationsEnabled: Boolean(
      data.marketing_notifications_enabled,
    ),
    autoplayNextEnabled: data.autoplay_next_enabled !== false,
    videoQualityPreference: String(data.video_quality_preference || 'auto'),
    videoFitMode: data.video_fit_mode === 'contain' ? 'contain' : 'cover',
    playbackSpeed: Number(data.playback_speed || 1),
  };
};

export const getPortfolioProfile = async (): Promise<PortfolioProfile> => {
  const data = payload(await publicRequest.get('portfolio-profile'));
  return {
    slug: String(data.slug || ''),
    headline: String(data.headline || ''),
    location: String(data.location || ''),
    skills: Array.isArray(data.skills) ? data.skills.map(String) : [],
    publicUrl: String(data.public_url || ''),
    shareMode: 'unlisted',
  };
};

export const updateProfile = async ({
  name,
  jobTitle,
  avatar,
}: {
  name: string;
  jobTitle: string;
  avatar?: {uri: string; type?: string; fileName?: string};
}): Promise<Profile> => {
  const form = new FormData();
  form.append('name', name);
  form.append('job_title', jobTitle);
  if (avatar?.uri) {
    form.append('profile_image', {
      uri: avatar.uri,
      type: avatar.type || 'image/jpeg',
      name: avatar.fileName || `profile-${Date.now()}.jpg`,
    } as unknown as Blob);
  }
  // PHP does not reliably populate multipart file uploads for a raw PUT.
  // Preserve the deployed POST alias during the API transition.
  const data = payload(await publicRequest.post('update_profile', form));
  return {
    id: String(data.id),
    name: String(data.name || name),
    email: String(data.email || ''),
    jobTitle: String(data.job_title || jobTitle),
    avatar:
      data.profile_image || data.image
        ? String(data.profile_image || data.image)
        : avatar?.uri,
    watchHistoryEnabled: data.watch_history_enabled !== false,
    marketingNotificationsEnabled: Boolean(
      data.marketing_notifications_enabled,
    ),
    autoplayNextEnabled: data.autoplay_next_enabled !== false,
    videoQualityPreference: String(data.video_quality_preference || 'auto'),
    videoFitMode: data.video_fit_mode === 'contain' ? 'contain' : 'cover',
    playbackSpeed: Number(data.playback_speed || 1),
  };
};

/** Sync the push preference while callers retain their local offline setting. */
export const updateNotificationStatus = async (
  enabled: boolean,
): Promise<boolean> => {
  const data = payload(
    await publicRequest.put('user/profile', {notifications_status: enabled}),
  );
  return Boolean(data.notifications_status);
};

export const updatePrivacyPreferences = async ({
  watchHistoryEnabled,
  marketingNotificationsEnabled,
}: {
  watchHistoryEnabled?: boolean;
  marketingNotificationsEnabled?: boolean;
}): Promise<void> => {
  const body: Record<string, boolean> = {};
  if (typeof watchHistoryEnabled === 'boolean') {
    body.watch_history_enabled = watchHistoryEnabled;
  }
  if (typeof marketingNotificationsEnabled === 'boolean') {
    body.marketing_notifications_enabled = marketingNotificationsEnabled;
  }
  if (Object.keys(body).length) {
    await publicRequest.put('user/profile', body);
  }
};

export const updatePlaybackPreferences = async ({
  autoplayNextEnabled,
  videoQualityPreference,
  videoFitMode,
  playbackSpeed,
}: {
  autoplayNextEnabled?: boolean;
  videoQualityPreference?: string;
  videoFitMode?: 'cover' | 'contain';
  playbackSpeed?: number;
}): Promise<void> => {
  const body: Record<string, boolean | number | string> = {};
  if (typeof autoplayNextEnabled === 'boolean') {
    body.autoplay_next_enabled = autoplayNextEnabled;
  }
  if (videoQualityPreference) {
    body.video_quality_preference =
      videoQualityPreference === 'data_saver' ? '360p' : videoQualityPreference;
  }
  if (videoFitMode) body.video_fit_mode = videoFitMode;
  if (typeof playbackSpeed === 'number') body.playback_speed = playbackSpeed;
  if (Object.keys(body).length) await publicRequest.put('user/profile', body);
};

export const clearWatchHistory = async (): Promise<void> => {
  await publicRequest.delete('user/watch-history');
};

export type WatchHistoryItem = {
  id: string;
  courseId: string;
  courseTitle: string;
  courseImage?: string;
  lessonId: string;
  lessonTitle: string;
  lessonThumbnail?: string;
  positionSeconds: number;
  durationSeconds?: number;
  progress: number;
  completed: boolean;
  watchedAt?: string;
};

export type WatchHistory = {
  trackingEnabled: boolean;
  items: WatchHistoryItem[];
};

/**
 * Fetch bounded resume metadata. Playback still requires normal course access.
 */
export const getWatchHistory = async (limit = 6): Promise<WatchHistory> => {
  const safeLimit = Math.max(1, Math.min(6, Math.trunc(limit) || 6));
  const data = payload(
    await publicRequest.get('user/watch-history', {
      params: {per_page: safeLimit},
    }),
  );
  const seenLessons = new Set<string>();
  const items: WatchHistoryItem[] = [];

  for (const item of resourceList<WatchHistoryDto>(data.items)) {
    const courseId = item?.course_id;
    const lessonId = item?.lesson_id;
    if (courseId === null || courseId === undefined) continue;
    if (lessonId === null || lessonId === undefined) continue;

    const lessonKey = `${courseId}:${lessonId}`;
    if (seenLessons.has(lessonKey)) continue;
    seenLessons.add(lessonKey);

    const positionSeconds = Math.max(0, Number(item.position_seconds || 0));
    const durationNumber = Number(item.duration_seconds);
    const durationSeconds =
      Number.isFinite(durationNumber) && durationNumber > 0
        ? durationNumber
        : undefined;
    const reportedProgress = Number(item.progress_percentage);
    const calculatedProgress = durationSeconds
      ? (positionSeconds / durationSeconds) * 100
      : 0;
    const progress = Math.max(
      0,
      Math.min(
        100,
        item.is_completed
          ? 100
          : Number.isFinite(reportedProgress)
          ? reportedProgress
          : calculatedProgress,
      ),
    );

    items.push({
      id: String(item.id ?? lessonKey),
      courseId: String(courseId),
      courseTitle: String(
        item.course_title || item.course_title_en || 'كورس ركن',
      ),
      courseImage: item.course_image ? String(item.course_image) : undefined,
      lessonId: String(lessonId),
      lessonTitle: String(item.lesson_title || 'خطوة من الكورس'),
      lessonThumbnail: item.lesson_thumbnail
        ? String(item.lesson_thumbnail)
        : undefined,
      positionSeconds,
      durationSeconds,
      progress,
      completed: Boolean(item.is_completed),
      watchedAt: item.watched_at ? String(item.watched_at) : undefined,
    });

    if (items.length >= safeLimit) break;
  }

  return {
    trackingEnabled: data.tracking_enabled !== false,
    items,
  };
};

export const updatePortfolioProfile = async ({
  slug,
  headline,
}: {
  slug: string;
  headline: string;
}): Promise<PortfolioProfile> => {
  const data = payload(
    await publicRequest.put('portfolio-profile', {
      portfolio_slug: slug,
      portfolio_headline: headline,
    }),
  );
  return {
    slug: String(data.slug || slug),
    headline: String(data.headline || headline),
    location: String(data.location || ''),
    skills: Array.isArray(data.skills) ? data.skills.map(String) : [],
    publicUrl: String(data.public_url || ''),
    shareMode: 'unlisted',
  };
};

export const getPortfolio = async (): Promise<PortfolioItem[]> => {
  const data = payload(await publicRequest.get('portfolio'));
  const items = resourceList<PortfolioItemDto>(data);
  return items
    .filter(item => item.id !== null && item.id !== undefined)
    .map(item => {
      const media = resourceList<PortfolioMediaDto>(item.media);
      const cover = media.find(entry => entry.file_type === 'image');
      return {
        id: String(item.id),
        title: String(item.title || 'مشروع بدون عنوان'),
        summary: String(item.description || ''),
        coverUri: cover?.image_url ? String(cover.image_url) : undefined,
        skills: Array.isArray(item.tools) ? item.tools.map(String) : [],
        courseName: item.course?.name ? String(item.course.name) : undefined,
        courseId: item.course?.id ? String(item.course.id) : undefined,
        courseImage: item.course?.image ? String(item.course.image) : undefined,
        sourceProjectId: item.source_project_id
          ? String(item.source_project_id)
          : undefined,
        featured: Boolean(item.is_featured),
      };
    });
};

export const createPortfolioItem = async ({
  title,
  summary,
  cover,
  sourceProjectId,
  courseId,
}: {
  title: string;
  summary: string;
  cover?: {uri: string; type?: string; fileName?: string};
  sourceProjectId?: string;
  courseId?: string;
}): Promise<PortfolioItem> => {
  const form = new FormData();
  form.append('title', title);
  form.append('description', summary);
  if (sourceProjectId) form.append('source_project_id', sourceProjectId);
  if (courseId) form.append('course_id', courseId);
  if (cover?.uri) {
    form.append('files[]', {
      uri: cover.uri,
      type: cover.type || 'image/jpeg',
      name: cover.fileName || `portfolio-${Date.now()}.jpg`,
    } as unknown as Blob);
    form.append('file_types[]', 'image');
  }
  const data = payload(
    await publicRequest.post('portfolio', form, {
      timeout: 45000,
    }),
  );
  const media = resourceList<PortfolioMediaDto>(data.media);
  const image = media.find(entry => entry.file_type === 'image');
  const course = data.course as PortfolioCourseDto | undefined;
  return {
    id: String(data.id),
    title: String(data.title || title),
    summary: String(data.description || summary),
    coverUri: image?.image_url ? String(image.image_url) : cover?.uri,
    skills: Array.isArray(data.tools) ? data.tools.map(String) : [],
    courseName: course?.name ? String(course.name) : undefined,
    courseId: course?.id ? String(course.id) : courseId,
    courseImage: course?.image ? String(course.image) : undefined,
    sourceProjectId: data.source_project_id
      ? String(data.source_project_id)
      : sourceProjectId,
    featured: Boolean(data.is_featured),
  };
};

export const getEligibleProjects = async (): Promise<EligibleProject[]> => {
  const data = payload(
    await publicRequest.get('portfolio/eligible-projects', {
      params: {per_page: 50},
    }),
  );
  return resourceList<EligibleProjectDto>(data.items)
    .filter(hasEligibleCourse)
    .map(item => ({
      projectId: String(item.project_id),
      courseId: String(item.course.id),
      title: String(item.title || 'مشروع تطبيقي'),
      summary: String(item.requirements || ''),
      courseName: String(
        item.course.title || item.course.title_en || 'كورس ركن',
      ),
      courseImage: item.course.image ? String(item.course.image) : undefined,
      moduleName: item.module?.title ? String(item.module.title) : undefined,
      passedAt: item.passed_at ? String(item.passed_at) : undefined,
    }));
};

export const deletePortfolioItem = (id: string) =>
  publicRequest.delete(`portfolio/${id}`);

export type ProductionProfile = Profile;
export type ProductionPortfolioProfile = PortfolioProfile;
export type ProductionPortfolioItem = PortfolioItem;
export type ProductionEligibleProject = EligibleProject;
export type ProductionWatchHistoryItem = WatchHistoryItem;
export type ProductionWatchHistory = WatchHistory;
export const getProductionProfile = getProfile;
export const getProductionPortfolioProfile = getPortfolioProfile;
export const updateProductionProfile = updateProfile;
export const updateProductionNotificationStatus = updateNotificationStatus;
export const updateProductionPrivacyPreferences = updatePrivacyPreferences;
export const updateProductionPlaybackPreferences = updatePlaybackPreferences;
export const clearProductionWatchHistory = clearWatchHistory;
export const getProductionWatchHistory = getWatchHistory;
export const updateProductionPortfolioProfile = updatePortfolioProfile;
export const getProductionPortfolio = getPortfolio;
export const createProductionPortfolioItem = createPortfolioItem;
export const getProductionEligibleProjects = getEligibleProjects;
export const deleteProductionPortfolioItem = deletePortfolioItem;
