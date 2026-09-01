import AsyncStorage from '@react-native-async-storage/async-storage';
import {accountScopedStorageKey} from '../constants/helpers';
import {cleanUnicodeText, truncateGraphemes} from '../utils/unicodeText';

const SEARCH_HISTORY_KEY = '@rokn/search-history/v1';
const SEARCH_HISTORY_LIMIT = 7;
const historyLocks = new Map<string, Promise<unknown>>();

const historyKey = () => accountScopedStorageKey(SEARCH_HISTORY_KEY);

const cleanQuery = (value: string) =>
  truncateGraphemes(cleanUnicodeText(value, false), 80);

const withHistoryLock = <T>(key: string, operation: () => Promise<T>) => {
  const previous = historyLocks.get(key) || Promise.resolve();
  const result = previous.then(operation, operation);
  const tail = result.then(
    () => undefined,
    () => undefined,
  );
  historyLocks.set(key, tail);
  void tail.finally(() => {
    if (historyLocks.get(key) === tail) historyLocks.delete(key);
  });
  return result;
};

const readSearchHistory = async (key: string): Promise<string[]> => {
  try {
    const raw = await AsyncStorage.getItem(key);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed)
      ? parsed
          .filter((item): item is string => typeof item === 'string')
          .map(cleanQuery)
          .filter(Boolean)
          .slice(0, SEARCH_HISTORY_LIMIT)
      : [];
  } catch {
    return [];
  }
};

export const getSearchHistory = async (): Promise<string[]> => {
  const key = await historyKey();
  return withHistoryLock(key, () => readSearchHistory(key));
};

export const rememberSearch = async (value: string): Promise<string[]> => {
  const query = cleanQuery(value);
  if (!query) return getSearchHistory();
  const key = await historyKey();
  return withHistoryLock(key, async () => {
    const current = await readSearchHistory(key);
    const next = [
      query,
      ...current.filter(
        item => item.toLocaleLowerCase('ar') !== query.toLocaleLowerCase('ar'),
      ),
    ].slice(0, SEARCH_HISTORY_LIMIT);
    await AsyncStorage.setItem(key, JSON.stringify(next)).catch(
      () => undefined,
    );
    return next;
  });
};

export const clearSearchHistory = async () => {
  const key = await historyKey();
  await withHistoryLock(key, () => AsyncStorage.removeItem(key)).catch(
    () => undefined,
  );
};
