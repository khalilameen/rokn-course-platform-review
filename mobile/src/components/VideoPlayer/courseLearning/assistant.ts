import {publicRequest} from '../../../constants/api';
import {isLocalDemoId} from '../../../config/runtime';
import {isProductFeatureEnabled} from '../../../services/productFeatures';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../../constants/helpers';
import {openExternalUrlOnce} from '../../../services/systemActions';
import {cleanUnicodeText} from '../../../utils/unicodeText';
import {includesCourseAssistant} from '../courseEntitlements';
import type {ChatMessage, CourseLearningData, CourseReel} from '../types';
import {asArray, asRecord, valueAsBoolean, valueAsString} from './shared';

// The send endpoint only admits the durable turn; the provider work runs on
// its own queue.  A slow web response must not keep the composer frozen for a
// full provider timeout.  After this bound the client reconciles the same
// immutable request id through the turn endpoint, so there is no second debit.
const COURSE_CHAT_REQUEST_TIMEOUT_MS = 15_000;
const assistantAttachmentOpenFlights = new Map<string, Promise<void>>();

const demoAssistantReply = (message: string, reel?: CourseReel) => {
  const question = message.trim();
  const normalized = question.toLocaleLowerCase('ar');
  if (/^(سلام|أهلا|اهلا|هاي|hello)/i.test(normalized)) {
    return 'أهلًا\nاكتب المشكلة أو الهدف مباشرة\nسأجيبك باختصار';
  }
  if (normalized.includes('سعر') || normalized.includes('تسعير')) {
    return 'اربط السعر بنطاق واضح\nما الذي ستسلّمه\nعدد جولات التعديل\nموعد التسليم\nأي إضافة خارج الاتفاق لها سعر منفصل';
  }
  if (normalized.includes('عميل') || normalized.includes('عرض')) {
    return 'اكتب رسالة قصيرة\nالمشكلة التي فهمتها\nالنتيجة التي ستقدمها\nالخطوة التالية وموعدها';
  }
  if (
    normalized.includes('مشروع') ||
    normalized.includes('تسليم') ||
    normalized.includes('ارفع')
  ) {
    return 'ارفع لقطة واضحة للعمل أو ملف النتيجة\nواكتب سطرين عما نفذته\nلا نطلب عملًا كاملًا\nالمهم أن يظهر مجهودك بوضوح';
  }
  if (normalized.includes('بورتفوليو') || normalized.includes('معرض')) {
    return 'اعرض المشروع كقصة قرار\nالمشكلة\nدورك\nأهم قرار اتخذته\nالنتيجة\nصور قليلة قوية أفضل من ألبوم طويل';
  }
  if (reel?.caption) {
    return `فكرة هذا المقطع\n${reel.caption}\nطبّقها على حالة حقيقية`;
  }
  return 'اكتب هدفك والمشكلة بوضوح\nمثال\nأريد تنفيذ كذا لكنني توقفت عند كذا';
};

export const courseIncludesAssistant = (
  course: Pick<CourseLearningData, 'accessType' | 'chatAvailable' | 'isDemo'>,
) => includesCourseAssistant(course);

export const loadCourseAssistantHistory = async (
  courseId: string,
  lessonId?: string,
): Promise<ChatMessage[]> => {
  if (isLocalDemoId(courseId)) return [];
  const response = await publicRequest.get('course-chat/messages', {
    params: {
      course_id: courseId,
      lesson_id: lessonId,
      per_page: 20,
    },
  });
  return asArray<Record<string, unknown>>(
    asRecord(response?.data?.data).messages,
  ).flatMap(message => {
    const role = valueAsString(message.role);
    const status = valueAsString(message.delivery_status);
    const id = valueAsString(message.id);
    if (
      !id ||
      !['user', 'assistant'].includes(role) ||
      !['queued', 'sent', 'streaming', 'completed', 'failed', 'cancelled'].includes(
        status,
      )
    ) return [];
    const createdAt = Date.parse(valueAsString(message.created_at));
    const text = cleanUnicodeText(valueAsString(message.text)) ||
      (role === 'assistant' && status === 'failed'
        ? 'لم تكتمل الإجابة\nاستعد الرد'
        : '');
    const attachments = asArray<Record<string, unknown>>(message.attachments).map(file => ({
      uri: '',
      name: cleanUnicodeText(valueAsString(file.name, 'مرفق'), false),
      type: valueAsString(file.mime_type, 'application/octet-stream'),
      size: Number(file.size_bytes) || undefined,
      uploadId: valueAsString(file.id),
      serverId: valueAsString(file.id),
      downloadUrl: valueAsString(file.download_url) || undefined,
      downloadExpiresAt: valueAsString(file.download_url_expires_at) || undefined,
    }));
    return [{
      id,
      role: role as ChatMessage['role'],
      text,
      createdAt: Number.isFinite(createdAt) ? createdAt : Date.now(),
      pending: role === 'assistant' && ['queued', 'sent', 'streaming'].includes(status),
      clientRequestId: valueAsString(message.client_request_id) || undefined,
      deliveryStatus: status as ChatMessage['deliveryStatus'],
      errorCode: valueAsString(message.error_code) || undefined,
      contextEligible: valueAsBoolean(message.context_eligible),
      attachments,
    }];
  });
};

