import {
  isCoinNotification,
  isCourseNotification,
  normalizeNotificationKind,
  notificationDefaultAction,
  NotificationKind,
  safeNotificationImageUrl,
} from './notificationCampaigns';
import {parseRoknDestination} from '../navigation/deepLinks';
import {firstBoolean} from './api/common';
import {learnerFacingText} from '../utils/errorPayload';
import {formatArabicDisplayText} from '../constants/arabicFormatting';

export type Notification = {
  id: string;
  campaignId?: string;
  type: string;
  title: string;
  description: string;
  createdAt: string;
  read: boolean;
  link?: string;
  courseId?: string;
  imageUrl?: string;
  actionLabel: string;
  kind: NotificationKind;
  tone: 'learning' | 'project' | 'coins';
};

const safeCourseId = (value: unknown): string | undefined => {
  const id = String(value || '').trim();
  return /^\d{1,18}$/.test(id) && Number(id) > 0 ? id : undefined;
};

const courseIdFromItem = (item: Record<string, unknown>) => {
  const explicit = safeCourseId(firstValue(item, ['course_id', 'courseId']));
  if (explicit) return explicit;
  const notifiableType = String(item.notifiable_type || '').toLowerCase();
  return notifiableType.includes('course')
    ? safeCourseId(item.notifiable_id)
    : undefined;
};

const normalizedExplicitLink = (
  value: unknown,
  kind: NotificationKind,
): string | undefined => {
  const link = String(value || '').trim();
  if (!link) return undefined;
  const destination = parseRoknDestination(link);
  if (!destination) return undefined;
  if (
    destination?.name === 'CourseDetails' &&
    (kind === 'continue_course' ||
      kind === 'learning_reminder' ||
      kind === 'streak_reminder')
  ) {
    return `rokn://course/${encodeURIComponent(
      destination.params.courseId,
    )}/watch`;
  }
  return link;
};

const notificationTone = (kind: NotificationKind): Notification['tone'] => {
  if (isCoinNotification(kind)) return 'coins';
  if (kind === 'project_update' || kind === 'certificate_ready') {
    return 'project';
  }
  return 'learning';
};

const safeDate = (value: unknown) => {
  const date = String(value || '').trim();
  return date && Number.isFinite(Date.parse(date)) ? date : '';
};

const firstValue = (item: Record<string, unknown>, keys: string[]): unknown => {
  for (const key of keys) {
    const value = item[key];
    if (value !== undefined && value !== null && String(value).trim()) {
      return value;
    }
  }
  return undefined;
};

/**
 * Maps the multilingual API payload at one boundary. Arabic UI must prefer
 * the explicit Arabic fields even when the API also returns generic text.
 */
export const mapNotification = (
  item: Record<string, unknown>,
): Notification => {
  const type = String(item.notification_type || 'learning');
  const kind = normalizeNotificationKind(type);
  const explicitLink = normalizedExplicitLink(
    firstValue(item, ['link', 'deep_link', 'action_url']),
    kind,
  );
  const courseId = courseIdFromItem(item);
  const fallbackLink = isCoinNotification(kind)
    ? 'rokn://wallet'
    : courseId && isCourseNotification(kind)
    ? kind === 'continue_course' ||
      kind === 'learning_reminder' ||
      kind === 'streak_reminder'
      ? `rokn://course/${encodeURIComponent(courseId)}/watch`
      : `rokn://course/${encodeURIComponent(courseId)}`
    : undefined;
  const imageUrl = safeNotificationImageUrl(
    firstValue(item, [
      'image_url',
      'image',
      'course_image',
      'cover_image',
      'thumbnail',
    ]),
  );
  return {
    id: String(item.id),
    campaignId: String(item.campaign_id || '').trim() || undefined,
    type,
    title: formatArabicDisplayText(
      learnerFacingText(item.title_ar || item.title, 'إشعار من ركن'),
    ),
    description: formatArabicDisplayText(
      learnerFacingText(item.message_ar || item.message, 'لديك إشعار جديد'),
    ),
    createdAt: safeDate(item.created_at),
    read:
      firstBoolean(item.is_read) ??
      (typeof item.read_at === 'string' && item.read_at.trim().length > 0),
    link: explicitLink || fallbackLink,
    courseId,
    imageUrl,
    actionLabel: formatArabicDisplayText(
      learnerFacingText(
        firstValue(item, ['action_label_ar', 'cta_ar', 'action_label']),
        notificationDefaultAction[kind],
      ),
    ),
    kind,
    tone: notificationTone(kind),
  };
};

export type ProductionNotification = Notification;
export const mapProductionNotification = mapNotification;
