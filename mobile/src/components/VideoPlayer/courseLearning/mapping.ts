import {isLocalDemoId, LOCAL_DEMO_ENABLED} from '../../../config/runtime';
import {publicRequest} from '../../../constants/api';
import {getDemoCoursePlanCode} from '../../../services/demoExperience';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import {deadlineFromServerTtl} from '../../../utils/serverClock';
import {createDemoCourse} from '../demoCourse';
import type {
  AttachmentPlatform,
  CourseAttachment,
  CourseLearningData,
  CourseLearningModule,
  CourseProject,
  CourseQuiz,
  CourseReel,
  ProjectStatus,
  VideoQuality,
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
  feedback_thread?: CoursePayloadDto;
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
  courseId?: string,
  moduleId?: string,
): CourseAttachment | null => {
  const url =
    raw?.file_url || raw?.url || raw?.link || raw?.download_url || raw?.file;
  if (!url) {
    return null;
  }
  const rawPlatform = normalisePlatform(raw?.platform);
  const expiresInSeconds = Number(raw?.expires_in_seconds);
  const localExpiresAt = deadlineFromServerTtl(expiresInSeconds);
  const fileSizeBytes = Number(raw.file_size_bytes);
  return {
    id: valueAsString(raw?.id, fallbackId),
    title: valueAsString(raw?.title || raw?.name, 'ملف مرفق'),
    url: valueAsString(url),
    fileType:
      raw.file_type || raw.type
        ? valueAsString(raw.file_type || raw.type)
        : undefined,
    mimeType: raw.mime_type ? valueAsString(raw.mime_type) : undefined,
    fileSize:
      raw.file_size || raw.size
        ? valueAsString(raw.file_size || raw.size)
        : undefined,
    fileSizeBytes:
      Number.isFinite(fileSizeBytes) && fileSizeBytes > 0
        ? fileSizeBytes
        : undefined,
    downloadVersion: valueAsString(raw.download_version) || undefined,
    platform: rawPlatform === 'any' ? fallbackPlatform : rawPlatform,
    courseId,
    moduleId,
    temporary: valueAsBoolean(raw.download_url_is_temporary),
    expiresAt:
      localExpiresAt ||
      valueAsString(raw.download_url_expires_at || raw.expires_at) ||
      undefined,
  };
};

const mapAttachments = (
  rawAttachments: unknown,
  platform: AttachmentPlatform,
  fallbackLink?: string,
  moduleId?: string,
  courseId?: string,
): CourseAttachment[] => {
  const attachments = asArray<CoursePayloadDto>(rawAttachments)
    .map((item, index) =>
      mapAttachment(
        item,
        platform,
        `${moduleId || 'module'}-${index + 1}`,
        courseId,
        moduleId,
      ),
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
      courseId,
      moduleId,
      external: true,
    });
  }
  return attachments;
};

const sectionType = (section: CoursePayloadDto): string =>
  valueAsString(
    section?.type || section?.section_type || section?.content?.type,
    'lesson',
  ).toLowerCase();

const stableContractId = (value: unknown): string => {
  const id = valueAsString(value).trim();
  // Backend ids are numeric today, while imported/demo content can use stable
  // slugs or UUIDs. Identity and uniqueness matter here, not storage shape.
  return id;
};

