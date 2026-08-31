import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  AsyncKeys,
  extractApiToken,
  getItem,
} from '../../constants/helpers';
import {publicRequest} from '../../constants/api';
import type {DemoCourse} from '../../data/demoContent';
import {normalizeCourseDurationMinutes} from '../../utils/courseDetailsPresentation';
import {
  ApiRecord,
  isApiRecord,
  payload,
  resourceList,
  valueAsBoolean,
} from './common';

const CATALOGUE_CACHE_KEY = '@rokn/catalogue-page/v1';
const CATALOGUE_CACHE_MAX_AGE_MS = 24 * 60 * 60 * 1000;
const CATALOGUE_CACHE_PAGE_LIMIT = 4;

type CourseTagDto = {
  id?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  show_on_home?: unknown;
  home_order?: unknown;
};

type CourseLessonDto = {id?: unknown; title?: unknown};
type CourseResumeDto = {
  available?: unknown;
  lesson_id?: unknown;
  lesson_title?: unknown;
  last_lesson?: CourseLessonDto;
  next_lesson?: CourseLessonDto;
  last_watched_at?: unknown;
  watched_at?: unknown;
};

type CourseEnrollmentDto = {
  is_active?: unknown;
  is_completed?: unknown;
  completed?: unknown;
  progress_percentage?: unknown;
  last_watched_at?: unknown;
  updated_at?: unknown;
};

type CourseSectionDto = ApiRecord & {
  content?: ApiRecord;
  sectionable?: ApiRecord;
  lesson?: ApiRecord;
};

type CourseModuleDto = ApiRecord & {sections?: CourseSectionDto[]};
type CourseAccessPlanDto = ApiRecord;
type CourseTeacherDto = {
  name?: unknown;
  bio?: unknown;
  job_title?: unknown;
  image?: unknown;
};

type CourseDto = ApiRecord & {
  tags?: CourseTagDto[];
  enrollment?: CourseEnrollmentDto;
  progress?: unknown;
  resume?: CourseResumeDto;
  learning_resume?: CourseResumeDto;
  last_lesson?: CourseLessonDto;
  current_lesson?: CourseLessonDto;
  next_lesson?: CourseLessonDto;
  next_section?: CourseLessonDto;
  modules?: CourseModuleDto[];
  teachers?: CourseTeacherDto[];
  access_plans?: CourseAccessPlanDto[];
  metadata?: ApiRecord;
  catalog_badge?: ApiRecord;
};

type CatalogueCacheRecord = {
  savedAt: number;
  courses: DemoCourse[];
  page: number;
  hasMore: boolean;
  total: number;
};

export type PublishedCoursesPage = {
  courses: DemoCourse[];
  page: number;
  hasMore: boolean;
  total: number;
  fromCache: boolean;
};

export type CourseModulePreview = {
  id: string;
  title: string;
  reelCount: number;
  projectCount: number;
  previewReelCount: number;
  items: Array<{
    id: string;
    title: string;
    type: 'reel' | 'project' | 'other';
    isPreview: boolean;
    reelNumber?: number;
    reelId?: string;
  }>;
};

export type CourseAccessPlan = {
  code: 'basic' | 'guided' | 'mentor' | string;
  name: string;
  priceCoins: number;
  minimumPaidCoins?: number;
  chatEnabled: boolean;
  chatMessageLimit: number;
  projectFeedbackLevel: 'pass_only' | 'report' | 'enhanced' | string;
  projectReportEnabled: boolean;
  projectOutputEnabled: boolean;
  certificateEnabled: boolean;
};

export type CourseDetails = {
  id: string;
  title: string;
  description: string;
  imageUrl?: string;
  price: number | null;
  instructor: string;
  instructorBio: string;
  instructorImage?: string;
  owned: boolean;
  modules: CourseModulePreview[];
  reelCount: number;
  projectCount: number;
  previewReelCount: number;
  ratingAverage: number | null;
  ratingsCount: number;
  studentsCount: number;
  durationMinutes: number | null;
  accessPlans: CourseAccessPlan[];
};

const courseCategory = (course: CourseDto): DemoCourse['category'] => {
  const labels = (Array.isArray(course?.tags) ? course.tags : [])
    .flatMap(tag => [tag.name_ar, tag.name_en])
    .filter(Boolean)
    .join(' ')
    .toLowerCase();
  if (labels.includes('لغة') || labels.includes('language')) return 'language';
  if (
    labels.includes('دين') ||
    labels.includes('قرآن') ||
    labels.includes('relig')
  ) {
    return 'religious';
  }
  return 'freelance';
};

