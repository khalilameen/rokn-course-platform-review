import {LOCAL_DEMO_ENABLED} from '../../../config/runtime';
import {publicRequest} from '../../../constants/api';
import {getDemoCoursePlanCode} from '../../../services/demoExperience';
import {createDemoCourse} from '../demoCourse';
import type {
  AttachmentPlatform,
  CourseAttachment,
  CourseLearningData,
  CourseLearningModule,
  CourseProject,
  CourseReel,
  ProjectStatus,
} from '../types';
import {retryPendingSectionCompletions} from './playback';
import {retryPendingProjectSubmissions} from './projects';
import {
  asArray,
  asRecord,
  DataRecord,
  explicitBoolean,
  qualityOptions,
  qualitySources,
  valueAsBoolean,
  valueAsString,
} from './shared';

type CoursePayloadDto = DataRecord & {
  data?: CoursePayloadDto;
  course?: CoursePayloadDto;
  entitlement?: CoursePayloadDto;
  enrollment?: CoursePayloadDto;
  metadata?: CoursePayloadDto;
  content?: CoursePayloadDto;
  project?: CoursePayloadDto;
  sectionable?: CoursePayloadDto;
  latest_submission?: CoursePayloadDto;
  evaluation?: CoursePayloadDto;
  user_evaluation?: CoursePayloadDto;
  video?: CoursePayloadDto;
  module?: CoursePayloadDto;
  modules?: CoursePayloadDto[];
  sections?: CoursePayloadDto[];
};

const courseRecord = (value: unknown): CoursePayloadDto =>
  asRecord(value) as CoursePayloadDto;

const normalisePlatform = (value?: unknown): AttachmentPlatform => {
  const platform = valueAsString(value).toLowerCase();
  if (['desktop', 'computer', 'pc', 'windows', 'mac'].includes(platform)) {
    return 'computer';
  }
  if (['mobile', 'phone'].includes(platform)) {
    return 'mobile';
  }
  if (['app', 'application'].includes(platform)) {
    return 'app';
  }
  if (['file', 'download'].includes(platform)) {
    return 'file';
  }
  return 'any';
};

const mapAttachment = (
  raw: CoursePayloadDto,
  fallbackPlatform: AttachmentPlatform = 'any',
  fallbackId = 'attachment',
): CourseAttachment | null => {
  const url =
    raw?.file_url || raw?.url || raw?.link || raw?.download_url || raw?.file;
  if (!url) {
    return null;
  }
  const rawPlatform = normalisePlatform(raw?.platform);
  return {
    id: valueAsString(raw?.id, fallbackId),
    title: valueAsString(raw?.title || raw?.name, 'ملف مرفق'),
    url: valueAsString(url),
    fileType:
      raw.file_type || raw.type
        ? valueAsString(raw.file_type || raw.type)
        : undefined,
    fileSize:
      raw.file_size || raw.size
        ? valueAsString(raw.file_size || raw.size)
        : undefined,
    platform: rawPlatform === 'any' ? fallbackPlatform : rawPlatform,
  };
};

const mapAttachments = (
  rawAttachments: unknown,
  platform: AttachmentPlatform,
  fallbackLink?: string,
  moduleId?: string,
): CourseAttachment[] => {
  const attachments = asArray<CoursePayloadDto>(rawAttachments)
    .map((item, index) =>
      mapAttachment(item, platform, `${moduleId || 'module'}-${index + 1}`),
    )
    .filter(Boolean) as CourseAttachment[];

  if (fallbackLink && !attachments.some(item => item.url === fallbackLink)) {
    attachments.push({
      id: `${moduleId || 'module'}-link`,
      title:
        platform === 'computer'
          ? 'رابط ملفات العمل على الكمبيوتر'
          : 'ملفات الوحدة',
      url: fallbackLink,
      platform,
    });
  }
  return attachments;
};

const sectionType = (section: CoursePayloadDto): string =>
  valueAsString(
    section?.type || section?.section_type || section?.content?.type,
    'lesson',
  ).toLowerCase();

const getVideoUrl = (content: CoursePayloadDto): string =>
  valueAsString(
    content?.bunny_video_url ||
      content?.video_url ||
      content?.video_link ||
      content?.stream_url ||
      content?.url,
  );

const getFallbackVideoUrl = (content: CoursePayloadDto): string =>
  valueAsString(
    content?.fallback_video_url ||
      content?.backup_video_url ||
      content?.alternate_video_url ||
      content?.cdn_fallback_url ||
      content?.video?.fallback_url,
  );

