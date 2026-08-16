import {
  isCoinNotification,
  isCourseNotification,
  normalizeNotificationKind,
  notificationDefaultAction,
  NotificationKind,
  safeNotificationImageUrl,
} from './notificationCampaigns';
import {parseRoknDestination} from '../navigation/deepLinks';

export type Notification = {
  id: string;
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
  return /^[\p{L}\p{N}][\p{L}\p{N}._-]{0,127}$/u.test(id) ? id : undefined;
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
    type,
    title: String(
      item.title_ar || item.title || item.title_en || 'إشعار من ركن',
    ),
    description: String(
      item.message_ar || item.message || item.message_en || '',
    ),
    createdAt: String(item.created_at || ''),
    read: Boolean(item.is_read || item.read_at),
    link: explicitLink || fallbackLink,
    courseId,
    imageUrl,
    actionLabel: String(
      firstValue(item, ['action_label_ar', 'cta_ar', 'action_label']) ||
        notificationDefaultAction[kind],
    ),
    kind,
    tone: notificationTone(kind),
  };
};

export type ProductionNotification = Notification;
export const mapProductionNotification = mapNotification;