const courseProgress = (course: CourseDto): number | undefined => {
  if (
    valueAsBoolean(
      course?.enrollment?.is_completed,
      course?.enrollment?.completed,
      course?.is_completed,
    )
  ) {
    return 100;
  }
  const progress = isApiRecord(course.progress) ? course.progress : {};
  const raw =
    course?.enrollment?.progress_percentage ??
    course?.progress_percentage ??
    progress.progress_percentage ??
    course?.progress;
  if (raw === null || raw === undefined || raw === '') return undefined;
  const numeric = Number(raw);
  return Number.isFinite(numeric)
    ? Math.max(0, Math.min(100, numeric))
    : undefined;
};

export type CourseProgress = {
  id: string;
  title: string;
  imageUrl?: string;
  progress: number;
  completedSections: number;
  totalSections: number;
  category: DemoCourse['category'];
  accessType?: string;
  chatAvailable: boolean;
  certificateAvailable: boolean;
  lastLessonId?: string;
  lastLessonTitle?: string;
  nextLessonId?: string;
  nextLessonTitle?: string;
  lastWatchedAt?: string;
};

export const getLearningCourses = async (): Promise<CourseProgress[]> => {
  const data = payload(await publicRequest.get('learning/courses'));
  return resourceList<CourseDto>(data.items)
    .filter(item => item?.course_id !== null && item?.course_id !== undefined)
    .map(item => {
      const resume = item.resume || item.learning_resume || {};
      const lastLesson =
        resume.last_lesson ||
        (resume.available
          ? {id: resume.lesson_id, title: resume.lesson_title}
          : undefined) ||
        item.last_lesson ||
        item.current_lesson ||
        {};
      const nextLesson =
        resume.next_lesson || item.next_lesson || item.next_section || {};
      return {
        id: String(item.course_id),
        title: String(item.title || 'كورس ركن'),
        imageUrl: item.image ? String(item.image) : undefined,
        progress: Math.max(
          0,
          Math.min(100, Number(item.progress_percentage || 0)),
        ),
        completedSections: Math.max(0, Number(item.completed_sections || 0)),
        totalSections: Math.max(0, Number(item.total_sections || 0)),
        category: courseCategory(item),
        accessType: item.access_type ? String(item.access_type) : undefined,
        chatAvailable: valueAsBoolean(item.chat_available),
        certificateAvailable:
          item.certificate_available === undefined
            ? true
            : valueAsBoolean(item.certificate_available),
        lastLessonId:
          (lastLesson?.id ?? item.last_lesson_id ?? item.current_lesson_id) ===
            null ||
          (lastLesson?.id ?? item.last_lesson_id ?? item.current_lesson_id) ===
            undefined
            ? undefined
            : String(
                lastLesson?.id ?? item.last_lesson_id ?? item.current_lesson_id,
              ),
        lastLessonTitle:
          lastLesson?.title ||
          item.last_lesson_title ||
          item.current_lesson_title
            ? String(
                lastLesson?.title ||
                  item.last_lesson_title ||
                  item.current_lesson_title,
              )
            : undefined,
        nextLessonId:
          (nextLesson?.id ?? item.next_lesson_id ?? item.next_section_id) ===
            null ||
          (nextLesson?.id ?? item.next_lesson_id ?? item.next_section_id) ===
            undefined
            ? undefined
            : String(
                nextLesson?.id ?? item.next_lesson_id ?? item.next_section_id,
              ),
        nextLessonTitle:
          nextLesson?.title || item.next_lesson_title || item.next_section_title
            ? String(
                nextLesson?.title ||
                  item.next_lesson_title ||
                  item.next_section_title,
              )
            : undefined,
        lastWatchedAt:
          String(
            resume.last_watched_at ||
              resume.watched_at ||
              item.last_activity_at ||
              item.last_watched_at ||
              item.enrollment?.last_watched_at ||
              item.enrollment?.updated_at ||
              '',
          ) || undefined,
      };
    });
};

