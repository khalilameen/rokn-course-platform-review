import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  AsyncKeys,
  extractApiToken,
  getItem,
  normalizeText,
} from '../../constants/helpers';
import {publicRequest} from '../../constants/api';
import type {DemoCourse} from '../../data/demoContent';
import {normalizeCourseDurationMinutes} from '../../utils/courseDetailsPresentation';
import {isServerTimestampFresh, serverNowMs} from '../../utils/serverClock';
import {truncateGraphemes} from '../../utils/unicodeText';
import {
  ApiRecord,
  firstBoolean,
  isApiRecord,
  payload,
  resourceList,
  valueAsBoolean,
} from './common';

const numericRouteId = (value: string, field: string) => {
  const normalized = String(value).trim();
  if (!/^\d+$/.test(normalized) || Number(normalized) <= 0) {
    throw new Error(`INVALID_${field}_ID`);
  }
  return normalized;
};

const CATALOGUE_CACHE_KEY = '@rokn/catalogue-page/v4';
const CATALOGUE_CACHE_MAX_AGE_MS = 2 * 60 * 60 * 1000;
const CATALOGUE_CACHE_PAGE_LIMIT = 4;
const COURSE_DETAILS_CACHE_KEY = '@rokn/course-details/v3';
const COURSE_DETAILS_CACHE_MAX_AGE_MS = 24 * 60 * 60 * 1000;
const COURSE_DETAILS_CACHE_LIMIT = 8;
let courseDetailsCacheTail: Promise<void> = Promise.resolve();
const unavailableCourseListeners = new Set<(courseId: string) => void>();

export const subscribeToUnavailableCourses = (
  listener: (courseId: string) => void,
) => {
  unavailableCourseListeners.add(listener);
  return () => {
    unavailableCourseListeners.delete(listener);
  };
};

const publishUnavailableCourse = (courseId: string) => {
  unavailableCourseListeners.forEach(listener => listener(courseId));
};

const nonNegativeNumberOr = (value: unknown, fallback: number): number => {
  if (value === null || value === undefined || value === '') return fallback;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? Math.max(0, parsed) : fallback;
};

const courseUserRating = (value: unknown): number | null => {
  const raw = isApiRecord(value) ? value.rating : value;
  const rating = Number(raw);
  return rating >= 1 && rating <= 5 ? rating : null;
};

type CourseTagDto = {
  id?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  show_on_home?: unknown;
  home_order?: unknown;
};

type CourseLessonDto = {id?: unknown; title?: unknown; type?: unknown};
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
  access_type?: unknown;
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
  rating_eligibility?: ApiRecord;
  user_rating?: ApiRecord;
};

type CatalogueCacheRecord = {
  version: 4;
  savedAt: number;
  courses: DemoCourse[];
  page: number;
  hasMore: boolean;
  total: number;
  revision: number;
};

export type PublishedCoursesPage = {
  courses: DemoCourse[];
  page: number;
  hasMore: boolean;
  total: number;
  fromCache: boolean;
  revision: number;
  reset?: boolean;
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
    type: 'reel' | 'project' | 'quiz' | 'other';
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
  projectFollowupEnabled?: boolean;
  projectFollowupMessageLimit?: number;
  projectFollowupTokenBudget?: number;
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
  userRating: number | null;
  ratingVersion?: number;
  ratingEligible?: boolean;
  ratingEligibilityReason?: string;
  studentsCount: number;
  durationMinutes: number | null;
  accessPlans: CourseAccessPlan[];
  fromCache?: boolean;
};

type CourseDetailsCacheRecord = {
  version: 3;
  savedAt: number;
  course: CourseDetails;
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
  nextSectionType?: string;
  lastWatchedAt?: string;
};

