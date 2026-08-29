import AsyncStorage from '@react-native-async-storage/async-storage';
import {accountScopedStorageKey} from '../../constants/helpers';
import {publicRequest} from '../../constants/api';
import type {DemoCourse} from '../../data/demoContent';
import {getLearningCourses} from './courses';
import {payload, resourceList} from './common';

type EarnedBadgeDto = {
  id?: unknown;
  level_id?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  badge_image?: unknown;
  course_id?: unknown;
  course_name_ar?: unknown;
  course_name_en?: unknown;
  track?: unknown;
  earned_at?: unknown;
};

type ProfileLearningDto = {earned_badges?: unknown};
type StreakDayDto = {has_streak?: unknown; date?: unknown};
type StreakDto = {week?: {days?: unknown}};

type LearningPathLevelDto = {
  id?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  badge_image_url?: unknown;
  order?: unknown;
};

type LearningPathDto = {
  path?: {id?: unknown; title?: unknown; title_ar?: unknown; title_en?: unknown};
  current_level?: LearningPathLevelDto | null;
  next_level?: LearningPathLevelDto | null;
  levels?: unknown;
  progress_percentage?: unknown;
  required_progress_percentage?: unknown;
  completed_sections?: unknown;
  total_sections?: unknown;
};

type SavedFolderDto = {id?: unknown; name?: unknown};
type SavedLessonDto = {
  id?: unknown;
  folder_memberships?: unknown;
  course?: {id?: unknown; title?: unknown; image?: unknown};
  course_id?: unknown;
  title?: unknown;
  duration_minutes?: unknown;
  image?: unknown;
};

type SavedLessonsPayloadDto = {
  lessons?: unknown;
  pagination?: {
    current_page?: unknown;
    last_page?: unknown;
    total?: unknown;
  };
};

const SAVED_LESSONS_CACHE_KEY = '@rokn/saved-lessons/v1';

export type LearningCourse = {
  id: string;
  title: string;
  imageUrl?: string;
  progress: number;
  completedSections: number;
  totalSections: number;
  category: DemoCourse['category'];
  lastLessonId?: string;
  lastLessonTitle?: string;
  nextLessonId?: string;
  nextLessonTitle?: string;
  lastWatchedAt?: string;
};

export type LearningDashboard = {
  courses: LearningCourse[];
  paths: LearningPathProgress[];
  badges: Array<{
    id: string;
    levelId?: string;
    title: string;
    imageUrl?: string;
    courseId?: string;
    courseTitle?: string;
    track?: string;
    earnedAt?: string;
  }>;
  activityDays: string[];
};

export type LearningPathLevel = {
  id: string;
  name: string;
  imageUrl?: string;
  order: number;
};

export type LearningPathProgress = {
  id: string;
  title: string;
  currentLevel?: LearningPathLevel;
  nextLevel?: LearningPathLevel;
  upcomingLevels: LearningPathLevel[];
  progress: number;
  remainingToNextLevel: number;
  completedSections: number;
  totalSections: number;
};

const mapPathLevel = (
  level?: LearningPathLevelDto | null,
): LearningPathLevel | undefined => {
  if (level?.id === null || level?.id === undefined) return undefined;
  return {
    id: String(level.id),
    name: String(level.name_ar || level.name_en || 'المستوى التالي'),
    imageUrl: level.badge_image_url
      ? String(level.badge_image_url)
      : undefined,
    order: Math.max(0, Number(level.order) || 0),
  };
};

const getLearningPaths = async (): Promise<LearningPathProgress[]> => {
  const data = payload<LearningPathDto[]>(
    await publicRequest.get('user/paths'),
  );
  return resourceList<LearningPathDto>(data).flatMap(item => {
    if (item.path?.id === null || item.path?.id === undefined) return [];
    return [
      {
        id: String(item.path.id),
        title: String(
          item.path.title ||
            item.path.title_ar ||
            item.path.title_en ||
            'مسارك المهني',
        ),
        currentLevel: mapPathLevel(item.current_level),
        nextLevel: mapPathLevel(item.next_level),
        upcomingLevels: resourceList<LearningPathLevelDto>(item.levels)
          .map(mapPathLevel)
          .filter((level): level is LearningPathLevel => Boolean(level)),
        progress: Math.min(
          100,
          Math.max(0, Number(item.progress_percentage) || 0),
        ),
        remainingToNextLevel: Math.min(
          100,
          Math.max(0, Number(item.required_progress_percentage) || 0),
        ),
        completedSections: Math.max(
          0,
          Number(item.completed_sections) || 0,
        ),
        totalSections: Math.max(0, Number(item.total_sections) || 0),
      },
    ];
  });
};

const LEARNING_DASHBOARD_CACHE = '@rokn/learning-dashboard/v1';

export const getCachedLearningDashboard = async () => {
  try {
    const raw = await AsyncStorage.getItem(
      await accountScopedStorageKey(LEARNING_DASHBOARD_CACHE),
    );
    return raw ? (JSON.parse(raw) as LearningDashboard) : null;
  } catch {
    return null;
  }
};