const courseModules = (course: CourseDto): CourseModulePreview[] => {
  let reelNumber = 0;
  return (Array.isArray(course?.modules) ? course.modules : []).map(module => {
    const sections = Array.isArray(module?.sections) ? module.sections : [];
    const sectionType = (section: CourseSectionDto) =>
      String(
        section?.type ||
          section?.section_type ||
          section?.content?.type ||
          'lesson',
      ).toLowerCase();
    const lessons = sections.filter(section =>
      ['lesson', 'video', 'reel'].includes(sectionType(section)),
    );
    const isPreviewSection = (section: CourseSectionDto) => {
      const content =
        section?.content || section?.sectionable || section?.lesson || {};
      return valueAsBoolean(
        section?.is_preview,
        section?.preview,
        section?.is_free_preview,
        section?.is_free,
        content?.is_preview,
        content?.preview,
        content?.is_free_preview,
        content?.is_free,
        content?.is_opened,
      );
    };
    const items = sections.map(section => {
      const rawType = sectionType(section);
      const content =
        section?.content || section?.sectionable || section?.lesson || {};
      const type =
        rawType === 'project'
          ? 'project'
          : ['lesson', 'video', 'reel'].includes(rawType)
          ? 'reel'
          : 'other';
      const currentReelNumber = type === 'reel' ? ++reelNumber : undefined;
      return {
        id: String(
          section?.id ?? `${module?.id}-${currentReelNumber ?? rawType}`,
        ),
        title: String(
          section?.title ||
            section?.content?.title ||
            (type === 'project' ? 'مشروع العبور' : 'مقطع تعليمي'),
        ),
        type,
        isPreview: type === 'reel' && isPreviewSection(section),
        reelNumber: currentReelNumber,
        reelId:
          type === 'reel'
            ? String(content?.id || section?.lesson_id || section?.id)
            : undefined,
      } as CourseModulePreview['items'][number];
    });
    return {
      id: String(module.id),
      title: String(module.title || 'وحدة تعليمية'),
      reelCount: lessons.length,
      projectCount: sections.filter(section => section.type === 'project')
        .length,
      previewReelCount: lessons.filter(isPreviewSection).length,
      items,
    };
  });
};

const mapCourseDetails = (course: CourseDto): CourseDetails => {
  const modules = courseModules(course);
  const teacher = Array.isArray(course?.teachers) ? course.teachers[0] : null;
  const rawPrice = Number(course?.price);
  const durationMinutes = normalizeCourseDurationMinutes(course);
  const planOrder: Record<string, number> = {basic: 0, guided: 1, mentor: 2};
  const seenPlanCodes = new Set<string>();
  const accessPlans = resourceList<CourseAccessPlanDto>(course?.access_plans)
    .filter(plan => {
      const price = Number(plan?.price_coins);
      return (
        String(plan?.code || '').trim().length > 0 &&
        plan?.price_coins !== '' &&
        plan?.price_coins !== null &&
        plan?.price_coins !== undefined &&
        Number.isSafeInteger(price) &&
        price >= 0
      );
    })
    .map(plan => {
      const code = String(plan.code).trim().toLowerCase();
      const fallbackName =
        code === 'basic'
          ? 'التعلّم'
          : code === 'guided'
          ? 'التعلّم بإرشاد'
          : code === 'mentor'
          ? 'التعلّم بمتابعة'
          : 'اختيار التعلّم';
      return {
        code,
        name:
          String(plan.name || '').trim() ||
          String(plan.name_ar || '').trim() ||
          fallbackName,
        priceCoins: Number(plan.price_coins),
        minimumPaidCoins: Math.max(0, Number(plan.minimum_paid_coins) || 0),
        chatEnabled: valueAsBoolean(plan.chat_enabled),
        chatMessageLimit: Math.max(0, Number(plan.chat_message_limit) || 0),
        projectFeedbackLevel: String(
          plan.project_feedback_level || 'pass_only',
        ),
        projectReportEnabled: valueAsBoolean(plan.project_report_enabled),
        projectOutputEnabled: valueAsBoolean(plan.project_output_enabled),
        certificateEnabled:
          plan.certificate_enabled === undefined
            ? true
            : valueAsBoolean(plan.certificate_enabled),
      };
    })
    .filter(plan => {
      if (!plan.code || seenPlanCodes.has(plan.code)) return false;
      seenPlanCodes.add(plan.code);
      return true;
    })
    .sort(
      (left, right) =>
        (planOrder[left.code] ?? 100) - (planOrder[right.code] ?? 100) ||
        left.priceCoins - right.priceCoins,
    );
  return {
    id: String(course.id),
    title: String(course.title || 'كورس ركن'),
    description: String(course.description || ''),
    imageUrl: course.image ? String(course.image) : undefined,
    price:
      course.price === null || course.price === undefined
        ? null
        : Number.isFinite(rawPrice)
        ? Math.max(0, rawPrice)
        : null,
    instructor: String(teacher?.name || 'فريق ركن'),
    instructorBio: String(teacher?.bio || teacher?.job_title || ''),
    instructorImage: teacher?.image ? String(teacher.image) : undefined,
    owned: Boolean(course?.enrollment?.is_active),
    modules,
    reelCount: modules.reduce((sum, module) => sum + module.reelCount, 0),
    projectCount: modules.reduce((sum, module) => sum + module.projectCount, 0),
    previewReelCount: Math.max(
      0,
      Number(
        course?.preview_reel_count ??
          course?.preview_reels_count ??
          course?.metadata?.preview_reels_count ??
          course?.previewReelCount ??
          modules.reduce((sum, module) => sum + module.previewReelCount, 0),
      ) || 0,
    ),
    ratingAverage:
      Number(course?.average_rating ?? course?.ratings_avg_rating) > 0
        ? Number(course?.average_rating ?? course?.ratings_avg_rating)
        : null,
    ratingsCount: Math.max(0, Number(course?.ratings_count ?? 0) || 0),
    studentsCount: Math.max(
      0,
      Number(course?.metadata?.students_count ?? course?.students_count ?? 0) ||
        0,
    ),
    durationMinutes,
    accessPlans,
  };
};