export const getLearningCourses = async (): Promise<CourseProgress[]> => {
  const rawData = payload<unknown>(
    await publicRequest.get('learning/courses'),
  );
  if (!isApiRecord(rawData) || !Array.isArray(rawData.items)) {
    throw new Error('LEARNING_COURSES_CONTRACT_INVALID');
  }
  const data = rawData;
  return resourceList<CourseDto>(data.items)
    .filter(
      item =>
        isApiRecord(item) &&
        item.course_id !== null &&
        item.course_id !== undefined,
    )
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
        progress: Math.min(
          100,
          nonNegativeNumberOr(item.progress_percentage, 0),
        ),
        completedSections: nonNegativeNumberOr(item.completed_sections, 0),
        totalSections: nonNegativeNumberOr(item.total_sections, 0),
        category: courseCategory(item),
        accessType: item.access_type ? String(item.access_type) : undefined,
        chatAvailable: valueAsBoolean(item.chat_available),
        certificateAvailable:
          item.certificate_available === undefined
            ? false
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
        nextSectionType:
          nextLesson?.type || item.next_section?.type
            ? String(nextLesson?.type || item.next_section?.type).toLowerCase()
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
  return (Array.isArray(course?.modules) ? course.modules : []).flatMap(
    module => {
      const moduleId = String(module?.id ?? '').trim();
      if (!moduleId) return [];
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
      const items = sections.flatMap(section => {
        const rawType = sectionType(section);
        const content =
          section?.content || section?.sectionable || section?.lesson || {};
        const type =
          rawType === 'project'
            ? 'project'
            : rawType === 'quiz'
            ? 'quiz'
            : ['lesson', 'video', 'reel'].includes(rawType)
            ? 'reel'
            : 'other';
        if (type === 'other') return [];
        const sectionId = String(section?.id ?? '').trim();
        if (!sectionId) return [];
        const currentReelNumber = type === 'reel' ? ++reelNumber : undefined;
        return [
          {
            id: sectionId,
            title: String(
              section?.title ||
                section?.content?.title ||
                (type === 'project'
                  ? 'مشروع العبور'
                  : type === 'quiz'
                  ? 'اختبار الوحدة'
                  : 'مقطع تعليمي'),
            ),
            type,
            isPreview: type === 'reel' && isPreviewSection(section),
            reelNumber: currentReelNumber,
            reelId:
              type === 'reel'
                ? String(content?.id || section?.lesson_id || sectionId)
                : undefined,
          } as CourseModulePreview['items'][number],
        ];
      });
      return [
        {
          id: moduleId,
          title: String(module.title || 'وحدة تعليمية'),
          reelCount: lessons.length,
          projectCount: sections.filter(
            section => sectionType(section) === 'project',
          ).length,
          previewReelCount: lessons.filter(isPreviewSection).length,
          items,
        },
      ];
    },
  );
};

const mapCourseDetails = (course: CourseDto): CourseDetails => {
  const modules = courseModules(course);
  const teacher = Array.isArray(course?.teachers) ? course.teachers[0] : null;
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
          ['pass_only', 'report', 'enhanced'].includes(
            String(plan.project_feedback_level),
          )
            ? plan.project_feedback_level
            : 'pass_only',
        ) as CourseAccessPlan['projectFeedbackLevel'],
        projectReportEnabled: valueAsBoolean(plan.project_report_enabled),
        projectFollowupEnabled: valueAsBoolean(
          plan.project_thread_reply_enabled,
        ),
        projectFollowupMessageLimit: Math.max(
          0,
          Number(plan.project_message_limit) || 0,
        ),
        projectFollowupTokenBudget: Math.max(
          0,
          Number(plan.project_token_budget) || 0,
        ),
        projectOutputEnabled: valueAsBoolean(plan.project_output_enabled),
        certificateEnabled:
          plan.certificate_enabled === undefined
            ? false
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
    id: String(course.id ?? '').trim(),
    title: String(course.title || 'كورس ركن'),
    description: String(course.description || ''),
    imageUrl: course.image ? String(course.image) : undefined,
    price:
      accessPlans.length > 0
        ? Math.min(...accessPlans.map(plan => plan.priceCoins))
        : null,
    instructor: String(teacher?.name || 'فريق ركن'),
    instructorBio: String(teacher?.bio || teacher?.job_title || ''),
    instructorImage: teacher?.image ? String(teacher.image) : undefined,
    owned:
      String(course?.access_type || 'none').toLowerCase() !== 'none' ||
      valueAsBoolean(course?.enrollment?.is_active),
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
    userRating: courseUserRating(course?.user_rating),
    ratingVersion: Math.max(
      0,
      Number(
        course?.rating_eligibility?.version ?? course?.user_rating?.version,
      ) || 0,
    ),
    ratingEligible: valueAsBoolean(course?.rating_eligibility?.can_rate),
    ratingEligibilityReason: String(
      course?.rating_eligibility?.reason || 'course_access_required',
    ),
    studentsCount: Math.max(
      0,
      Number(course?.metadata?.students_count ?? course?.students_count ?? 0) ||
        0,
    ),
    durationMinutes,
    accessPlans,
  };
};

