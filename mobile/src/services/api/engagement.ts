import {publicRequest} from '../../constants/api';
import {payload} from './common';

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
  version?: unknown;
  campaign_key?: unknown;
  task_id?: unknown;
};

const text = (value: unknown): string =>
  typeof value === 'string' ? value.trim() : '';

export const getEngagementMessage = async (
  key: EngagementMessageKey,
): Promise<EngagementMessage | null> => {
  const response = await publicRequest.get(`engagement/messages/${key}`);
  const item = payload<EngagementMessageDto | null>(response);
  if (!item || text(item.key) !== key) return null;

  return {
    id: text(item.id),
    key,
    title: text(item.title_ar),
    description: text(item.description_ar),
    actionLabel: text(item.action_label_ar),
    secondaryActionLabel: text(item.secondary_action_label_ar),
    imageUrl: text(item.image_url) || undefined,
    link: text(item.link) || undefined,
    coins: Math.max(0, Number(item.coins || 0) || 0),
    dismissible: item.dismissible !== false,
    version: text(item.version) || text(item.id) || '1',
    campaignKey: text(item.campaign_key) || undefined,
    taskId: text(item.task_id) || undefined,
  };
};

export const getNextEngagementMessage = async (): Promise<EngagementMessage | null> => {
  const response = await publicRequest.get('engagement/next');
  const item = payload<EngagementMessageDto | null>(response);
  if (!item || text(item.key) !== 'coin_offer') return null;
  return {
    id: text(item.id),
    key: 'coin_offer',
    title: text(item.title_ar),
    description: text(item.description_ar),
    actionLabel: text(item.action_label_ar),
    secondaryActionLabel: text(item.secondary_action_label_ar),
    imageUrl: text(item.image_url) || undefined,
    link: text(item.link) || undefined,
    coins: Math.max(0, Number(item.coins || 0) || 0),
    dismissible: item.dismissible !== false,
    version: text(item.version) || text(item.id) || '1',
    campaignKey: text(item.campaign_key) || undefined,
    taskId: text(item.task_id) || undefined,
  };
};