const mapProject = (
  section: CoursePayloadDto,
  moduleId: string,
  moduleAttachments: CourseAttachment[],
): CourseProject => {
  const content = courseRecord(
    section.content || section.project || section.sectionable,
  );
  const evaluation =
    section?.latest_submission ||
    content?.latest_submission ||
    section?.evaluation ||
    content?.user_evaluation ||
    content?.evaluation;
  const backendStatus = valueAsString(
    evaluation?.status || section?.status || content?.status,
    evaluation?.passed
      ? 'passed'
      : evaluation
      ? 'needs_retry'
      : 'not_submitted',
  ).toLowerCase();
  const rawStatus: ProjectStatus =
    backendStatus === 'pending' || backendStatus === 'reviewing'
      ? 'reviewing'
      : backendStatus === 'passed' || evaluation?.can_continue === true
      ? 'passed'
      : backendStatus === 'needs_resubmission' ||
        backendStatus === 'needs_retry' ||
        evaluation?.needs_resubmission === true
      ? 'needs_retry'
      : 'not_submitted';

  const projectAttachments = mapAttachments(
    section?.attachments || content?.attachments,
    'any',
    undefined,
    moduleId,
  );

  return {
    id: valueAsString(
      content?.id || section?.project_id || section?.id,
      `${moduleId}-project`,
    ),
    sectionId: valueAsString(section?.id),
    moduleId,
    title: valueAsString(section?.title || content?.title, 'مشروع العبور'),
    requirements: valueAsString(
      content?.requirements_text ||
        content?.requirements ||
        content?.description ||
        section?.description,
      'ارفع محاولتك العملية. المطلوب مجهود حقيقي، وليس إجابة مثالية.',
    ),
    status: rawStatus,
    isGraduationProject: Boolean(
      content?.is_graduation_project || section?.is_graduation_project,
    ),
    attachments: [...moduleAttachments, ...projectAttachments],
  };
};

export const mapCoursePayload = (
  rawPayload: unknown,
): CourseLearningData | null => {
  const root = courseRecord(rawPayload);
  const envelope = courseRecord(root.data?.data || root.data || rawPayload);
  const rawCourseValue = envelope.course || envelope;
  if (
    !rawCourseValue ||
    typeof rawCourseValue !== 'object' ||
    Array.isArray(rawCourseValue)
  ) {
    return null;
  }
  const rawCourse = courseRecord(rawCourseValue);

  const accessType = valueAsString(
    envelope?.access_type ??
      envelope?.accessType ??
      envelope?.entitlement?.access_type ??
      envelope?.entitlement?.accessType ??
      rawCourse?.access_type ??
      rawCourse?.accessType ??
      rawCourse?.enrollment?.access_type ??
      rawCourse?.enrollment?.accessType ??
      rawCourse?.entitlement?.access_type ??
      rawCourse?.entitlement?.accessType,
  )
    .trim()
    .toLowerCase();
  const chatAvailable = explicitBoolean(
    envelope?.chat_available,
    envelope?.chatAvailable,
    envelope?.entitlement?.chat_available,
    envelope?.entitlement?.chatAvailable,
    rawCourse?.chat_available,
    rawCourse?.chatAvailable,
    rawCourse?.metadata?.chat_available,
    rawCourse?.metadata?.chatAvailable,
    rawCourse?.enrollment?.chat_available,
    rawCourse?.enrollment?.chatAvailable,
    rawCourse?.entitlement?.chat_available,
    rawCourse?.entitlement?.chatAvailable,
  );
  const certificateAvailable = explicitBoolean(
    envelope?.certificate_available,
    envelope?.certificateAvailable,
    envelope?.entitlement?.certificate_available,
    envelope?.entitlement?.certificateAvailable,
    rawCourse?.certificate_available,
    rawCourse?.certificateAvailable,
    rawCourse?.metadata?.certificate_available,
    rawCourse?.metadata?.certificateAvailable,
    rawCourse?.enrollment?.certificate_available,
    rawCourse?.enrollment?.certificateAvailable,
    rawCourse?.entitlement?.certificate_available,
    rawCourse?.entitlement?.certificateAvailable,
  );

  let rawModules = asArray<CoursePayloadDto>(rawCourse.modules);
  if (!rawModules.length && asArray(rawCourse.sections).length) {
    const byModule = new Map<string, CoursePayloadDto[]>();
    asArray<CoursePayloadDto>(rawCourse.sections).forEach(section => {
      const moduleId = valueAsString(section.module_id, 'course');
      byModule.set(moduleId, [...(byModule.get(moduleId) || []), section]);
    });
    rawModules = Array.from(byModule.entries()).map(
      ([moduleId, sections], index) => ({
        id: moduleId,
        title: sections[0]?.module?.title || `الوحدة ${index + 1}`,
        order: index + 1,
        sections,
      }),
    );
  }

  let reelNumber = 0;
  const modules: CourseLearningModule[] = rawModules
    .sort((a, b) => Number(a?.order || 0) - Number(b?.order || 0))
    .map((module, moduleIndex) => {
      const moduleId = valueAsString(module?.id, `module-${moduleIndex + 1}`);
      const platform = normalisePlatform(module?.attachment_platform);
      const attachments = mapAttachments(
        module?.attachments,
        platform,
        valueAsString(module?.attachments_link) || undefined,
        moduleId,
      );
      const sections = asArray<CoursePayloadDto>(module?.sections).sort(
        (a, b) => Number(a?.order || 0) - Number(b?.order || 0),
      );
      const reels: CourseReel[] = [];
      let project: CourseProject | undefined;
      let progressionBlocked = valueAsBoolean(module?.is_locked);

      sections.forEach(section => {
        const type = sectionType(section);
        if (type === 'project') {
          project = mapProject(section, moduleId, attachments);
          return;
        }
        if (!['lesson', 'video', 'reel'].includes(type)) {
          return;
        }
        const content = courseRecord(
          section.content || section.sectionable || section,
        );
        const videoUrl = getVideoUrl(content);
        const videoQualitySources = qualitySources(content);
        const fallbackVideoUrl =
          getFallbackVideoUrl(content) || getFallbackVideoUrl(section);
        const sectionLocked =
          progressionBlocked || !videoUrl || valueAsBoolean(section?.is_locked);
        reelNumber += 1;
        const lessonId = valueAsString(
          content?.id || section?.lesson_id || section?.id,
        );
        reels.push({
          id: lessonId,
          lessonId,
          sectionId: valueAsString(section?.id, lessonId),
          moduleId,
          title: valueAsString(
            section?.title || content?.title,
            `الخطوة ${reelNumber}`,
          ),
          caption: valueAsString(content?.description || section?.description),
          // Locked lessons keep outline metadata without a playable source.
          videoUrl: videoUrl || '',
          fallbackVideoUrl:
            fallbackVideoUrl && fallbackVideoUrl !== videoUrl
              ? fallbackVideoUrl
              : undefined,
          qualitySources: videoQualitySources,
          thumbnailUrl:
            valueAsString(
              content.thumbnail_url ||
                content.thumbnail ||
                section.thumbnail_url,
            ) || undefined,
          durationSeconds:
            Number(content?.duration_seconds) ||
            (Number(content?.duration_minutes) || 0) * 60 ||
            undefined,
          availableQualities: videoUrl
            ? qualityOptions(content, videoUrl, videoQualitySources)
            : [],
          isPreview:
            Boolean(videoUrl) &&
            valueAsBoolean(
              section?.is_preview,
              section?.preview,
              section?.is_free_preview,
              section?.is_free,
              content?.is_preview,
              content?.preview,
              content?.is_free_preview,
              content?.is_free,
            ),
          isLocked: sectionLocked,
          isCompleted: Boolean(section?.is_completed || section?.completed),
          reelNumber,
        });
        progressionBlocked = progressionBlocked || sectionLocked;
      });

      return {
        id: moduleId,
        title: valueAsString(module?.title, `الوحدة ${moduleIndex + 1}`),
        description: valueAsString(module?.description),
        order: Number(module?.order || moduleIndex + 1),
        isLocked:
          valueAsBoolean(module?.is_locked) ||
          Boolean(reels.length && reels.every(reel => reel.isLocked)),
        attachments,
        reels,
        project,
      };
    })
    .filter(module => module.reels.length || module.project);

  if (!modules.some(module => module.reels.length)) {
    return null;
  }

  return {
    id: valueAsString(rawCourse.id, 'course'),
    title: valueAsString(rawCourse.title || rawCourse.name, 'الكورس'),
    image:
      valueAsString(rawCourse.image || rawCourse.thumbnail_url) || undefined,
    totalReels: reelNumber,
    modules,
    accessType: accessType || undefined,
    chatAvailable,
    certificateAvailable,
  };
};

