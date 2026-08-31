import {publicRequest} from '../../../constants/api';
import {isProductFeatureEnabled} from '../../../services/productFeatures';
import {includesCourseAssistant} from '../courseEntitlements';
import type {CourseLearningData, CourseReel} from '../types';
import {asRecord, valueAsString} from './shared';

const COURSE_CHAT_REQUEST_TIMEOUT_MS = 60_000;

const demoAssistantReply = (message: string, reel?: CourseReel) => {
  const question = message.trim();
  const normalized = question.toLocaleLowerCase('ar');
  if (/^(سلام|أهلا|اهلا|هاي|hello)/i.test(normalized)) {
    return 'أهلًا\nاكتب المشكلة أو الهدف بشكل مباشر\nهجاوبك من غير لف';
  }
  if (normalized.includes('سعر') || normalized.includes('تسعير')) {
    return 'اربط السعر بنطاق واضح\nهتسلّم إيه\nكام جولة تعديل\nوالموعد\nأي إضافة خارج الاتفاق لها سعر منفصل';
  }
  if (normalized.includes('عميل') || normalized.includes('عرض')) {
    return 'اكتب رسالة قصيرة\nالمشكلة التي فهمتها\nالنتيجة التي ستقدمها\nالخطوة التالية وموعدها';
  }
  if (
    normalized.includes('مشروع') ||
    normalized.includes('تسليم') ||
    normalized.includes('ارفع')
  ) {
    return 'ارفع لقطة واضحة للشغل أو ملف النتيجة\nواكتب سطرين عن اللي نفذته\nمش مطلوب كمال\nالمرفوض فقط ملف فاضي أو صورة سودا أو تسليم بلا مجهود واضح';
  }
  if (normalized.includes('بورتفوليو') || normalized.includes('معرض')) {
    return 'اعرض المشروع كقصة قرار\nالمشكلة\nدورك\nأهم قرار خدته\nوالنتيجة\nصور قليلة قوية أفضل من ألبوم طويل';
  }
  if (reel?.caption) {
    return `فكرة هذا المقطع\n${reel.caption}\nطبّقها على حالة حقيقية`;
  }
  return 'اكتب هدفك والمشكلة بوضوح\nمثال\nأريد تنفيذ كذا لكنني توقفت عند كذا';
};

export const courseIncludesAssistant = (
  course: Pick<CourseLearningData, 'accessType' | 'chatAvailable' | 'isDemo'>,
) => includesCourseAssistant(course);

export const askCourseAssistant = async ({
  course,
  reel,
  message,
  onRequestStart,
}: {
  course: CourseLearningData;
  reel?: CourseReel;
  message: string;
  onRequestStart?: () => void;
}): Promise<{text: string; offline: boolean; blocked?: boolean}> => {
  if (!courseIncludesAssistant(course)) {
    return {
      text: 'Rokn AI غير مشمول في وصولك الحالي',
      offline: true,
      blocked: true,
    };
  }
  const courseId = course.id;
  if (courseId.startsWith('demo')) {
    onRequestStart?.();
    return {text: demoAssistantReply(message, reel), offline: true};
  }
  if (!courseId.startsWith('demo')) {
    if (!(await isProductFeatureEnabled('ai_chat'))) {
      return {
        text: 'Rokn AI متوقف مؤقتًا للصيانة. دروسك وتقدمك محفوظان، جرّب سؤالك لاحقًا.',
        offline: true,
        blocked: true,
      };
    }
    try {
      onRequestStart?.();
      const response = await publicRequest.post(
        `courses/${courseId}/chat`,
        {
          message,
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
        return {text: valueAsString(text), offline: false};
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
          text: 'Rokn AI غير مشمول في المنحة\nتقدر تفتحه بالترقية للوصول الكامل',
          offline: false,
          blocked: true,
        };
      }
      if (errorCode === 'chat_plan_limit_reached') {
        return {
          text: 'استخدمت مساحة الأسئلة في اختيارك الحالي\nتقدر تزودها من غير ما تدفع ثمن الكورس من جديد',
          offline: false,
          blocked: true,
        };
      }
      // Keep the learner in context and provide a useful local fallback.
    }
  }

  return {
    text: 'Rokn AI غير متاح الآن\nأكمل المقطع ومكانك محفوظ\nحاول لاحقًا',
    offline: true,
  };
};
