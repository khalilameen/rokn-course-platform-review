import AsyncStorage from '@react-native-async-storage/async-storage';
import {accountScopedStorageKey} from '../constants/helpers';

const SEARCH_HISTORY_KEY = '@rokn/search-history/v1';
const SEARCH_HISTORY_LIMIT = 7;

const historyKey = () => accountScopedStorageKey(SEARCH_HISTORY_KEY);

const cleanQuery = (value: string) =>
  value.trim().replace(/\s+/g, ' ').slice(0, 80);

export const getSearchHistory = async (): Promise<string[]> => {
  try {
    const raw = await AsyncStorage.getItem(await historyKey());
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

export const rememberSearch = async (value: string): Promise<string[]> => {
  const query = cleanQuery(value);
  if (!query) return getSearchHistory();
  const current = await getSearchHistory();
  const next = [
    query,
    ...current.filter(
      item => item.toLocaleLowerCase('ar') !== query.toLocaleLowerCase('ar'),
    ),
  ].slice(0, SEARCH_HISTORY_LIMIT);
  await AsyncStorage.setItem(await historyKey(), JSON.stringify(next)).catch(
    () => undefined,
  );
  return next;
};

export const clearSearchHistory = async () => {
  await AsyncStorage.removeItem(await historyKey()).catch(() => undefined);
};
