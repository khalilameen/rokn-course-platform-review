import AsyncStorage from '@react-native-async-storage/async-storage';

export type DurableOutboxItem<T> = {
  id: string;
  payload: T;
  createdAt: string;
  expiresAt: string;
  attempts: number;
  nextAttemptAt: string;
};

type DeliveryResult = 'ack' | 'retry' | 'drop';

type EnqueueOptions<T> = {
  storageKey: string;
  id: string;
  payload: T;
  maxItems?: number;
  ttlMs?: number;
};

type FlushOptions<T> = {
  storageKey: string;
  deliver: (payload: T) => Promise<DeliveryResult>;
  maxBatch?: number;
  maxItems?: number;
  baseRetryMs?: number;
  maxRetryMs?: number;
  now?: () => number;
};

const DEFAULT_MAX_ITEMS = 50;
const DEFAULT_TTL_MS = 7 * 24 * 60 * 60 * 1000;
const locks = new Map<string, Promise<unknown>>();
const flushes = new Map<string, Promise<void>>();

const withLock = <T>(storageKey: string, operation: () => Promise<T>) => {
  const previous = locks.get(storageKey) || Promise.resolve();
  const result = previous.then(operation, operation);
  const tail = result.then(
    () => undefined,
    () => undefined,
  );
  locks.set(storageKey, tail);
  void tail.finally(() => {
    if (locks.get(storageKey) === tail) locks.delete(storageKey);
  });
  return result;
};

const validItem = <T>(value: unknown): value is DurableOutboxItem<T> => {
  if (!value || typeof value !== 'object') return false;
  const item = value as DurableOutboxItem<T>;
  return (
    typeof item.id === 'string' &&
    item.id.length > 0 &&
    typeof item.createdAt === 'string' &&
    Number.isFinite(Date.parse(item.createdAt)) &&
    typeof item.expiresAt === 'string' &&
    Number.isFinite(Date.parse(item.expiresAt)) &&
    typeof item.nextAttemptAt === 'string' &&
    Number.isFinite(Date.parse(item.nextAttemptAt)) &&
    Number.isInteger(item.attempts) &&
    item.attempts >= 0 &&
    Object.prototype.hasOwnProperty.call(item, 'payload')
  );
};

const read = async <T>(storageKey: string, now = Date.now()) => {
  try {
    const raw = await AsyncStorage.getItem(storageKey);
    const parsed = raw ? JSON.parse(raw) : [];
    if (!Array.isArray(parsed)) return [];
    return parsed
      .filter(validItem<T>)
      .filter(item => Date.parse(item.expiresAt) > now);
  } catch {
    return [];
  }
};

const write = async <T>(
  storageKey: string,
  items: DurableOutboxItem<T>[],
  maxItems: number,
) => {
  await AsyncStorage.setItem(
    storageKey,
    JSON.stringify(items.slice(-Math.max(1, maxItems))),
  );
};

export const enqueueDurableOutbox = async <T>({
  storageKey,
  id,
  payload,
  maxItems = DEFAULT_MAX_ITEMS,
  ttlMs = DEFAULT_TTL_MS,
}: EnqueueOptions<T>): Promise<void> => {
  const now = Date.now();
  const timestamp = new Date(now).toISOString();
  await withLock(storageKey, async () => {
    const current = await read<T>(storageKey, now);
    const next = current.filter(item => item.id !== id);
    next.push({
      id,
      payload,
      createdAt: timestamp,
      expiresAt: new Date(now + Math.max(60_000, ttlMs)).toISOString(),
      attempts: 0,
      nextAttemptAt: timestamp,
    });
    await write(storageKey, next, maxItems);
  });
};

export const flushDurableOutbox = async <T>({
  storageKey,
  deliver,
  maxBatch = 12,
  maxItems = DEFAULT_MAX_ITEMS,
  baseRetryMs = 2_000,
  maxRetryMs = 60 * 60 * 1000,
  now = Date.now,
}: FlushOptions<T>): Promise<void> => {
  const existing = flushes.get(storageKey);
  if (existing) return existing;

  const flight = (async () => {
    const snapshot = await withLock(storageKey, () =>
      read<T>(storageKey, now()),
    );
    const eligible = snapshot
      .filter(item => Date.parse(item.nextAttemptAt) <= now())
      .slice(0, Math.max(1, maxBatch));

    for (const candidate of eligible) {
      let result: DeliveryResult = 'retry';
      try {
        result = await deliver(candidate.payload);
      } catch {
        result = 'retry';
      }

      await withLock(storageKey, async () => {
        const current = await read<T>(storageKey, now());
        const index = current.findIndex(item => item.id === candidate.id);
        if (index < 0) return;
        if (result === 'ack' || result === 'drop') {
          current.splice(index, 1);
        } else {
          const attempts = current[index].attempts + 1;
          const retryMs = Math.min(
            Math.max(baseRetryMs, 1) * 2 ** Math.min(attempts - 1, 12),
            Math.max(baseRetryMs, maxRetryMs),
          );
          current[index] = {
            ...current[index],
            attempts,
            nextAttemptAt: new Date(now() + retryMs).toISOString(),
          };
        }
        await write(storageKey, current, maxItems);
      });

      if (result === 'retry') break;
    }
  })();
  flushes.set(storageKey, flight);
  try {
    await flight;
  } finally {
    if (flushes.get(storageKey) === flight) flushes.delete(storageKey);
  }
};

export const readDurableOutbox = <T>(storageKey: string) =>
  withLock(storageKey, () => read<T>(storageKey));

export const resetDurableOutboxForTests = () => {
  locks.clear();
  flushes.clear();
};