type LearningCatalogueSnapshot = {
  expiresAt: number;
  items: CourseProgress[];
};
const learningCatalogueSnapshots = new Map<string, LearningCatalogueSnapshot>();
const learningCatalogueFlights = new Map<string, Promise<CourseProgress[]>>();

const getLearningCatalogueSnapshot = async (): Promise<CourseProgress[]> => {
  const scope = await accountScopedStorageKey('@rokn/learning-catalogue');
  const snapshot = learningCatalogueSnapshots.get(scope);
  if (snapshot && snapshot.expiresAt > serverNowMs()) {
    return snapshot.items;
  }
  const currentFlight = learningCatalogueFlights.get(scope);
  if (currentFlight) return currentFlight;

  const flight = getLearningCourses()
    .then(items => {
      learningCatalogueSnapshots.set(scope, {
        expiresAt: serverNowMs() + 30_000,
        items,
      });
      while (learningCatalogueSnapshots.size > 4) {
        const oldestScope = learningCatalogueSnapshots.keys().next().value;
        if (typeof oldestScope !== 'string') break;
        learningCatalogueSnapshots.delete(oldestScope);
      }
      return items;
    })
    .finally(() => {
      learningCatalogueFlights.delete(scope);
    });
  learningCatalogueFlights.set(scope, flight);
  return flight;
};

const mapPublishedCourses = (
  items: CourseDto[],
  learningResult: CourseProgress[],
): DemoCourse[] => {
  const learningByCourse = new Map(learningResult.map(item => [item.id, item]));
  const seenCourseIds = new Set<string>();
  return items
    .filter(item => {
      const rawId = item?.id ?? item?.course_id;
      if (
        rawId === null ||
        rawId === undefined ||
        String(item.title || '').trim().length === 0
      ) {
        return false;
      }
      const id = String(rawId);
      if (seenCourseIds.has(id)) return false;
      seenCourseIds.add(id);
      return true;
    })
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
          order: nonNegativeNumberOr(tag.home_order, 100),
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
          : require('../../assets/images/courseSlider.jpg'),
        label:
          badgeLabel ||
          (valueAsBoolean(item.is_coming_soon)
            ? 'قريبًا'
            : valueAsBoolean(item.is_main_course)
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
          : valueAsBoolean(item.is_coming_soon)
          ? 'neutral'
          : 'primary',
        isMainCourse: valueAsBoolean(item.is_main_course),
        homeSortOrder: nonNegativeNumberOr(item.home_sort_order, 100),
        homeRows,
        coinPrice: (() => {
          if (item.price === null || item.price === undefined) return undefined;
          const price = Number(item.price);
          return Number.isSafeInteger(price) && price >= 0 ? price : undefined;
        })(),
        durationMinutes: (() => {
          const value = normalizeCourseDurationMinutes(item);
          return value === null ? undefined : value;
        })(),
        ratingAverage:
          Number(item?.average_rating ?? item?.ratings_avg_rating) > 0
            ? Number(item?.average_rating ?? item?.ratings_avg_rating)
            : undefined,
        ratingsCount: Math.max(0, Number(item?.ratings_count ?? 0) || 0),
        studentsCount: Math.max(
          0,
          Number(item?.metadata?.students_count ?? item?.students_count ?? 0) ||
            0,
        ),
        progress: learning?.progress ?? courseProgress(item),
        category: courseCategory(item),
        owned:
          Boolean(learning) ||
          (firstBoolean(item.enrollment?.is_active) ?? false),
        published: !(firstBoolean(item.is_coming_soon) ?? false),
      };
    });
};

