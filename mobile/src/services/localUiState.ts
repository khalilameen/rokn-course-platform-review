import AsyncStorage from '@react-native-async-storage/async-storage';
import {accountScopedStorageKey} from '../constants/helpers';

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
  const storageKey = await accountScopedStorageKey(NOTIFICATION_READ_KEY);
  const parsed = parseArray(await AsyncStorage.getItem(storageKey));
  const compact = normalizeLocalNotificationIds(
    parsed.filter((item): item is string => typeof item === 'string'),
  );

  if (compact.length !== parsed.length) {
    await AsyncStorage.setItem(storageKey, JSON.stringify(compact));
  }
  return compact;
};

export const writeLocalNotificationIds = async (ids: string[]) => {
  const compact = normalizeLocalNotificationIds(ids);
  await AsyncStorage.setItem(
    await accountScopedStorageKey(NOTIFICATION_READ_KEY),
    JSON.stringify(compact),
  );
  return compact;
};

/** Local portfolio drafts exist only when the explicit local demo is enabled. */
export const readLocalPortfolioDrafts = async <T>(): Promise<T[]> => {
  const storageKey = await accountScopedStorageKey(
    LOCAL_PORTFOLIO_PROJECTS_KEY,
  );
  const parsed = parseArray(await AsyncStorage.getItem(storageKey)) as T[];
  const compact = parsed.slice(0, MAX_LOCAL_PORTFOLIO_DRAFTS);
  if (compact.length !== parsed.length) {
    await AsyncStorage.setItem(storageKey, JSON.stringify(compact));
  }
  return compact;
};

export const writeLocalPortfolioDrafts = async <T>(drafts: T[]) =>
  AsyncStorage.setItem(
    await accountScopedStorageKey(LOCAL_PORTFOLIO_PROJECTS_KEY),
    JSON.stringify(drafts.slice(0, MAX_LOCAL_PORTFOLIO_DRAFTS)),
  );
