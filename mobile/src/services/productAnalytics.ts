import AsyncStorage from '@react-native-async-storage/async-storage';
import {AppState} from 'react-native';
import {publicRequest} from '../constants/api';
import {accountScopedStorageKey} from '../constants/helpers';
import {enqueueDurableOutbox, flushDurableOutbox} from './durableOutbox';

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
  source?: 'app' | 'web' | 'notification';
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
const FLUSH_DEBOUNCE_MS = 1_200;

const uuid = (): string => {
  const seed = `${Date.now()}-${Math.random()}-${Math.random()}`;
  let index = 0;
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, token => {
    const source = seed.charCodeAt(index++ % seed.length) + Math.random() * 16;
    const value = (Math.floor(source) + index) % 16;
    return (token === 'x' ? value : 8 + (value % 4)).toString(16);
  });
};

const queueKey = () => accountScopedStorageKey(QUEUE_KEY);
const sessionKeys = new Map<string, string>();
const sessionKeyForQueue = (storageKey: string) => {
  const current = sessionKeys.get(storageKey);
  if (current) return current;
  const created = uuid();
  sessionKeys.set(storageKey, created);
  while (sessionKeys.size > 4) {
    const oldest = sessionKeys.keys().next().value;
    if (typeof oldest !== 'string') break;
    sessionKeys.delete(oldest);
  }
  return created;
};
let legacyMigration: Promise<void> | null = null;
const scheduledFlushes = new Map<string, ReturnType<typeof setTimeout>>();

const migrateLegacyQueue = (targetKey: string) => {
  if (legacyMigration) return legacyMigration;
  legacyMigration = (async () => {
    try {
      // The retired global queue has no trustworthy account owner. Importing
      // it after an account switch would attribute another learner's actions
      // to the current account, so discard it instead of guessing ownership.
      void targetKey;
      await AsyncStorage.removeItem(QUEUE_KEY);
    } catch {
      // A malformed legacy queue is non-critical and remains for a later retry.
    }
  })().finally(() => {
    legacyMigration = null;
  });
  return legacyMigration;
};

const deliver = async (
  event: QueuedEvent,
): Promise<'ack' | 'retry' | 'drop'> => {
  try {
    await publicRequest.post('product-events', event, {timeout: 6000});
    return 'ack';
  } catch (error) {
    const status = Number(
      (error as {response?: {status?: unknown}})?.response?.status || 0,
    );
    return status >= 400 && status < 500 ? 'drop' : 'retry';
  }
};

const deliverBatch = async (
  events: QueuedEvent[],
): Promise<'ack' | 'retry' | 'drop'> => {
  try {
    await publicRequest.post('product-events', {events}, {timeout: 6000});
    return 'ack';
  } catch (error) {
    const status = Number(
      (error as {response?: {status?: unknown}})?.response?.status || 0,
    );
    return status >= 400 && status < 500 ? 'drop' : 'retry';
  }
};

const flushQueue = (storageKey: string) =>
  flushDurableOutbox<QueuedEvent>({
    storageKey,
    deliver: async event =>
      (await queueKey()) === storageKey ? deliver(event) : 'drop',
    deliverBatch: async events =>
      (await queueKey()) === storageKey ? deliverBatch(events) : 'drop',
    maxBatch: MAX_BATCH_SIZE,
    maxItems: MAX_QUEUE_SIZE,
  });

const scheduleQueueFlush = (storageKey: string) => {
  const existing = scheduledFlushes.get(storageKey);
  if (existing) clearTimeout(existing);
  const timer = setTimeout(() => {
    scheduledFlushes.delete(storageKey);
    if (AppState.currentState !== 'active') return;
    void flushQueue(storageKey).catch(() => undefined);
  }, FLUSH_DEBOUNCE_MS);
  scheduledFlushes.set(storageKey, timer);
  while (scheduledFlushes.size > 4) {
    const oldestKey = scheduledFlushes.keys().next().value;
    if (typeof oldestKey !== 'string') break;
    const oldestTimer = scheduledFlushes.get(oldestKey);
    if (oldestTimer) clearTimeout(oldestTimer);
    scheduledFlushes.delete(oldestKey);
  }
};

export const flushProductEvents = async (): Promise<void> => {
  const storageKey = await queueKey();
  const scheduled = scheduledFlushes.get(storageKey);
  if (scheduled) clearTimeout(scheduled);
  scheduledFlushes.delete(storageKey);
  await migrateLegacyQueue(storageKey);
  await flushQueue(storageKey);
};

export const trackProductEvent = async (event: ProductEvent): Promise<void> => {
  try {
    const storageKey = await queueKey();
    const queued: QueuedEvent = {
      ...event,
      event_id: uuid(),
      session_key: sessionKeyForQueue(storageKey),
      occurred_at: new Date().toISOString(),
    };

    await migrateLegacyQueue(storageKey);
    await enqueueDurableOutbox({
      storageKey,
      id: queued.event_id,
      payload: queued,
      maxItems: MAX_QUEUE_SIZE,
    });
    scheduleQueueFlush(storageKey);
  } catch {
    // Analytics must never surface an unhandled rejection or block the
    // learner when storage is full or temporarily unavailable.
  }
};

export const productAnalyticsQueueBaseKey = QUEUE_KEY;
export const getProductAnalyticsQueueKey = queueKey;
