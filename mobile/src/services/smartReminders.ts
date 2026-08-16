import {NativeModules, Platform} from 'react-native';
import {
  accountScopedStorageKey,
  getItem,
  saveItem,
} from '../constants/helpers';
import {DEMO_COURSE_ID} from './demoExperience';
import {formatArabicDisplayText} from '../constants/arabicFormatting';
import {
  NotificationKind,
  notificationDefaultAction,
  safeNotificationImageUrl,
} from './notificationCampaigns';

export const REMINDER_ENABLED_KEY = 'PREF_NOTIFICATIONS';
export const REMINDER_HOUR_KEY = 'PREF_REMINDER_HOUR';

const reminderEnabledStorageKey = () =>
  accountScopedStorageKey(REMINDER_ENABLED_KEY);

export const getSmartRemindersEnabled = async () =>
  (await getItem(await reminderEnabledStorageKey())) === true;

export const setSmartRemindersEnabled = async (enabled: boolean) =>
  saveItem(await reminderEnabledStorageKey(), enabled);

type ReminderModule = {
  requestPermission: () => Promise<boolean>;
  schedule: (
    id: number,
    title: string,
    body: string,
    triggerAt: number,
    courseId?: string,
    link?: string,
    kind?: NotificationKind,
    imageUrl?: string,
    actionLabel?: string,
  ) => Promise<boolean>;
  preview: (
    title: string,
    body: string,
    link?: string,
    kind?: NotificationKind,
    imageUrl?: string,
    actionLabel?: string,
  ) => Promise<boolean>;
  cancel: (id: number) => void;
};

const nativeReminders = NativeModules?.RoknReminders as
  | ReminderModule
  | undefined;

/**
 * A read-only capability check for permission primers. It never asks for an
 * OS permission and keeps unsupported platforms from showing a dead action.
 */
export const areSmartRemindersSupported = () =>
  Platform.OS === 'android' && Boolean(nativeReminders);

const safeReminderHour = (value: unknown) => {
  const hour = Number(value);
  return Number.isInteger(hour) && hour >= 9 && hour <= 21 ? hour : 20;
};

const nextPreferredTime = (hour = 20) => {
  const now = new Date();
  const next = new Date(now);
  next.setHours(safeReminderHour(hour), 0, 0, 0);
  if (next.getTime() <= now.getTime() + 60 * 60 * 1_000) {
    next.setDate(next.getDate() + 1);
  }
  return next.getTime();
};

export const enableSmartReminders = async () => {
  if (!areSmartRemindersSupported() || !nativeReminders) return false;
  return nativeReminders.requestPermission();
};

export const previewSmartReminder = async () => {
  if (!nativeReminders) return false;
  return nativeReminders.preview(
    'نكمّل من مكانك؟',
    'لما تفضى افتح ركن وهتلاقي الخطوة اللي بعدها جاهزة',
    'rokn://home',
    'learning_reminder',
    undefined,
    notificationDefaultAction.learning_reminder,
  );
};

export const previewCoinNotification = async ({
  amount = 20,
  offer = false,
}: {amount?: number; offer?: boolean} = {}) => {
  if (!nativeReminders) return false;
  const kind: NotificationKind = offer ? 'coin_offer' : 'coin_reward';
  return nativeReminders.preview(
    offer ? 'عرض رصيد لفترة محدودة' : 'رصيدك وصل',
    offer
      ? `فيه ${amount} عملة إضافية مستنياك في المحفظة`
      : `أضفنا ${amount} عملة لرصيدك. تقدر تشوف التفاصيل من المحفظة`,
    'rokn://wallet',
    kind,
    undefined,
    notificationDefaultAction[kind],
  );
};

export const scheduleNextLearningReminder = async ({
  nextReelTitle,
  courseTitle,
  streakDays = 0,
  courseId = DEMO_COURSE_ID,
  preferredHour,
}: {
  nextReelTitle?: string;
  courseTitle?: string;
  streakDays?: number;
  courseId?: string;
  preferredHour?: number;
}) => {
  if (!nativeReminders) return false;
  if (!(await getSmartRemindersEnabled())) return false;
  const storedHour = await getItem(REMINDER_HOUR_KEY);
  const reminderHour = safeReminderHour(preferredHour ?? storedHour);
  const kind: NotificationKind =
    streakDays > 1 ? 'streak_reminder' : 'learning_reminder';
  const body = formatArabicDisplayText(
    nextReelTitle
      ? `${courseTitle ? `${courseTitle}\n` : ''}وقفت عند: ${nextReelTitle}\nكمّلها وقت ما تكون فاضي`
      : streakDays > 1
        ? `باقي خطوة صغيرة وتحافظ على ${streakDays} أيام من الاستمرار`
        : 'خمس دقايق كفاية تنجز فيهم خطوة جديدة',
  );
  return nativeReminders.schedule(
    8101,
    formatArabicDisplayText(
      streakDays > 1 ? 'ما تقطعش استمراريتك النهارده' : 'نكمّل من مكانك؟',
    ),
    body,
    nextPreferredTime(reminderHour),
    courseId,
    `rokn://course/${encodeURIComponent(courseId)}/watch`,
    kind,
    undefined,
    notificationDefaultAction[kind],
  );
};

export const scheduleProjectReviewResult = async (
  projectTitle: string,
  courseId = DEMO_COURSE_ID,
) => {
  if (!nativeReminders) return false;
  if (!(await getSmartRemindersEnabled())) return false;
  return nativeReminders.schedule(
    8102,
    'مشروعك عبر',
    `راجعنا «${projectTitle}». الوحدة التالية مفتوحة الآن.`,
    Date.now() + 12_000,
    courseId,
    `rokn://course/${encodeURIComponent(courseId)}`,
    'project_update',
    undefined,
    notificationDefaultAction.project_update,
  );
};

export const scheduleCoinRewardNotification = async ({
  amount,
  reason,
  delayMs = 2_000,
}: {
  amount: number;
  reason?: string;
  delayMs?: number;
}) => {
  if (!nativeReminders || !(await getSmartRemindersEnabled())) return false;
  const safeAmount = Math.max(0, Math.floor(Number(amount) || 0));
  if (!safeAmount) return false;
  return nativeReminders.schedule(
    8103,
    'رصيدك زاد',
    `${reason ? `${reason}\n` : ''}أضفنا ${safeAmount} عملة لرصيدك`,
    Date.now() + Math.max(1_000, delayMs),
    undefined,
    'rokn://wallet',
    'coin_reward',
    undefined,
    notificationDefaultAction.coin_reward,
  );
};

export const previewCourseNotification = async ({
  title,
  courseId,
  imageUrl,
  isNew = false,
}: {
  title: string;
  courseId: string;
  imageUrl?: string;
  isNew?: boolean;
}) => {
  if (!nativeReminders) return false;
  const kind: NotificationKind = isNew ? 'new_course' : 'continue_course';
  return nativeReminders.preview(
    isNew ? 'كورس جديد على ركن' : 'الكورس ده عندك بالفعل',
    isNew ? title : `${title}\nارجع كمّل من آخر خطوة وقفت عندها`,
    `rokn://course/${encodeURIComponent(courseId)}${isNew ? '' : '/watch'}`,
    kind,
    safeNotificationImageUrl(imageUrl),
    notificationDefaultAction[kind],
  );
};

export const cancelLearningReminders = () => {
  if (!nativeReminders) return;
  nativeReminders.cancel(8101);
  nativeReminders.cancel(8102);
  nativeReminders.cancel(8103);
};