let learningCatalogueSnapshot: {
  expiresAt: number;
  items: CourseProgress[];
} | null = null;
let learningCatalogueFlight: Promise<CourseProgress[]> | null = null;

const getLearningCatalogueSnapshot = async () => {
  if (
    learningCatalogueSnapshot &&
    learningCatalogueSnapshot.expiresAt > Date.now()
  ) {
    return learningCatalogueSnapshot.items;
  }
  if (!learningCatalogueFlight) {
    learningCatalogueFlight = getLearningCourses()
      .then(items => {
        learningCatalogueSnapshot = {
          expiresAt: Date.now() + 30_000,
          items,
        };
        return items;
      })
      .finally(() => {
        learningCatalogueFlight = null;
      });
  }
  return learningCatalogueFlight;
};

const mapPublishedCourses = (
  items: CourseDto[],
  learningResult: CourseProgress[],
): DemoCourse[] => {
  const learningByCourse = new Map(learningResult.map(item => [item.id, item]));
  return items
    .filter(
      item =>
        (item?.id ?? item?.course_id) !== null &&
        (item?.id ?? item?.course_id) !== undefined &&
        (item.is_coming_soon ||
          item._compact_search ||
          (item.price !== null && item.price !== undefined)) &&
        String(item.title || '').trim().length > 0,
    )
    .map(item => {
      const courseId = item.id ?? item.course_id;
      const learning = learningByCourse.get(String(courseId));
      const badgeLabel = String(
        item?.catalog_badge?.label || item?.badge || '',
      ).trim();
      const badgeTone = String(
        item?.catalog_badge?.tone || item?.badge_tone || 'blue',
      );
      const homeRows = (Array.isArray(item?.tags) ? item.tags : [])
        .filter(tag => valueAsBoolean(tag.show_on_home))
        .map(tag => ({
          id: String(tag.id),
          title: String(tag.name_ar || tag.name_en || '').trim(),
          order: Math.max(0, Number(tag.home_order ?? 100) || 100),
        }))
        .filter(row => row.title.length > 0);
      return {
        id: String(courseId),
        title: String(item.title),
        description: String(item.description || ''),
        instructor: String(
          item.teacher_name || item.teachers?.[0]?.name || 'فريق ركن',
        ),
        image: item.image
          ? {uri: String(item.image)}
          : require('../../assets/images/authLogo.png'),
        label:
          badgeLabel ||
          (item.is_coming_soon
            ? 'قريبًا'
            : item.is_main_course
            ? 'مختار لك'
            : undefined),
        labelTone: badgeLabel
          ? badgeTone === 'green'
            ? 'success'
            : badgeTone === 'gold'
            ? 'coin'
            : badgeTone === 'neutral'
            ? 'neutral'
            : 'primary'
          : item.is_coming_soon
          ? 'neutral'
          : 'primary',
        isMainCourse: valueAsBoolean(item.is_main_course),
        homeSortOrder: Math.max(0, Number(item.home_sort_order ?? 100) || 100),
        homeRows,
        coinPrice:
          item.price === null || item.price === undefined
            ? undefined
            : Number(item.price),
        progress: learning?.progress ?? courseProgress(item),
        category: courseCategory(item),
        owned: Boolean(learning || item.enrollment?.is_active),
        published: !item.is_coming_soon,
      };
    });
};

const catalogueCacheKey = async (page: number) =>
  `${await accountScopedStorageKey(CATALOGUE_CACHE_KEY)}:${page}`;

