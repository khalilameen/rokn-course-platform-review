import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../constants/helpers';

const NOTIFICATION_READ_KEY = '@rokn/notifications/read/v2';
const LOCAL_PORTFOLIO_PROJECTS_KEY = '@rokn/portfolio/custom-projects/v2';
const MAX_LOCAL_NOTIFICATION_IDS = 250;
const MAX_LOCAL_PORTFOLIO_DRAFTS = 60;

export const normalizeLocalNotificationIds = (ids: string[]) =>
  Array.from(new Set(ids)).slice(-MAX_LOCAL_NOTIFICATION_IDS);

const parseArray = (raw: string | null): unknown[] => {
  if (!raw) return [];
  try {
    const value = JSON.parse(raw);
    return Array.isArray(value) ? value : [];
  } catch {
    return [];
  }
};

/** Device-only read state used by the local demonstration experience. */
export const readLocalNotificationIds = async (): Promise<string[]> => {
  const boundary = await captureAccountSessionBoundary();
  const storageKey = await accountScopedStorageKey(
    NOTIFICATION_READ_KEY,
    boundary,
  );
  const parsed = parseArray(await AsyncStorage.getItem(storageKey));
  assertAccountSessionBoundary(boundary);
  const compact = normalizeLocalNotificationIds(
    parsed.filter((item): item is string => typeof item === 'string'),
  );

  if (compact.length !== parsed.length) {
    assertAccountSessionBoundary(boundary);
    await AsyncStorage.setItem(storageKey, JSON.stringify(compact));
    assertAccountSessionBoundary(boundary);
  }
  return compact;
};

export const writeLocalNotificationIds = async (ids: string[]) => {
  const boundary = await captureAccountSessionBoundary();
  const compact = normalizeLocalNotificationIds(ids);
  const storageKey = await accountScopedStorageKey(
    NOTIFICATION_READ_KEY,
    boundary,
  );
  assertAccountSessionBoundary(boundary);
  await AsyncStorage.setItem(storageKey, JSON.stringify(compact));
  assertAccountSessionBoundary(boundary);
  return compact;
};

/** Local portfolio drafts exist only when the explicit local demo is enabled. */
export const readLocalPortfolioDrafts = async <T>(): Promise<T[]> => {
  const boundary = await captureAccountSessionBoundary();
  const storageKey = await accountScopedStorageKey(
    LOCAL_PORTFOLIO_PROJECTS_KEY,
    boundary,
  );
  const parsed = parseArray(await AsyncStorage.getItem(storageKey)) as T[];
  assertAccountSessionBoundary(boundary);
  const compact = parsed.slice(0, MAX_LOCAL_PORTFOLIO_DRAFTS);
  if (compact.length !== parsed.length) {
    assertAccountSessionBoundary(boundary);
    await AsyncStorage.setItem(storageKey, JSON.stringify(compact));
    assertAccountSessionBoundary(boundary);
  }
  return compact;
};

export const writeLocalPortfolioDrafts = async <T>(drafts: T[]) => {
  const boundary = await captureAccountSessionBoundary();
  const storageKey = await accountScopedStorageKey(
    LOCAL_PORTFOLIO_PROJECTS_KEY,
    boundary,
  );
  assertAccountSessionBoundary(boundary);
  await AsyncStorage.setItem(
    storageKey,
    JSON.stringify(drafts.slice(0, MAX_LOCAL_PORTFOLIO_DRAFTS)),
  );
  assertAccountSessionBoundary(boundary);
};