/** Reject a paid learning map if any step would be dropped or overwrite one beside it. */
const hasValidLearningContract = (modules: CoursePayloadDto[]): boolean => {
  if (!modules.length) return false;
  const moduleIds = new Set<string>();
  const sectionIds = new Set<string>();
  const contentIds = new Set<string>();

  return modules.every(module => {
    const moduleId = stableContractId(module?.id);
    if (!moduleId || moduleIds.has(moduleId)) return false;
    moduleIds.add(moduleId);

    const sections = asArray<CoursePayloadDto>(module?.sections);
    return sections.length > 0 && sections.every(section => {
      const type = sectionType(section);
      if (!['lesson', 'video', 'reel', 'quiz', 'project'].includes(type)) {
        return false;
      }
      const sectionId = stableContractId(section?.id);
      if (!sectionId || sectionIds.has(sectionId)) return false;
      sectionIds.add(sectionId);

      const content = courseRecord(
        section.content || section.project || section.sectionable,
      );
      const contentId = stableContractId(
        content?.id ||
          section?.content_id ||
          section?.lesson_id ||
          section?.quiz_id ||
          section?.project_id,
      );
      const identityType = ['lesson', 'video', 'reel'].includes(type)
        ? 'lesson'
        : type;
      const contentKey = `${identityType}:${contentId}`;
      if (!contentId || contentIds.has(contentKey)) return false;
      contentIds.add(contentKey);
      return true;
    });
  });
};

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
  courseId: string,
): CourseProject | undefined => {
  const content = courseRecord(
    section.content || section.project || section.sectionable,
  );
  const lockReason = valueAsString(section?.lock_reason).trim();
  const isLocked = valueAsBoolean(section?.is_locked);
  // Public outlines deliberately omit project content. Keeping a fabricated
  // project here turned the purchase boundary into a submission gate.
  if (lockReason === 'course_purchase_required' || (!Object.keys(content).length && !isLocked)) {
    return undefined;
  }
  const evaluation =
    section?.latest_submission ||
    content?.latest_submission ||
    section?.evaluation ||
    content?.user_evaluation ||
    content?.evaluation;
  const backendStatus = valueAsString(
    evaluation?.status || section?.status || content?.status,
    valueAsBoolean(evaluation?.passed)
      ? 'passed'
      : evaluation
      ? 'needs_retry'
      : 'not_submitted',
  ).toLowerCase();
  const rawStatus: ProjectStatus =
    backendStatus === 'pending' || backendStatus === 'reviewing'
      ? 'reviewing'
      : backendStatus === 'passed' || valueAsBoolean(evaluation?.can_continue)
      ? 'passed'
      : backendStatus === 'needs_resubmission' ||
        backendStatus === 'needs_retry' ||
        valueAsBoolean(evaluation?.needs_resubmission)
      ? 'needs_retry'
      : evaluation
      ? 'reviewing'
      : 'not_submitted';

  const projectAttachments = mapAttachments(
    section?.attachments || content?.attachments,
    'any',
    undefined,
    moduleId,
    courseId,
  );
  const allAttachments = Array.from(
    new Map(
      [...moduleAttachments, ...projectAttachments].map(
        attachment =>
          [
            `${attachment.id}:${attachment.downloadVersion || attachment.url}`,
            attachment,
          ] as const,
      ),
    ).values(),
  );
  const rawThread = courseRecord(
    evaluation?.feedback_thread || content?.feedback_thread,
  );
  const feedbackLevel = valueAsString(rawThread.feedback_level);
  const rawProjectFeedback = courseRecord(content?.project_feedback);
  const projectFeedbackLevel = valueAsString(
    rawProjectFeedback.level,
    'pass_only',
  );
  const feedbackThread =
    rawThread.id && ['report', 'enhanced'].includes(feedbackLevel)
      ? {
          id: valueAsString(rawThread.id),
          feedbackLevel: feedbackLevel as 'report' | 'enhanced',
          canReply: valueAsBoolean(rawThread.can_reply),
          status: valueAsString(rawThread.status, 'ready'),
          remainingMessages: Math.max(
            0,
            Number(rawThread.remaining_messages) || 0,
          ),
          messages: asArray<CoursePayloadDto>(rawThread.messages).flatMap(
            message => {
              const role = valueAsString(message.role);
              const status = valueAsString(message.status);
              if (
                !['assistant', 'user'].includes(role) ||
                ![
                  'queued',
                  'sent',
                  'streaming',
                  'completed',
                  'failed',
                  'cancelled',
                ].includes(status)
              ) {
                return [];
              }
              return [
                {
                  id: valueAsString(message.id),
                  clientRequestId:
                    valueAsString(message.client_request_id) || undefined,
                  role: role as 'assistant' | 'user',
                  status: status as
                    | 'queued'
                    | 'sent'
                    | 'streaming'
                    | 'completed'
                    | 'failed'
                    | 'cancelled',
                  text: valueAsString(message.text) || undefined,
                  createdAt: valueAsString(message.created_at) || undefined,
                  attachments: asArray<CoursePayloadDto>(message.attachments).map(file => ({
                    uri: '',
                    name: valueAsString(file.name, 'مرفق'),
                    type: valueAsString(file.mime_type, 'application/octet-stream'),
                    size: Number(file.size_bytes) || undefined,
                    uploadId: valueAsString(file.id),
                    serverId: valueAsString(file.id),
                    downloadUrl: valueAsString(file.download_url) || undefined,
                    downloadExpiresAt: valueAsString(file.download_url_expires_at) || undefined,
                  })),
                },
              ];
            },
          ),
        }
      : undefined;

  return {
    id: valueAsString(
      content?.id || section?.project_id || section?.content_id || section?.id,
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
      '',
    ),
    status: rawStatus,
    isGraduationProject: valueAsBoolean(
      content?.is_graduation_project || section?.is_graduation_project,
    ),
    attachments: allAttachments,
    isLocked,
    lockReason: lockReason || undefined,
    feedbackLevel: ['report', 'enhanced'].includes(projectFeedbackLevel)
      ? (projectFeedbackLevel as 'report' | 'enhanced')
      : 'pass_only',
    reportEnabled: valueAsBoolean(rawProjectFeedback.report_enabled),
    feedbackThread,
    submissionMaxFiles: Math.min(5, Math.max(1, Number(content?.submission_max_files) || 3)),
    submissionAllowedMimeTypes: asArray<string>(content?.submission_allowed_mime_types)
      .map(value => String(value || '').toLowerCase()).filter(Boolean),
    submissionAttachments: asArray<CoursePayloadDto>(evaluation?.attachments).map(file => ({
      uri: '',
      name: valueAsString(file.name, 'مرفق'),
      type: valueAsString(file.mime_type, 'application/octet-stream'),
      size: Number(file.size_bytes) || undefined,
      uploadId: valueAsString(file.id),
      serverId: valueAsString(file.id),
      downloadUrl: valueAsString(file.download_url) || undefined,
      downloadExpiresAt: valueAsString(file.download_url_expires_at) || undefined,
    })),
  };
};