const openCourseAssistantAttachmentInternal = async (
  file: import('../types').ChatAttachmentDraft,
  boundary: Awaited<ReturnType<typeof captureAccountSessionBoundary>>,
) => {
  let candidate = file;
  const expiresAt = Date.parse(String(candidate.downloadExpiresAt || ''));
  if (
    !candidate.downloadUrl ||
    !Number.isFinite(expiresAt) ||
    expiresAt <= Date.now() + 15000
  ) {
    if (!candidate.serverId) throw new Error('CHAT_ATTACHMENT_UNAVAILABLE');
    const response = await publicRequest.get(
      `ai-input-attachments/${encodeURIComponent(candidate.serverId)}`,
    );
    assertAccountSessionBoundary(boundary);
    const refreshed = asRecord(asRecord(response?.data).data);
    candidate = {
      ...candidate,
      downloadUrl: valueAsString(refreshed.download_url) || undefined,
      downloadExpiresAt:
        valueAsString(refreshed.download_url_expires_at) || undefined,
    };
  }
  if (!candidate.downloadUrl) throw new Error('CHAT_ATTACHMENT_UNAVAILABLE');
  assertAccountSessionBoundary(boundary);
  await openExternalUrlOnce(
    candidate.downloadUrl,
    undefined,
    `course-chat-attachment:${
      file.serverId || file.uploadId || file.downloadUrl || ''
    }`,
  );
};

export const openCourseAssistantAttachment = (
  file: import('../types').ChatAttachmentDraft,
) =>
  (async () => {
    const boundary = await captureAccountSessionBoundary();
    const attachmentKey = String(
      file.serverId || file.uploadId || file.downloadUrl || '',
    ).trim();
    if (!attachmentKey) {
      return Promise.reject(new Error('CHAT_ATTACHMENT_UNAVAILABLE'));
    }
    const key = `${boundary.scope}:${attachmentKey}`;
    const existing = assistantAttachmentOpenFlights.get(key);
    if (existing) return existing;
    const flight = openCourseAssistantAttachmentInternal(
      file,
      boundary,
    ).finally(() => {
      if (assistantAttachmentOpenFlights.get(key) === flight) {
        assistantAttachmentOpenFlights.delete(key);
      }
    });
    assistantAttachmentOpenFlights.set(key, flight);
    return flight;
  })();