export const loadCourseLearningData = async (
  courseId?: string | number,
  options: {reconcilePending?: boolean} = {},
): Promise<{
  course: CourseLearningData;
  usedFallback: boolean;
  error?: string;
}> => {
  if (
    LOCAL_DEMO_ENABLED &&
    (!courseId || String(courseId).startsWith('demo'))
  ) {
    const demoId = courseId ? String(courseId) : 'demo-freelance-course';
    const planCode = await getDemoCoursePlanCode(demoId);
    return {
      course: createDemoCourse({
        chatAvailable: planCode === 'guided' || planCode === 'mentor',
        accessType:
          planCode === 'grant'
            ? 'course_code_grant'
            : planCode
            ? 'paid'
            : 'preview',
      }),
      usedFallback: true,
    };
  }
  if (!courseId) {
    throw new Error('رابط الكورس غير مكتمل');
  }
  if (String(courseId).startsWith('demo')) {
    throw new Error('الكورس ده مش متاح دلوقتي');
  }

  // Do not block opening the course while a previously interrupted project
  // upload is reconciled in the background.
  if (options.reconcilePending !== false) {
    retryPendingProjectSubmissions().catch(() => undefined);
    retryPendingSectionCompletions().catch(() => undefined);
  }

  try {
    const response = await publicRequest.get(`courses/${courseId}/details`);
    const mapped = mapCoursePayload(response.data);
    if (!mapped) {
      throw new Error('لم تُنشر خطوات هذا الكورس بعد');
    }
    return {course: mapped, usedFallback: false};
  } catch (caught: unknown) {
    const failure = asRecord(caught);
    const failureData = asRecord(failure.data);
    const error = {
      data: {message: valueAsString(failureData.message)},
      message: valueAsString(failure.message),
    };
    throw new Error(
      error?.data?.message || error?.message || 'تعذّر تحميل الكورس الآن',
    );
  }
};
