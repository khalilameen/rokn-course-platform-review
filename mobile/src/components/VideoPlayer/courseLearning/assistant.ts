import {publicRequest} from '../../../constants/api';
import {isLocalDemoId} from '../../../config/runtime';
import {isProductFeatureEnabled} from '../../../services/productFeatures';
import {includesCourseAssistant} from '../courseEntitlements';
import type {ChatMessage, CourseLearningData, CourseReel} from '../types';
import {asArray, asRecord, valueAsBoolean, valueAsString} from './shared';

const COURSE_CHAT_REQUEST_TIMEOUT_MS = 60_000;

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
    const text = valueAsString(message.text) ||
      (role === 'assistant' && status === 'failed'
        ? 'لم تكتمل الإجابة\nاستعد الرد'
        : '');
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
    }];
  });
};

export const askCourseAssistant = async ({
  course,
  reel,
  message,
  clientRequestId,
  onRequestStart,
}: {
  course: CourseLearningData;
  reel?: CourseReel;
  message: string;
  clientRequestId?: string;
  onRequestStart?: () => void;
}): Promise<{
  text: string;
  offline: boolean;
  blocked?: boolean;
  unavailable?: boolean;
  clientRequestId?: string;
  turnStatus?: ChatMessage['deliveryStatus'];
  code?: string;
  retryAfterSeconds?: number;
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
          text: valueAsString(text),
          offline: false,
          unavailable,
          clientRequestId:
            valueAsString(data.client_request_id) || clientRequestId,
          turnStatus,
          code: code || undefined,
          retryAfterSeconds: Math.max(0, Number(data.retry_after_seconds) || 0),
        };
      }
    } catch (error: unknown) {
      const failure = asRecord(error);
      const response = asRecord(failure.response);
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