export const pollCourseAssistantTurn = async (
  clientRequestId: string,
): Promise<{
  text: string;
  offline: boolean;
  blocked?: boolean;
  unavailable?: boolean;
  clientRequestId?: string;
  turnStatus?: ChatMessage['deliveryStatus'];
  code?: string;
  retryAfterSeconds?: number;
  partial?: boolean;
}> => {
  let response: Awaited<ReturnType<typeof publicRequest.get>>;
  try {
    response = await publicRequest.get(
      `course-chat/turns/${encodeURIComponent(clientRequestId)}`,
      {timeout: 6000},
    );
  } catch (error: unknown) {
    const failure = asRecord(error);
    const errorResponse = asRecord(failure.response);
    const status = Number(errorResponse.status || failure.status || 0);
    if (status === 404 || status === 410) {
      return {
        text: 'لم يصل السؤال إلى Rokn AI\nأرسله مرة أخرى',
        offline: false,
        unavailable: true,
        clientRequestId,
        turnStatus: 'failed',
        code: 'chat_turn_not_found',
      };
    }
    // A status read cannot invalidate a turn already accepted by the server.
    // Keep the same logical id and let the bounded polling loop recover after
    // a mobile-network hand-off instead of showing a false failed answer.
    return {
      text: 'الرد قيد التجهيز\nسيظهر عند عودة الاتصال',
      offline: true,
      unavailable: true,
      clientRequestId,
      turnStatus: 'queued',
      code: 'chat_answer_in_progress',
      retryAfterSeconds: 5,
    };
  }
  const responsePayload = asRecord(asRecord(response).data);
  const data = asRecord(responsePayload.data);
  const status = valueAsString(data.turn_status);
  const turnStatus = [
    'queued',
    'sent',
    'streaming',
    'completed',
    'failed',
    'cancelled',
  ].includes(status)
    ? (status as ChatMessage['deliveryStatus'])
    : 'failed';

  const code = valueAsString(responsePayload.code).toLowerCase();
  const blocked = [
    'chat_upgrade_required',
    'chat_plan_limit_reached',
    'course_not_available',
    'course_access_required',
    'chat_disabled_for_course',
  ].includes(code);

  return {
    text:
      (blocked && code === 'chat_plan_limit_reached'
        ? 'استخدمت مساحة الأسئلة في فئتك الحالية\nيمكنك زيادتها بدفع فرق الفئة فقط'
        : cleanUnicodeText(valueAsString(data.message))) ||
      (turnStatus === 'completed'
        ? ''
        : 'الرد قيد التجهيز\nسيظهر خلال لحظات'),
    offline: false,
    blocked,
    unavailable: !blocked && data.unavailable === true,
    clientRequestId:
      valueAsString(data.client_request_id) || clientRequestId,
    turnStatus,
    code: code || undefined,
    retryAfterSeconds: Math.max(0, Number(data.retry_after_seconds) || 0),
    partial: valueAsBoolean(data.partial),
  };
};

export const cancelCourseAssistantTurn = async (clientRequestId: string): Promise<boolean> => {
  try {
    await publicRequest.delete(
      `course-chat/turns/${encodeURIComponent(clientRequestId)}`,
      {timeout: 12000},
    );
    return true;
  } catch {
    return false;
  }
};