const mapQuiz = (section: CoursePayloadDto, moduleId: string): CourseQuiz => {
  const content = courseRecord(section.content || section.sectionable);
  const timeMinutes = Number(content.time_minutes);
  const scorePercentage = Number(content.score_percentage);
  return {
    id: valueAsString(
      content.id || section.quiz_id || section.content_id || section.id,
    ),
    sectionId: valueAsString(section.id),
    moduleId,
    title: valueAsString(section.title || content.title, 'اختبار الوحدة'),
    description:
      valueAsString(content.description || section.description) || undefined,
    timeMinutes:
      Number.isFinite(timeMinutes) && timeMinutes > 0 ? timeMinutes : undefined,
    isLocked: valueAsBoolean(section.is_locked),
    passed: valueAsBoolean(content.is_passed, section.is_completed),
    scorePercentage: Number.isFinite(scorePercentage)
      ? scorePercentage
      : undefined,
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
  const chatAttachmentsEnabled = explicitBoolean(
    rawCourse?.chat_attachments_enabled,
    rawCourse?.chatAttachmentsEnabled,
    envelope?.chat_attachments_enabled,
    envelope?.chatAttachmentsEnabled,
  );
  const chatAttachmentMaxFiles = Math.min(
    5,
    Math.max(
      0,
      Number(rawCourse?.chat_attachment_max_files ?? rawCourse?.chatAttachmentMaxFiles ?? 0) || 0,
    ),
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
  const certificateIncluded = explicitBoolean(
    envelope?.certificate_included,
    envelope?.certificateIncluded,
    envelope?.entitlement?.certificate_included,
    envelope?.entitlement?.certificateIncluded,
    rawCourse?.certificate_included,
    rawCourse?.certificateIncluded,
    rawCourse?.entitlement?.certificate_included,
    rawCourse?.entitlement?.certificateIncluded,
  );

  const mappedCourseId = valueAsString(rawCourse.id).trim();
  if (!mappedCourseId) return null;
  const rawModules = asArray<CoursePayloadDto>(rawCourse.modules);
  if (!hasValidLearningContract(rawModules)) return null;

  let reelNumber = 0;
  const modules: CourseLearningModule[] = rawModules
    .sort((a, b) => Number(a?.order || 0) - Number(b?.order || 0))
    .map((module, moduleIndex) => {
      const moduleId = stableContractId(module?.id);
      const platform = normalisePlatform(module?.attachment_platform);
      const attachments = mapAttachments(
        module?.attachments,
        platform,
        valueAsString(module?.attachments_link) || undefined,
        moduleId,
        mappedCourseId,
      );
      const sections = asArray<CoursePayloadDto>(module?.sections).sort(
        (a, b) => Number(a?.order || 0) - Number(b?.order || 0),
      );
      const sectionAttachments = sections.flatMap(section =>
        mapAttachments(
          section?.attachments,
          platform,
          undefined,
          moduleId,
          mappedCourseId,
        ),
      );
      const availableAttachments = Array.from(
        new Map(
          [...attachments, ...sectionAttachments].map(
            attachment =>
              [
                `${attachment.id}:${
                  attachment.downloadVersion || attachment.url
                }`,
                attachment,
              ] as const,
          ),
        ).values(),
      );
      const reels: CourseReel[] = [];
      const quizzes: CourseQuiz[] = [];
      const projects: CourseProject[] = [];
      let progressionBlocked = valueAsBoolean(module?.is_locked);

      sections.forEach(section => {
        const type = sectionType(section);
        if (type === 'project') {
          const project = mapProject(
            section,
            moduleId,
            availableAttachments,
            mappedCourseId,
          );
          if (project) projects.push(project);
          return;
        }
        if (type === 'quiz') {
          quizzes.push(mapQuiz(section, moduleId));
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
        // The broad course response intentionally omits paid playback URLs.
        // Access is authoritative in `is_locked`; an unlocked source-less row
        // is waiting for its short-lived playback manifest, not locked.
        const sectionLocked =
          progressionBlocked || valueAsBoolean(section?.is_locked);
        const lessonId = valueAsString(
          content?.id ||
            section?.lesson_id ||
            section?.content_id ||
            section?.id,
        ).trim();
        reelNumber += 1;
        const rawDuration =
          Number(content?.duration_seconds) ||
          Number(content?.duration_minutes) * 60;
        reels.push({
          id: lessonId,
          lessonId,
          sectionId: stableContractId(section?.id),
          moduleId,
          title: valueAsString(
            section?.title || content?.title,
            `المقطع ${reelNumber}`,
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
            Number.isFinite(rawDuration) && rawDuration > 0
              ? rawDuration
              : undefined,
          availableQualities: videoUrl
            ? qualityOptions(content, videoUrl, videoQualitySources)
            : (['auto'] as VideoQuality[]),
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
          lockReason:
            valueAsString(section?.lock_reason).trim() ||
            (sectionLocked && !accessType
              ? 'course_purchase_required'
              : undefined),
          isCompleted: valueAsBoolean(
            section?.is_completed,
            section?.completed,
          ),
          reelNumber,
        });
        progressionBlocked = progressionBlocked || sectionLocked;
      });

      return {
        id: moduleId,
        title: valueAsString(module?.title, `الوحدة ${moduleIndex + 1}`),
        order: Number(module?.order || moduleIndex + 1),
        isLocked:
          valueAsBoolean(module?.is_locked) ||
          Boolean(reels.length && reels.every(reel => reel.isLocked)),
        attachments: availableAttachments,
        reels,
        quizzes,
        projects,
        project: projects[0],
      };
    })
    .filter(
      module =>
        module.reels.length ||
        module.quizzes.length ||
        Boolean(module.projects?.length),
    );

  if (!modules.some(module => module.reels.length)) {
    return null;
  }

  const rawAttachmentPrompt =
    rawCourse.attachment_prompt &&
    typeof rawCourse.attachment_prompt === 'object' &&
    !Array.isArray(rawCourse.attachment_prompt)
      ? courseRecord(rawCourse.attachment_prompt)
      : null;

  return {
    id: mappedCourseId,
    title: valueAsString(rawCourse.title || rawCourse.name, 'الكورس'),
    image:
      valueAsString(rawCourse.image || rawCourse.thumbnail_url) || undefined,
    totalReels: reelNumber,
    modules,
    accessType: accessType || undefined,
    chatAvailable,
    chatAttachmentsEnabled,
    chatAttachmentMaxFiles,
    certificateAvailable,
    certificateIncluded:
      certificateIncluded === undefined
        ? certificateAvailable
        : certificateIncluded,
    attachmentPrompt: rawAttachmentPrompt
      ? {
          enabled: valueAsBoolean(rawAttachmentPrompt.enabled),
          atSeconds: Math.max(0, Number(rawAttachmentPrompt.at_seconds) || 0),
          title: valueAsString(
            rawAttachmentPrompt.title,
            'مرفقات تساعدك في التطبيق',
          ),
          body: valueAsString(
            rawAttachmentPrompt.body,
            'هذه الوحدة تتضمن ملفات تساعدك على التطبيق',
          ),
          buttonText: valueAsString(
            rawAttachmentPrompt.button_text,
            'عرض المرفقات',
          ),
          frequency:
            valueAsString(rawAttachmentPrompt.frequency) === 'once_per_course'
              ? 'once_per_course'
              : 'once_per_module',
        }
      : undefined,
  };
};

export const loadCourseLearningData = async (
  courseId?: string | number,
  options: {reconcilePending?: boolean; signal?: AbortSignal} = {},
): Promise<{
  course: CourseLearningData;
  usedFallback: boolean;
  error?: string;
}> => {
  if (LOCAL_DEMO_ENABLED && (!courseId || isLocalDemoId(courseId))) {
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
    throw new Error('COURSE_ID_MISSING');
  }
  if (String(courseId).startsWith('demo')) {
    throw new Error('DEMO_COURSE_UNAVAILABLE');
  }

  // Do not block opening the course while a previously interrupted project
  // upload is reconciled in the background.
  if (options.reconcilePending !== false) {
    retryPendingProjectSubmissions().catch(() => undefined);
    retryPendingSectionCompletions().catch(() => undefined);
  }

  try {
    const response = await publicRequest.get(`courses/${courseId}/details`, {
      signal: options.signal,
    });
    const mapped = mapCoursePayload(response.data);
    if (!mapped) {
      throw new Error('COURSE_CONTENT_UNPUBLISHED');
    }
    return {course: mapped, usedFallback: false};
  } catch (caught: unknown) {
    const error = new Error('COURSE_LEARNING_UNAVAILABLE') as Error & {
      cause?: unknown;
      learnerMessage?: string;
    };
    error.cause = caught;
    error.learnerMessage = learnerErrorMessage(
      caught,
      'تعذّر تحميل الكورس الآن',
    );
    throw error;
  }
};