const catalogueCacheKey = async (page: number, scopedBaseKey?: string) =>
  `${
    scopedBaseKey || (await accountScopedStorageKey(CATALOGUE_CACHE_KEY))
  }:${page}`;

const readCatalogueCache = async (
  page: number,
  scopedBaseKey?: string,
  expectedRevision?: number,
): Promise<PublishedCoursesPage | null> => {
  if (page > CATALOGUE_CACHE_PAGE_LIMIT) return null;
  try {
    const raw = await AsyncStorage.getItem(
      await catalogueCacheKey(page, scopedBaseKey),
    );
    if (!raw) return null;
    const cached = JSON.parse(raw) as CatalogueCacheRecord;
    if (
      cached.version !== 4 ||
      !Array.isArray(cached.courses) ||
      cached.courses.some(
        course =>
          !course ||
          typeof course.id !== 'string' ||
          typeof course.title !== 'string',
      ) ||
      cached.page !== page ||
      !Number.isSafeInteger(cached.revision) ||
      cached.revision < 1 ||
      (expectedRevision !== undefined &&
        cached.revision !== expectedRevision) ||
      !isServerTimestampFresh(cached.savedAt, CATALOGUE_CACHE_MAX_AGE_MS)
    ) {
      return null;
    }
    return {
      courses: cached.courses,
      page: cached.page,
      hasMore: cached.hasMore,
      total: cached.total,
      fromCache: true,
      revision: cached.revision,
    };
  } catch {
    return null;
  }
};

const writeCatalogueCache = async (
  result: Omit<PublishedCoursesPage, 'fromCache'>,
  scopedBaseKey?: string,
) => {
  if (result.page > CATALOGUE_CACHE_PAGE_LIMIT) return;
  const record: CatalogueCacheRecord = {
    version: 4,
    savedAt: serverNowMs(),
    courses: result.courses,
    page: result.page,
    hasMore: result.hasMore,
    total: result.total,
    revision: result.revision,
  };
  await AsyncStorage.setItem(
    await catalogueCacheKey(result.page, scopedBaseKey),
    JSON.stringify(record),
  );
};

const removeCatalogueCachePages = async (
  firstPage: number,
  scopedBaseKey?: string,
) => {
  const pages = Array.from(
    {length: Math.max(0, CATALOGUE_CACHE_PAGE_LIMIT - firstPage + 1)},
    (_, index) => firstPage + index,
  );
  if (pages.length === 0) return;
  await AsyncStorage.multiRemove(
    await Promise.all(
      pages.map(page => catalogueCacheKey(page, scopedBaseKey)),
    ),
  );
};