export const askCourseAssistant = async ({
  course,
  reel,
  message,
  clientRequestId,
  onRequestStart,
  attachmentIds = [],
}: {
  course: CourseLearningData;
  reel?: CourseReel;
  message: string;
  clientRequestId?: string;
  onRequestStart?: () => void;
  attachmentIds?: string[];
}): Promise<{
  text: string;
  offline: boolean;
  blocked?: boolean;
  unavailable?: boolean;
  clientRequestId?: string;
  turnStatus?: ChatMessage['deliveryStatus'];
  code?: string;
  retryAfterSeconds?: number;
  partial?: boolean;
}> => {
  if (!courseIncludesAssistant(course)) {
    return {
      text: 'Rokn AI غير مشمول في وصولك الحالي',
      offline: true,
      blocked: true,
      code: 'chat_upgrade_required',
    };
  }
  const courseId = course.id;
  if (isLocalDemoId(courseId)) {
    return {text: demoAssistantReply(message, reel), offline: true};
  }
  if (!isLocalDemoId(courseId)) {
    if (!(await isProductFeatureEnabled('ai_chat'))) {
      return {
        text: 'Rokn AI متوقف مؤقتًا للصيانة\nتقدمك محفوظ\nحاول لاحقًا',
        offline: true,
        unavailable: true,
        code: 'ai_feature_unavailable',
      };
    }
    try {
      onRequestStart?.();
      const response = await publicRequest.post(
        `courses/${courseId}/chat`,
        {
          message,
          client_request_id: clientRequestId,
          lesson_id: reel?.lessonId,
          reel_title: reel?.title,
          attachment_ids: attachmentIds,
        },
        {timeout: COURSE_CHAT_REQUEST_TIMEOUT_MS},
      );
      const text =
        response?.data?.data?.message ||
        response?.data?.data?.reply ||
        response?.data?.message;
      if (text) {
        const data = asRecord(response?.data?.data);
        const unavailable = data.unavailable === true;
        const code = valueAsString(response?.data?.code).toLowerCase();
        const responseStatus = valueAsString(data.turn_status);
        const turnStatus = [
          'queued',
          'sent',
          'streaming',
          'completed',
          'failed',
          'cancelled',
        ].includes(responseStatus)
          ? (responseStatus as ChatMessage['deliveryStatus'])
          : unavailable
          ? 'failed'
          : 'completed';
        return {
          text: cleanUnicodeText(valueAsString(text)),
          offline: false,
          unavailable,
          clientRequestId:
            valueAsString(data.client_request_id) || clientRequestId,
          turnStatus,
          code: code || undefined,
          retryAfterSeconds: Math.max(0, Number(data.retry_after_seconds) || 0),
          partial: valueAsBoolean(data.partial),
        };
      }
    } catch (error: unknown) {
      const failure = asRecord(error);
      const response = asRecord(failure.response);
      const status = Number(response.status || failure.status || 0);
      const errorCode = valueAsString(
        asRecord(failure.data).code,
        valueAsString(asRecord(response.data).code),
      ).toLowerCase();
      if (errorCode === 'chat_upgrade_required') {
        return {
          text: 'Rokn AI غير مشمول في المنحة\nيمكنك إضافته بالترقية',
          offline: false,
          blocked: true,
          code: errorCode,
        };
      }
      if (errorCode === 'chat_plan_limit_reached') {
        return {
          text: 'استخدمت مساحة الأسئلة في فئتك الحالية\nيمكنك زيادتها بدفع فرق الفئة فقط',
          offline: false,
          blocked: true,
          code: errorCode,
        };
      }
      if (
        [
          'course_not_available',
          'course_access_required',
          'chat_disabled_for_course',
        ].includes(errorCode)
      ) {
        return {
          text:
            errorCode === 'course_not_available'
              ? 'هذا الكورس غير متاح الآن'
              : errorCode === 'course_access_required'
              ? 'افتح الكورس أولًا لاستخدام Rokn AI'
              : 'Rokn AI غير متاح في هذا الكورس',
          offline: false,
          blocked: true,
          code: errorCode,
          clientRequestId,
          turnStatus: 'failed',
        };
      }
      if (errorCode === 'chat_daily_limit_reached') {
        return {
          text: 'اكتملت أسئلة اليوم\nيمكنك المتابعة غدًا',
          offline: false,
          unavailable: true,
          code: errorCode,
          clientRequestId,
          turnStatus: 'failed',
        };
      }
      if (errorCode === 'chat_rate_limited') {
        return {
          text: 'انتظر قليلًا\nثم أرسل سؤالك مرة أخرى',
          offline: false,
          unavailable: true,
          code: errorCode,
          clientRequestId,
          turnStatus: 'failed',
        };
      }

      // A timeout or server/gateway disconnect does not prove that the paid
      // turn was rejected. Keep the immutable request id and recover through
      // the status endpoint; resubmitting a fresh turn here could debit the
      // learner and call the provider twice for one visible question.
      if (
        clientRequestId &&
        (status === 0 || status === 408 || status >= 500)
      ) {
        return {
          text: 'نجهز إجابتك الآن\nستظهر خلال لحظات',
          offline: status === 0,
          unavailable: false,
          clientRequestId,
          turnStatus: 'queued',
          code: 'chat_answer_in_progress',
          retryAfterSeconds: 2,
        };
      }

      return {
        text: 'Rokn AI غير متاح الآن\nأكمل المقطع ومكانك محفوظ\nحاول لاحقًا',
        offline: true,
        unavailable: true,
        clientRequestId,
        code:
          errorCode ||
          (valueAsString(failure.code).toUpperCase() === 'ECONNABORTED'
            ? 'client_timeout'
            : 'network_unavailable'),
        turnStatus: 'failed',
      };
    }
  }

  return {
    text: 'Rokn AI غير متاح الآن\nأكمل المقطع ومكانك محفوظ\nحاول لاحقًا',
    offline: true,
    unavailable: true,
    clientRequestId,
    code: 'ai_temporarily_unavailable',
    turnStatus: 'failed',
  };
};

export const uploadCourseAssistantAttachment = async ({
  courseId,
  file,
}: {
  courseId: string;
  file: import('../types').ChatAttachmentDraft;
}): Promise<string> => {
  const body = new FormData();
  body.append('client_upload_id', file.uploadId);
  body.append('attachment', {
    uri: file.uri,
    name: file.name,
    type: file.type || 'application/octet-stream',
  } as unknown as Blob);
  const response = await publicRequest.post(
    `courses/${courseId}/chat/attachments`,
    body,
    {headers: {'Content-Type': 'multipart/form-data'}, timeout: 45000},
  );
  const id = valueAsString(response?.data?.data?.id);
  if (!id) throw new Error('attachment_upload_failed');
  return id;
};