const readCatalogueCache = async (
  page: number,
): Promise<PublishedCoursesPage | null> => {
  if (page > CATALOGUE_CACHE_PAGE_LIMIT) return null;
  try {
    const raw = await AsyncStorage.getItem(await catalogueCacheKey(page));
    if (!raw) return null;
    const cached = JSON.parse(raw) as CatalogueCacheRecord;
    if (
      !Array.isArray(cached.courses) ||
      Date.now() - Number(cached.savedAt || 0) > CATALOGUE_CACHE_MAX_AGE_MS
    ) {
      return null;
    }
    return {
      courses: cached.courses,
      page: cached.page,
      hasMore: cached.hasMore,
      total: cached.total,
      fromCache: true,
    };
  } catch {
    return null;
  }
};

const writeCatalogueCache = async (
  result: Omit<PublishedCoursesPage, 'fromCache'>,
) => {
  if (result.page > CATALOGUE_CACHE_PAGE_LIMIT) return;
  const record: CatalogueCacheRecord = {
    savedAt: Date.now(),
    courses: result.courses,
    page: result.page,
    hasMore: result.hasMore,
    total: result.total,
  };
  await AsyncStorage.setItem(
    await catalogueCacheKey(result.page),
    JSON.stringify(record),
  );
};

export const getPublishedCoursesPage = async ({
  page = 1,
  perPage = 30,
  search = '',
}: {
  page?: number;
  perPage?: number;
  search?: string;
} = {}): Promise<PublishedCoursesPage> => {
  const safePage = Math.max(1, Math.floor(page));
  const normalizedSearch = search.trim().slice(0, 120);
  const safePerPage = Math.max(
    1,
    Math.min(normalizedSearch ? 20 : 50, Math.floor(perPage)),
  );
  try {
    const sessionAvailable = await hasSession();
    const [catalogueResponse, learningResult] = await Promise.all([
      publicRequest.get(normalizedSearch ? 'search/courses' : 'courses/list', {
        params: {
          page: safePage,
          per_page: safePerPage,
          ...(normalizedSearch ? {q: normalizedSearch} : {}),
        },
      }),
      sessionAvailable
        ? getLearningCatalogueSnapshot().catch(() => [])
        : Promise.resolve([]),
    ]);
    const data = payload(catalogueResponse);
    const items = normalizedSearch
      ? resourceList(data.items).map(item => ({...item, _compact_search: true}))
      : Array.isArray(data)
      ? data
      : resourceList(data.courses);
    const pagination = (data?.pagination || {}) as {
      current_page?: unknown;
      last_page?: unknown;
      total?: unknown;
    };
    const currentPage = Math.max(
      1,
      Number(pagination.current_page ?? safePage) || safePage,
    );
    const lastPage = Math.max(
      currentPage,
      Number(pagination.last_page ?? currentPage) || currentPage,
    );
    const result = {
      courses: mapPublishedCourses(items, learningResult),
      page: currentPage,
      hasMore: currentPage < lastPage,
      total: Math.max(0, Number(pagination.total ?? items.length) || 0),
    };
    // Keep the device cache bounded to catalogue pages.
    if (!normalizedSearch) {
      await writeCatalogueCache(result).catch(() => undefined);
    }
    return {...result, fromCache: false};
  } catch (error) {
    if (!normalizedSearch) {
      const cached = await readCatalogueCache(safePage);
      if (cached) return cached;
    }
    throw error;
  }
};

/** First-page convenience wrapper. */
export const getPublishedCourses = async (): Promise<DemoCourse[]> =>
  (await getPublishedCoursesPage()).courses;

/** Read the latest small catalogue snapshot without network or new storage. */
export const getCachedPublishedCourses = async (): Promise<DemoCourse[]> =>
  (await readCatalogueCache(1))?.courses ?? [];

export const getCourseDetails = async (
  courseId: string,
): Promise<CourseDetails> => {
  const data = payload(await publicRequest.get(`courses/${courseId}/details`));
  return mapCourseDetails(data);
};

/** Demo sessions have no bearer token and cannot call authenticated APIs. */
export const hasSession = async () => {
  const user = await getItem(AsyncKeys.USER_DATA);
  return Boolean(extractApiToken(user));
};

export const getOwnedCourseIds = async (): Promise<Set<string>> => {
  const profile = payload(await publicRequest.get('user/profile'));
  return new Set(
    resourceList<CourseDto>(profile.courses)
      .filter(course => course?.id !== null && course?.id !== undefined)
      .map(course => String(course.id)),
  );
};

export type ProductionCourseModulePreview = CourseModulePreview;
export type ProductionCourseAccessPlan = CourseAccessPlan;
export type ProductionCourseDetails = CourseDetails;
export type ProductionCourseProgress = CourseProgress;
export const getProductionLearningCourses = getLearningCourses;
export const getProductionCourseDetails = getCourseDetails;
export const hasProductionSession = hasSession;
export const getProductionOwnedCourseIds = getOwnedCourseIds;