export const getPublishedCoursesPage = async ({
  page = 1,
  perPage = 30,
  search = '',
  revision,
  signal,
}: {
  page?: number;
  perPage?: number;
  search?: string;
  revision?: number;
  signal?: AbortSignal;
} = {}): Promise<PublishedCoursesPage> => {
  const safePage = Math.max(1, Math.floor(page));
  const normalizedSearch = truncateGraphemes(normalizeText(search), 120);
  const expectedRevision =
    Number.isSafeInteger(revision) && Number(revision) > 0
      ? Number(revision)
      : undefined;
  const safePerPage = Math.max(
    1,
    Math.min(normalizedSearch ? 20 : 50, Math.floor(perPage)),
  );
  // The network response belongs to the account that started the request.
  // Never resolve its cache destination after a logout/account switch.
  const scopedCatalogueCacheKey = await accountScopedStorageKey(
    CATALOGUE_CACHE_KEY,
  );
  try {
    const sessionAvailable = await hasSession();
    const [catalogueResponse, learningSnapshot] = await Promise.all([
      publicRequest.get(normalizedSearch ? 'search/courses' : 'courses/list', {
        signal,
        params: {
          page: safePage,
          per_page: safePerPage,
          ...(normalizedSearch ? {q: normalizedSearch} : {}),
          ...(safePage > 1 && expectedRevision
            ? {catalogue_revision: expectedRevision}
            : {}),
        },
      }),
      sessionAvailable
        ? getLearningCatalogueSnapshot()
            .then(courses => ({available: true, courses}))
            .catch(() => ({available: false, courses: [] as CourseProgress[]}))
        : Promise.resolve({available: true, courses: [] as CourseProgress[]}),
    ]);
    const data = payload(catalogueResponse);
    const responseRevision = Math.max(1, Number(data?.catalogue_revision) || 1);
    if (
      safePage > 1 &&
      expectedRevision !== undefined &&
      responseRevision !== expectedRevision
    ) {
      const changed = new Error('CATALOGUE_CHANGED') as Error & {
        code?: string;
      };
      changed.code = 'catalogue_changed';
      throw changed;
    }
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
    let mappedCourses = mapPublishedCourses(items, learningSnapshot.courses);
    if (sessionAvailable && !learningSnapshot.available) {
      // Catalogue availability and entitlement availability are independent.
      // A temporary learning-dashboard outage must not relock cards that were
      // already confirmed for this account or persist false ownership over a
      // good cache snapshot. Course details remains the final authority.
      const previous = normalizedSearch
        ? null
        : await readCatalogueCache(
            safePage,
            scopedCatalogueCacheKey,
            expectedRevision,
          );
      const previousById = new Map(
        (previous?.courses || []).map(course => [course.id, course]),
      );
      mappedCourses = mappedCourses.map(course => {
        const known = previousById.get(course.id);
        return {
          ...course,
          owned: known?.owned,
          progress: known?.progress,
        };
      });
    }
    const result = {
      courses: mappedCourses,
      page: currentPage,
      hasMore: currentPage < lastPage,
      total: Math.max(0, Number(pagination.total ?? items.length) || 0),
      revision: responseRevision,
    };
    // Keep the device cache bounded to catalogue pages.
    if (!normalizedSearch) {
      if (result.page === 1) {
        await removeCatalogueCachePages(2, scopedCatalogueCacheKey).catch(
          () => undefined,
        );
      } else if (!result.hasMore) {
        await removeCatalogueCachePages(
          result.page + 1,
          scopedCatalogueCacheKey,
        ).catch(() => undefined);
      }
      await writeCatalogueCache(result, scopedCatalogueCacheKey).catch(
        () => undefined,
      );
    }
    return {...result, fromCache: false};
  } catch (error) {
    const candidate = error as {
      code?: unknown;
      response?: {status?: unknown; data?: {code?: unknown}};
    };
    const catalogueChanged =
      candidate?.code === 'catalogue_changed' ||
      (Number(candidate?.response?.status) === 409 &&
        candidate?.response?.data?.code === 'catalogue_changed');
    if (catalogueChanged && safePage > 1) {
      const replacement = await getPublishedCoursesPage({
        page: 1,
        perPage: safePerPage,
        search: normalizedSearch,
        signal,
      });
      return {...replacement, reset: true};
    }
    if (!normalizedSearch) {
      const cached = await readCatalogueCache(
        safePage,
        scopedCatalogueCacheKey,
        expectedRevision,
      );
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

const courseDetailsCacheKey = async (
  courseId: string,
  scopedBaseKey?: string,
) =>
  `${
    scopedBaseKey || (await accountScopedStorageKey(COURSE_DETAILS_CACHE_KEY))
  }:${courseId}`;

const courseDetailsCacheIndexKey = (scopedBaseKey: string) =>
  `${scopedBaseKey}:index`;

const withCourseDetailsCacheLock = <T>(operation: () => Promise<T>) => {
  const result = courseDetailsCacheTail.then(operation, operation);
  courseDetailsCacheTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

const readCourseDetailsCacheIndex = async (
  scopedBaseKey: string,
): Promise<string[]> => {
  try {
    const raw = await AsyncStorage.getItem(
      courseDetailsCacheIndexKey(scopedBaseKey),
    );
    if (raw === null) {
      // Older releases cached every opened course without an index. Discover
      // that legacy set once, then the first write below persists a bounded
      // index so normal reads never scan all of AsyncStorage again.
      const prefix = `${scopedBaseKey}:`;
      const keys = (await AsyncStorage.getAllKeys()).filter(key => {
        const suffix = key.startsWith(prefix) ? key.slice(prefix.length) : '';
        return /^\d+$/.test(suffix);
      });
      const records = keys.length ? await AsyncStorage.multiGet(keys) : [];
      return records
        .map(([key, value]) => {
          let savedAt = '';
          try {
            savedAt = String(
              (JSON.parse(value || '{}') as {savedAt?: unknown}).savedAt || '',
            );
          } catch {
            // Corrupt entries sort last and are evicted first.
          }
          return {id: key.slice(prefix.length), savedAt};
        })
        .sort((left, right) => right.savedAt.localeCompare(left.savedAt))
        .map(item => item.id);
    }
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed)
      ? Array.from(
          new Set(
            parsed
              .map(value => String(value))
              .filter(value => /^\d+$/.test(value)),
          ),
        )
      : [];
  } catch {
    return [];
  }
};

const persistCourseDetailsCache = async (
  courseId: string,
  record: CourseDetailsCacheRecord,
  scopedBaseKey: string,
) =>
  withCourseDetailsCacheLock(async () => {
    const current = await readCourseDetailsCacheIndex(scopedBaseKey);
    const ordered = [courseId, ...current.filter(id => id !== courseId)];
    const retained = ordered.slice(0, COURSE_DETAILS_CACHE_LIMIT);
    const evicted = ordered.slice(COURSE_DETAILS_CACHE_LIMIT);
    await AsyncStorage.setItem(
      courseDetailsCacheIndexKey(scopedBaseKey),
      JSON.stringify(retained),
    );
    if (evicted.length) {
      await AsyncStorage.multiRemove(
        await Promise.all(
          evicted.map(id => courseDetailsCacheKey(id, scopedBaseKey)),
        ),
      );
    }
    await AsyncStorage.setItem(
      await courseDetailsCacheKey(courseId, scopedBaseKey),
      JSON.stringify(record),
    );
  });

const touchCourseDetailsCache = async (
  courseId: string,
  scopedBaseKey: string,
) =>
  withCourseDetailsCacheLock(async () => {
    const current = await readCourseDetailsCacheIndex(scopedBaseKey);
    const retained = [courseId, ...current.filter(id => id !== courseId)].slice(
      0,
      COURSE_DETAILS_CACHE_LIMIT,
    );
    await AsyncStorage.setItem(
      courseDetailsCacheIndexKey(scopedBaseKey),
      JSON.stringify(retained),
    );
  });

const readCourseDetailsCache = async (
  courseId: string,
  scopedBaseKey?: string,
): Promise<CourseDetails | null> => {
  try {
    const raw = await AsyncStorage.getItem(
      await courseDetailsCacheKey(courseId, scopedBaseKey),
    );
    if (!raw) return null;
    const cached = JSON.parse(raw) as CourseDetailsCacheRecord;
    if (
      cached.version !== 3 ||
      !isServerTimestampFresh(
        cached.savedAt,
        COURSE_DETAILS_CACHE_MAX_AGE_MS,
      ) ||
      !cached.course ||
      cached.course.id !== courseId ||
      typeof cached.course.title !== 'string' ||
      !Array.isArray(cached.course.accessPlans)
    ) {
      return null;
    }
    return {...cached.course, fromCache: true};
  } catch {
    return null;
  }
};

const removeCourseDetailsCache = async (
  courseId: string,
  scopedBaseKey?: string,
) => {
  const resolvedBaseKey =
    scopedBaseKey || (await accountScopedStorageKey(COURSE_DETAILS_CACHE_KEY));
  await withCourseDetailsCacheLock(async () => {
    const current = await readCourseDetailsCacheIndex(resolvedBaseKey);
    await Promise.all([
      AsyncStorage.removeItem(
        await courseDetailsCacheKey(courseId, resolvedBaseKey),
      ),
      AsyncStorage.setItem(
        courseDetailsCacheIndexKey(resolvedBaseKey),
        JSON.stringify(current.filter(id => id !== courseId)),
      ),
    ]);
  });
};

const errorStatus = (error: unknown): number => {
  const candidate = error as {
    status?: unknown;
    response?: {status?: unknown};
  };
  return Number(candidate?.status ?? candidate?.response?.status ?? 0) || 0;
};

export const isCourseUnavailableError = (error: unknown): boolean =>
  [403, 404, 410].includes(errorStatus(error));

export const getCourseDetails = async (
  courseId: string,
  options: {signal?: AbortSignal} = {},
): Promise<CourseDetails> => {
  const normalizedCourseId = numericRouteId(courseId, 'COURSE');
  const scopedDetailsCacheKey = await accountScopedStorageKey(
    COURSE_DETAILS_CACHE_KEY,
  );
  try {
    const data = payload(
      await publicRequest.get(`courses/${normalizedCourseId}/details`, {
        signal: options.signal,
      }),
    );
    const course = {...mapCourseDetails(data), fromCache: false};
    if (!course.id || course.id !== normalizedCourseId) {
      throw new Error('API_CONTRACT_INVALID_COURSE_DETAILS_ID');
    }
    const record: CourseDetailsCacheRecord = {
      version: 3,
      savedAt: serverNowMs(),
      course,
    };
    await persistCourseDetailsCache(
      normalizedCourseId,
      record,
      scopedDetailsCacheKey,
    ).catch(() => undefined);
    return course;
  } catch (error) {
    if (isCourseUnavailableError(error)) {
      await removeCourseDetailsCache(
        normalizedCourseId,
        scopedDetailsCacheKey,
      ).catch(() => undefined);
      if ([404, 410].includes(errorStatus(error))) {
        const scopedCatalogueBaseKey = await accountScopedStorageKey(
          CATALOGUE_CACHE_KEY,
        );
        await removeCatalogueCachePages(1, scopedCatalogueBaseKey).catch(
          () => undefined,
        );
        publishUnavailableCourse(normalizedCourseId);
      }
      throw error;
    }
    const cached = await readCourseDetailsCache(
      normalizedCourseId,
      scopedDetailsCacheKey,
    );
    if (cached) {
      await touchCourseDetailsCache(
        normalizedCourseId,
        scopedDetailsCacheKey,
      ).catch(() => undefined);
      return cached;
    }
    throw error;
  }
};

export type CourseRatingResult = {
  rating: number | null;
  version: number;
  averageRating: number | null;
  ratingsCount: number;
};

export const rateCourse = async (
  courseId: string,
  rating: number,
  version: number,
): Promise<CourseRatingResult> => {
  const normalizedCourseId = numericRouteId(courseId, 'COURSE');
  if (!Number.isInteger(rating) || rating < 1 || rating > 5) {
    throw new Error('INVALID_COURSE_RATING');
  }
  if (!Number.isSafeInteger(version) || version < 0) {
    throw new Error('INVALID_COURSE_RATING_VERSION');
  }
  const data = payload(
    await publicRequest.post(`courses/${normalizedCourseId}/rate`, {
      rating,
      version: Math.max(0, Math.floor(version)),
    }),
  );
  const average = Number(data.average_rating);
  return {
    rating: Math.min(5, Math.max(1, Number(data.rating) || rating)),
    version: Math.max(0, Number(data.version) || 0),
    averageRating: average > 0 ? average : null,
    ratingsCount: Math.max(0, Number(data.ratings_count) || 0),
  };
};

export const deleteCourseRating = async (
  courseId: string,
  version: number,
): Promise<CourseRatingResult> => {
  const normalizedCourseId = numericRouteId(courseId, 'COURSE');
  if (!Number.isSafeInteger(version) || version < 1) {
    throw new Error('INVALID_COURSE_RATING_VERSION');
  }
  const data = payload(
    await publicRequest.delete(`courses/${normalizedCourseId}/rate`, {
      data: {version: Math.max(1, Math.floor(version))},
    }),
  );
  const average = Number(data.average_rating);
  return {
    rating: null,
    version: Math.max(0, Number(data.version) || 0),
    averageRating: average > 0 ? average : null,
    ratingsCount: Math.max(0, Number(data.ratings_count) || 0),
  };
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