export const getLearningDashboard = async (): Promise<LearningDashboard> => {
  const [profileResult, streakResult, learningResult, pathsResult] =
    await Promise.allSettled([
      publicRequest.get('user/profile'),
      publicRequest.get('streaks'),
      getLearningCourses(),
      getLearningPaths(),
    ]);
  if (profileResult.status === 'rejected') throw profileResult.reason;
  if (learningResult.status === 'rejected') throw learningResult.reason;
  const profile = payload<ProfileLearningDto>(profileResult.value);
  const streak =
    streakResult.status === 'fulfilled'
      ? payload<StreakDto>(streakResult.value)
      : {};
  const dashboard: LearningDashboard = {
    courses: learningResult.value,
    paths: pathsResult.status === 'fulfilled' ? pathsResult.value : [],
    badges: resourceList<EarnedBadgeDto>(profile.earned_badges).map(badge => ({
      id: String(badge.id),
      levelId: badge.level_id ? String(badge.level_id) : undefined,
      title: String(badge.name_ar || badge.name_en || 'شارة مهنية'),
      imageUrl: badge.badge_image ? String(badge.badge_image) : undefined,
      courseId: badge.course_id ? String(badge.course_id) : undefined,
      courseTitle:
        badge.course_name_ar || badge.course_name_en
          ? String(badge.course_name_ar || badge.course_name_en)
          : undefined,
      track: badge.track ? String(badge.track) : undefined,
      earnedAt: badge.earned_at ? String(badge.earned_at) : undefined,
    })),
    activityDays: resourceList<StreakDayDto>(streak.week?.days)
      .filter(day => Boolean(day?.has_streak) && typeof day?.date === 'string')
      .map(day => String(day.date)),
  };
  // Keep a bounded metadata snapshot; media is never stored here.
  await AsyncStorage.setItem(
    await accountScopedStorageKey(LEARNING_DASHBOARD_CACHE),
    JSON.stringify({...dashboard, courses: dashboard.courses.slice(0, 12)}),
  );
  return dashboard;
};

export type SavedLesson = {
  id: string;
  folderId: string;
  folderName: string;
  courseId: string;
  title: string;
  courseTitle: string;
  duration: string;
  imageUrl?: string;
};

export type SavedLessonsPage = {
  lessons: SavedLesson[];
  page: number;
  hasMore: boolean;
  total: number;
  fromCache: boolean;
};

const savedLessonsCacheKey = () =>
  accountScopedStorageKey(SAVED_LESSONS_CACHE_KEY);

const readSavedLessonsCache = async (): Promise<SavedLesson[] | null> => {
  try {
    const raw = await AsyncStorage.getItem(await savedLessonsCacheKey());
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : null;
  } catch {
    return null;
  }
};

export const getSavedLessonsPage = async (
  page = 1,
  perPage = 20,
): Promise<SavedLessonsPage> => {
  const safePage = Math.max(1, Math.floor(page));
  const safePerPage = Math.min(50, Math.max(1, Math.floor(perPage)));
  try {
    const data = payload<SavedLessonsPayloadDto>(
      await publicRequest.get('saved-lessons', {
        params: {page: safePage, per_page: safePerPage},
      }),
    );
    const lessons = resourceList<SavedLessonDto>(data.lessons).flatMap(
      lesson => {
        if (lesson?.id === null || lesson?.id === undefined) return [];
        return resourceList<SavedFolderDto>(lesson.folder_memberships)
          .filter(folder => folder?.id !== null && folder?.id !== undefined)
          .map(folder => ({
            id: String(lesson.id),
            folderId: String(folder.id),
            folderName: String(folder.name || 'المشاهدة لاحقًا'),
            courseId: String(lesson.course?.id || lesson.course_id || ''),
            title: String(lesson.title || 'خطوة محفوظة'),
            courseTitle: String(lesson.course?.title || 'كورس ركن'),
            duration: `${String(
              Math.floor(Number(lesson.duration_minutes || 0)),
            ).padStart(2, '0')}:00`,
            imageUrl:
              lesson.image || lesson.course?.image
                ? String(lesson.image || lesson.course?.image)
                : undefined,
          }));
      },
    );
    const pagination = data?.pagination || {};
    const currentPage = Math.max(
      1,
      Number(pagination.current_page ?? safePage) || safePage,
    );
    const lastPage = Math.max(
      currentPage,
      Number(pagination.last_page ?? currentPage) || currentPage,
    );
    if (currentPage === 1) {
      await AsyncStorage.setItem(
        await savedLessonsCacheKey(),
        JSON.stringify(lessons),
      ).catch(() => undefined);
    }
    return {
      lessons,
      page: currentPage,
      hasMore: currentPage < lastPage,
      total: Math.max(0, Number(pagination.total ?? lessons.length) || 0),
      fromCache: false,
    };
  } catch (error) {
    // Only page one has an offline snapshot; later-page failures remain errors.
    const cached = safePage === 1 ? await readSavedLessonsCache() : null;
    if (cached) {
      return {
        lessons: cached,
        page: 1,
        hasMore: false,
        total: cached.length,
        fromCache: true,
      };
    }
    throw error;
  }
};

/** First-page convenience wrapper. */
export const getSavedLessons = async (): Promise<SavedLesson[]> =>
  (await getSavedLessonsPage()).lessons;

export const deleteSavedLesson = async (folderId: string, lessonId: string) => {
  const response = await publicRequest.delete(
    `saved-folders/${folderId}/lessons/${lessonId}`,
  );
  const cached = await readSavedLessonsCache();
  if (cached) {
    await AsyncStorage.setItem(
      await savedLessonsCacheKey(),
      JSON.stringify(
        cached.filter(
          lesson => lesson.folderId !== folderId || lesson.id !== lessonId,
        ),
      ),
    ).catch(() => undefined);
  }
  return response;
};

export type ProductionLearningCourse = LearningCourse;
export type ProductionLearningDashboard = LearningDashboard;
export type ProductionSavedLesson = SavedLesson;
export type ProductionSavedLessonsPage = SavedLessonsPage;
export const getCachedProductionLearningDashboard = getCachedLearningDashboard;
export const getProductionLearningDashboard = getLearningDashboard;
export const getProductionSavedLessonsPage = getSavedLessonsPage;
export const getProductionSavedLessons = getSavedLessons;
export const deleteProductionSavedLesson = deleteSavedLesson;
