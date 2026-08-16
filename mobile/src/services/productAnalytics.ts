import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../constants/api';
import {accountScopedStorageKey} from '../constants/helpers';
import {
  enqueueDurableOutbox,
  flushDurableOutbox,
} from './durableOutbox';

export type ProductEventName =
  | 'app_opened'
  | 'home_viewed'
  | 'search_submitted'
  | 'search_zero_results'
  | 'course_impression'
  | 'course_opened'
  | 'sample_started'
  | 'sample_completed'
  | 'lesson_started'
  | 'lesson_milestone'
  | 'lesson_completed'
  | 'paywall_viewed'
  | 'paywall_dismissed'
  | 'earn_tasks_opened'
  | 'purchase_started'
  | 'purchase_completed'
  | 'grant_claimed'
  | 'module_completed'
  | 'project_opened'
  | 'project_submitted'
  | 'project_passed'
  | 'certificate_issued'
  | 'notification_opened';

export type ProductEvent = {
  event_name: ProductEventName;
  source?: 'app' | 'web' | 'dashboard' | 'system' | 'notification';
  screen_key?: string;
  campaign_key?: string;
  course_id?: string | number;
  module_id?: string | number;
  lesson_id?: string | number;
  project_id?: string | number;
  milestone?: 25 | 50 | 75 | 95 | 100;
  value?: number;
};

type QueuedEvent = ProductEvent & {
  event_id: string;
  session_key: string;
  occurred_at: string;
};

const QUEUE_KEY = '@rokn/product-events/v1';
const MAX_QUEUE_SIZE = 50;
const MAX_BATCH_SIZE = 12;

const uuid = (): string => {
  const seed = `${Date.now()}-${Math.random()}-${Math.random()}`;
  let index = 0;
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, token => {
    const source = seed.charCodeAt(index++ % seed.length) + Math.random() * 16;
    const value = (Math.floor(source) + index) % 16;
    return (token === 'x' ? value : 8 + (value % 4)).toString(16);
  });
};

const sessionKey = uuid();
const queueKey = () => accountScopedStorageKey(QUEUE_KEY);
let legacyMigration: Promise<void> | null = null;

const migrateLegacyQueue = (targetKey: string) => {
  if (legacyMigration) return legacyMigration;
  legacyMigration = (async () => {
    try {
      const raw = await AsyncStorage.getItem(QUEUE_KEY);
      const parsed = raw ? JSON.parse(raw) : [];
      if (Array.isArray(parsed)) {
        for (const value of parsed.slice(-MAX_QUEUE_SIZE)) {
          const event = value as QueuedEvent;
          if (!event?.event_id || !event?.event_name) continue;
          await enqueueDurableOutbox({
            storageKey: targetKey,
            id: event.event_id,
            payload: event,
            maxItems: MAX_QUEUE_SIZE,
          });
        }
      }
      await AsyncStorage.removeItem(QUEUE_KEY);
    } catch {
      // A malformed legacy queue is non-critical and remains for a later retry.
    }
  })().finally(() => {
    legacyMigration = null;
  });
  return legacyMigration;
};

const deliver = async (event: QueuedEvent): Promise<'ack' | 'retry'> => {
  try {
    await publicRequest.post('product-events', event, {timeout: 6000});
    return 'ack';
  } catch {
    return 'retry';
  }
};

const flushQueue = (storageKey: string) =>
  flushDurableOutbox<QueuedEvent>({
    storageKey,
    deliver,
    maxBatch: MAX_BATCH_SIZE,
    maxItems: MAX_QUEUE_SIZE,
  });

export const flushProductEvents = async (): Promise<void> => {
  const storageKey = await queueKey();
  await migrateLegacyQueue(storageKey);
  await flushQueue(storageKey);
};

export const trackProductEvent = async (event: ProductEvent): Promise<void> => {
  const queued: QueuedEvent = {
    ...event,
    event_id: uuid(),
    session_key: sessionKey,
    occurred_at: new Date().toISOString(),
  };

  const storageKey = await queueKey();
  await migrateLegacyQueue(storageKey);
  await enqueueDurableOutbox({
    storageKey,
    id: queued.event_id,
    payload: queued,
    maxItems: MAX_QUEUE_SIZE,
  });
  await flushQueue(storageKey);
};

export const productAnalyticsQueueBaseKey = QUEUE_KEY;
export const getProductAnalyticsQueueKey = queueKey;
