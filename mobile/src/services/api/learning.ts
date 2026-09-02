import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {publicRequest} from '../../constants/api';
import type {DemoCourse} from '../../data/demoContent';
import {getLearningCourses} from './courses';
import {
  firstBoolean,
  isApiRecord,
  isResourceListPayload,
  payload,
  resourceList,
} from './common';
import {isServerTimestampFresh, serverNowMs} from '../../utils/serverClock';

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
type StreakDto = {
  week?: {days?: unknown};
  current_streak?: unknown;
  last_streak_before_gap?: unknown;
};

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
  duration_seconds?: unknown;
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

const SAVED_LESSONS_CACHE_KEY = '@rokn/saved-lessons/v2';
const SAVED_LESSONS_CACHE_TTL_MS = 24 * 60 * 60 * 1000;

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
  nextSectionType?: string;
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
  currentStreakDays: number;
  /** Present when fresh courses arrived but a secondary panel stayed cached. */
  partialError?: string;
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
  const data = payload<unknown>(
    await publicRequest.get('user/paths'),
  );
  if (!isResourceListPayload(data)) {
    throw new Error('LEARNING_PATHS_CONTRACT_INVALID');
  }
  return resourceList<LearningPathDto>(data).flatMap(item => {
    if (
      !isApiRecord(item) ||
      !isApiRecord(item.path) ||
      item.path.id === null ||
      item.path.id === undefined
    ) {
      return [];
    }
    const currentLevel = mapPathLevel(item.current_level);
    const nextLevel = mapPathLevel(item.next_level);
    const seenLevelIds = new Set<string>();
    const upcomingLevels = resourceList<LearningPathLevelDto>(item.levels)
      .map(mapPathLevel)
      .filter((level): level is LearningPathLevel => {
        if (
          !level ||
          level.id === currentLevel?.id ||
          seenLevelIds.has(level.id)
        ) {
          return false;
        }
        seenLevelIds.add(level.id);
        return true;
      })
      .sort((left, right) => left.order - right.order);
    return [
      {
        id: String(item.path.id),
        title: String(
          item.path.title ||
            item.path.title_ar ||
            item.path.title_en ||
            'مسارك المهني',
        ),
        currentLevel,
        nextLevel,
        upcomingLevels,
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

const LEARNING_DASHBOARD_CACHE = '@rokn/learning-dashboard/v2';
const LEARNING_DASHBOARD_CACHE_TTL_MS = 6 * 60 * 60 * 1000;

type LearningDashboardCache = {
  version: 2;
  savedAt: number;
  dashboard: LearningDashboard;
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const normalizeCachedLearningDashboard = (
  value: unknown,
): LearningDashboard | null => {
  if (!isRecord(value)) return null;
  const courses = Array.isArray(value.courses)
    ? value.courses.filter(
        (course): course is LearningCourse =>
          isRecord(course) &&
          typeof course.id === 'string' &&
          course.id.length > 0 &&
          typeof course.title === 'string' &&
          Number.isFinite(course.progress) &&
          Number.isFinite(course.completedSections) &&
          Number.isFinite(course.totalSections),
      )
    : [];
  const paths = Array.isArray(value.paths)
    ? value.paths.filter(
        (path): path is LearningPathProgress =>
          isRecord(path) &&
          typeof path.id === 'string' &&
          path.id.length > 0 &&
          typeof path.title === 'string' &&
          Array.isArray(path.upcomingLevels) &&
          Number.isFinite(path.progress) &&
          Number.isFinite(path.remainingToNextLevel),
      )
    : [];
  const badges = Array.isArray(value.badges)
    ? value.badges.filter(
        (badge): badge is LearningDashboard['badges'][number] =>
          isRecord(badge) &&
          typeof badge.id === 'string' &&
          badge.id.length > 0 &&
          typeof badge.title === 'string',
      )
    : [];
  const activityDays = Array.isArray(value.activityDays)
    ? value.activityDays.filter(
        (day): day is string =>
          typeof day === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(day),
      )
    : [];
  const currentStreakDays = Number(value.currentStreakDays);
  if (!Number.isFinite(currentStreakDays)) return null;
  return {
    courses: courses.slice(0, 100),
    paths: paths.slice(0, 50),
    badges: badges.slice(0, 200),
    activityDays: Array.from(new Set(activityDays)).slice(-31),
    currentStreakDays: Math.max(0, Math.floor(currentStreakDays)),
  };
};

export const getCachedLearningDashboard = async () => {
  const accountBoundary = await captureAccountSessionBoundary();
  try {
    const raw = await AsyncStorage.getItem(
      await accountScopedStorageKey(
        LEARNING_DASHBOARD_CACHE,
        accountBoundary,
      ),
    );
    if (!raw) return null;
    const cached = JSON.parse(raw) as Partial<LearningDashboardCache>;
    if (
      cached.version !== 2 ||
      !isServerTimestampFresh(
        Number(cached.savedAt),
        LEARNING_DASHBOARD_CACHE_TTL_MS,
      )
    ) {
      return null;
    }
    const dashboard = normalizeCachedLearningDashboard(cached.dashboard);
    assertAccountSessionBoundary(accountBoundary);
    return dashboard;
  } catch {
    assertAccountSessionBoundary(accountBoundary);
    return null;
  }
};

export const getLearningDashboard = async (): Promise<LearningDashboard> => {
  const accountBoundary = await captureAccountSessionBoundary();
  const dashboardCacheKey = await accountScopedStorageKey(
    LEARNING_DASHBOARD_CACHE,
    accountBoundary,
  );
  const cachedDashboard = await getCachedLearningDashboard();
  const [profileResult, streakResult, learningResult, pathsResult] =
    await Promise.allSettled([
      publicRequest.get('user/profile', {
        params: {include_learning: 0, include_badges: 1},
      }),
      publicRequest.get('streaks'),
      getLearningCourses(),
      getLearningPaths(),
    ]);
  assertAccountSessionBoundary(accountBoundary);
  if (learningResult.status === 'rejected') throw learningResult.reason;
  const partialFailure = [profileResult, streakResult, pathsResult].some(
    result => result.status === 'rejected',
  );
  const profile =
    profileResult.status === 'fulfilled'
      ? payload<ProfileLearningDto>(profileResult.value)
      : {};
  const streak =
    streakResult.status === 'fulfilled'
      ? payload<StreakDto>(streakResult.value)
      : {};
  const dashboard: LearningDashboard = {
    courses: learningResult.value,
    paths:
      pathsResult.status === 'fulfilled'
        ? pathsResult.value
        : cachedDashboard?.paths || [],
    badges:
      profileResult.status === 'fulfilled'
        ? resourceList<EarnedBadgeDto>(profile.earned_badges).flatMap(
      badge => {
        const id = String(badge.id ?? '').trim();
        if (!id) return [];
        return [
          {
            id,
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
          },
        ];
      },
    )
        : cachedDashboard?.badges || [],
    activityDays:
      streakResult.status === 'fulfilled'
        ? resourceList<StreakDayDto>(streak.week?.days)
            .filter(
              day =>
                firstBoolean(day?.has_streak) === true &&
                typeof day?.date === 'string',
            )
            .map(day => String(day.date))
        : cachedDashboard?.activityDays || [],
    currentStreakDays:
      streakResult.status === 'fulfilled'
        ? Math.max(
            0,
            Number(
              streak.current_streak ?? streak.last_streak_before_gap ?? 0,
            ) || 0,
          )
        : cachedDashboard?.currentStreakDays || 0,
    ...(partialFailure
      ? {partialError: 'تعذّر تحديث بعض بيانات تقدمك\nنعرض آخر نسخة متاحة'}
      : {}),
  };
  // The backend already caps active courses at 100. Keeping the complete
  // metadata set prevents older active courses from disappearing offline.
  if (!partialFailure) {
    await AsyncStorage.setItem(
      dashboardCacheKey,
      JSON.stringify({
        version: 2,
        savedAt: serverNowMs(),
        dashboard: {...dashboard, courses: dashboard.courses.slice(0, 100)},
      } satisfies LearningDashboardCache),
    ).catch(() => undefined);
  }
  assertAccountSessionBoundary(accountBoundary);
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

const savedLessonsCacheKey = (capturedKey?: string) =>
  capturedKey
    ? Promise.resolve(capturedKey)
    : accountScopedStorageKey(SAVED_LESSONS_CACHE_KEY);

const readSavedLessonsCache = async (
  capturedKey?: string,
): Promise<SavedLesson[] | null> => {
  try {
    const raw = await AsyncStorage.getItem(
      await savedLessonsCacheKey(capturedKey),
    );
    if (!raw) return null;
    const parsed = JSON.parse(raw) as {
      version?: unknown;
      savedAt?: unknown;
      lessons?: unknown;
    };
    if (
      parsed?.version !== 2 ||
      !isServerTimestampFresh(
        Number(parsed.savedAt),
        SAVED_LESSONS_CACHE_TTL_MS,
      ) ||
      !Array.isArray(parsed.lessons)
    ) {
      return null;
    }
    return parsed.lessons.filter(
      (lesson): lesson is SavedLesson =>
        isRecord(lesson) &&
        typeof lesson.id === 'string' &&
        lesson.id.length > 0 &&
        typeof lesson.folderId === 'string' &&
        lesson.folderId.length > 0 &&
        typeof lesson.courseId === 'string' &&
        lesson.courseId.length > 0 &&
        typeof lesson.title === 'string' &&
        typeof lesson.courseTitle === 'string' &&
        typeof lesson.duration === 'string',
    );
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
  const accountBoundary = await captureAccountSessionBoundary();
  const capturedCacheKey = await accountScopedStorageKey(
    SAVED_LESSONS_CACHE_KEY,
    accountBoundary,
  );
  try {
    const rawData = payload<unknown>(
      await publicRequest.get('saved-lessons', {
        params: {page: safePage, per_page: safePerPage},
      }),
    );
    assertAccountSessionBoundary(accountBoundary);
    if (!isRecord(rawData) || !Array.isArray(rawData.lessons)) {
      throw new Error('SAVED_LESSONS_CONTRACT_INVALID');
    }
    const data = rawData as SavedLessonsPayloadDto;
    const lessons = resourceList<SavedLessonDto>(data.lessons).flatMap(
      lesson => {
        if (lesson?.id === null || lesson?.id === undefined) return [];
        const courseId = String(
          lesson.course?.id ?? lesson.course_id ?? '',
        ).trim();
        if (!courseId) return [];
        return resourceList<SavedFolderDto>(lesson.folder_memberships)
          .filter(folder => folder?.id !== null && folder?.id !== undefined)
          .map(folder => ({
            id: String(lesson.id),
            folderId: String(folder.id),
            folderName: String(folder.name || 'المشاهدة لاحقًا'),
            courseId,
            title: String(lesson.title || 'مقطع محفوظ'),
            courseTitle: String(lesson.course?.title || 'كورس ركن'),
            duration: (() => {
              const seconds = Math.max(
                0,
                Math.floor(
                  Number(lesson.duration_seconds) ||
                    Number(lesson.duration_minutes || 0) * 60,
                ),
              );
              return `${String(Math.floor(seconds / 60)).padStart(
                2,
                '0',
              )}:${String(seconds % 60).padStart(2, '0')}`;
            })(),
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
      assertAccountSessionBoundary(accountBoundary);
      await AsyncStorage.setItem(
        capturedCacheKey,
        JSON.stringify({
          version: 2,
          savedAt: serverNowMs(),
          lessons,
        }),
      ).catch(() => undefined);
    }
    assertAccountSessionBoundary(accountBoundary);
    return {
      lessons,
      page: currentPage,
      hasMore: currentPage < lastPage,
      total: Math.max(0, Number(pagination.total ?? lessons.length) || 0),
      fromCache: false,
    };
  } catch (error) {
    assertAccountSessionBoundary(accountBoundary);
    // Only page one has an offline snapshot; later-page failures remain errors.
    const cached =
      safePage === 1
        ? await readSavedLessonsCache(capturedCacheKey)
        : null;
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
  const normalizedFolderId = String(folderId).trim();
  const normalizedLessonId = String(lessonId).trim();
  if (!/^\d+$/.test(normalizedFolderId) || !/^\d+$/.test(normalizedLessonId)) {
    throw new Error('INVALID_SAVED_LESSON_ROUTE');
  }
  const accountBoundary = await captureAccountSessionBoundary();
  const capturedCacheKey = await accountScopedStorageKey(
    SAVED_LESSONS_CACHE_KEY,
    accountBoundary,
  );
  const response = await publicRequest.delete(
    `saved-folders/${normalizedFolderId}/lessons/${normalizedLessonId}`,
  );
  assertAccountSessionBoundary(accountBoundary);
  const cached = await readSavedLessonsCache(capturedCacheKey);
  if (cached) {
    assertAccountSessionBoundary(accountBoundary);
    await AsyncStorage.setItem(
      capturedCacheKey,
      JSON.stringify({
        version: 2,
        savedAt: serverNowMs(),
        lessons: cached.filter(
          lesson =>
            lesson.folderId !== normalizedFolderId ||
            lesson.id !== normalizedLessonId,
        ),
      }),
    ).catch(() => undefined);
  }
  assertAccountSessionBoundary(accountBoundary);
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
