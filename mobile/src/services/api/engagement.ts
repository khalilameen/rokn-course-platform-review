import {publicRequest} from '../../constants/api';
import {learnerFacingText} from '../../utils/errorPayload';
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import {firstBoolean, payload} from './common';

export type EngagementMessageKey =
  | 'guest_registration_prompt'
  | 'welcome_bonus_received'
  | 'coin_offer';

export type EngagementMessage = {
  id: string;
  key: EngagementMessageKey;
  title: string;
  description: string;
  actionLabel: string;
  secondaryActionLabel: string;
  imageUrl?: string;
  link?: string;
  coins: number;
  dismissible: boolean;
  cooldownHours: number;
  version: string;
  campaignKey?: string;
  taskId?: string;
};

type EngagementMessageDto = {
  id?: unknown;
  key?: unknown;
  title_ar?: unknown;
  description_ar?: unknown;
  action_label_ar?: unknown;
  secondary_action_label_ar?: unknown;
  image_url?: unknown;
  link?: unknown;
  coins?: unknown;
  dismissible?: unknown;
  cooldown_hours?: unknown;
  version?: unknown;
  campaign_key?: unknown;
  task_id?: unknown;
};

const rawText = (value: unknown): string =>
  typeof value === 'string' ? value.trim() : '';

const copyText = (value: unknown): string =>
  formatArabicDisplayText(learnerFacingText(value));

const imageUrl = (value: unknown) => {
  const url = rawText(value);
  return /^https:\/\//i.test(url) ? url : undefined;
};

const mapEngagementMessage = (
  item: EngagementMessageDto | null,
  expectedKey: EngagementMessageKey,
): EngagementMessage | null => {
  const id = rawText(item?.id);
  const title = copyText(item?.title_ar);
  const actionLabel = copyText(item?.action_label_ar);
  if (
    !item ||
    rawText(item.key) !== expectedKey ||
    !id ||
    !title ||
    !actionLabel
  ) {
    return null;
  }
  return {
    id,
    key: expectedKey,
    title,
    description: copyText(item.description_ar),
    actionLabel,
    secondaryActionLabel: copyText(item.secondary_action_label_ar),
    imageUrl: imageUrl(item.image_url),
    link: rawText(item.link) || undefined,
    coins: Math.max(0, Number(item.coins || 0) || 0),
    dismissible: firstBoolean(item.dismissible) ?? true,
    cooldownHours: Math.max(0, Number(item.cooldown_hours || 0) || 0),
    version: rawText(item.version) || id,
    campaignKey: rawText(item.campaign_key) || undefined,
    taskId: rawText(item.task_id) || undefined,
  };
};

export const getEngagementMessage = async (
  key: EngagementMessageKey,
): Promise<EngagementMessage | null> => {
  const response = await publicRequest.get(`engagement/messages/${key}`);
  const item = payload<EngagementMessageDto | null>(response);
  return mapEngagementMessage(item, key);
};

export const getNextEngagementMessage = async (): Promise<EngagementMessage | null> => {
  const response = await publicRequest.get('engagement/next');
  const item = payload<EngagementMessageDto | null>(response);
  return mapEngagementMessage(item, 'coin_offer');
};
