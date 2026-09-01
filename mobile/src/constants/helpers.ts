import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';
import {Platform} from 'react-native';
import {PixelPerfect} from './styleConstants';
import {
  clearSecureSessionStorage,
  deleteSecureSession,
  extractApiToken,
  extractUserProfile,
  loadSecureSession,
  saveSecureSession,
} from '../services/secureSession';

import i18n from '../localization/i18n.config';
export enum AsyncKeys {
  IS_LOGIN = 'IS_LOGIN',
  USER_DATA = 'USER_DATA',
  LANGUAGE = 'LANGUAGE',
  SAVE_LAST_SEARCH = 'SAVE_LAST_SEARCH',
  CITY_ID = 'CITY_ID',
  COUNTRY_ID = 'COUNTRY_ID',
  LOCATION = 'LOCATION',
  DEVICE_TOKEN = 'DEVICE_TOKEN',
  PENDING_WELCOME_BONUS = 'PENDING_WELCOME_BONUS',
  STATE_ID = 'STATE_ID',
}

const GUEST_STORAGE_ID_KEY = '@rokn/guest-storage-id/v1';
let guestStorageIdentityPromise: Promise<string> | null = null;

export {extractApiToken, extractUserProfile};

const errorMessage = (error: unknown) =>
  error instanceof Error ? error.message : String(error);

export const saveItem = async (key: string, data: unknown) => {
  try {
    if (key === AsyncKeys.USER_DATA) {
      await saveSecureSession(data);
      return true;
    }
    await AsyncStorage.setItem(key, JSON.stringify(data));
    return true;
  } catch (error) {
    if (__DEV__) console.warn('saveItem', errorMessage(error));
  }
  return false;
};

export const getItem = async <T = unknown>(key: string): Promise<T | null> => {
  try {
    if (key === AsyncKeys.USER_DATA) {
      return (await loadSecureSession()) as T | null;
    }
    const retrievedItem = await AsyncStorage.getItem(key);
    return retrievedItem === null ? null : (JSON.parse(retrievedItem) as T);
  } catch (error) {
    if (__DEV__) console.warn('getItem', errorMessage(error));
  }
  return null;
};

const storageIdentityHash = async (value: string) =>
  (
    await Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, value)
  ).slice(0, 24);

// Builds released before account scopes used this short non-cryptographic
// suffix. It is migration input only; new keys always use SHA-256 above.
const legacyStorageIdentityHash = (value: string) => {
  let hash = 7;
  for (let index = 0; index < value.length; index += 1) {
    hash = (hash * 31 + value.charCodeAt(index)) % 2147483647;
  }
  return hash.toString(36);
};

const getGuestStorageIdentity = (): Promise<string> => {
  if (!guestStorageIdentityPromise) {
    const flight = (async () => {
      const stored = String(
        (await AsyncStorage.getItem(GUEST_STORAGE_ID_KEY)) || '',
      ).trim();
      if (/^[0-9a-f-]{32,64}$/i.test(stored)) return stored;
      const created = Crypto.randomUUID();
      // A full device may reject this non-sensitive identity write. Keep one
      // process-stable guest scope so the app remains usable; the next launch
      // deliberately starts a fresh anonymous scope rather than sharing data.
      await AsyncStorage.setItem(GUEST_STORAGE_ID_KEY, created).catch(
        () => undefined,
      );
      return created;
    })();
    guestStorageIdentityPromise = flight;
    void flight.catch(() => {
      if (guestStorageIdentityPromise === flight) {
        guestStorageIdentityPromise = null;
      }
    });
  }
  return guestStorageIdentityPromise as Promise<string>;
};

const accountScopeMigrations = new Map<string, Promise<void>>();

const migrateProviderScopedAccountKeys = (
  identityHashes: string[],
  stableScope: string,
) => {
  const existing = accountScopeMigrations.get(stableScope);
  if (existing) return existing;
  const flight = (async () => {
    const legacyScopes = identityHashes.flatMap(identityHash =>
      ['google', 'facebook', 'tiktok', 'apple', 'review', 'account'].map(
        provider => `${provider}-${identityHash}`,
      ),
    );
    const keys = await AsyncStorage.getAllKeys();
    for (const sourceKey of keys) {
      const legacyScope = legacyScopes.find(scope =>
        sourceKey.includes(`:${scope}`),
      );
      if (!legacyScope) continue;
      const targetKey = sourceKey.replace(`:${legacyScope}`, `:${stableScope}`);
      if (targetKey === sourceKey) continue;
      const [[, sourceValue], [, targetValue]] = await AsyncStorage.multiGet([
        sourceKey,
        targetKey,
      ]);
      if (sourceValue !== null && targetValue === null) {
        await AsyncStorage.setItem(targetKey, sourceValue);
      }
      const financialRecoveryConflict =
        sourceValue !== null &&
        targetValue !== null &&
        sourceValue !== targetValue &&
        [
          '@rokn/coin-checkout-attempt/v2',
          '@rokn/course-purchase-attempt/v2',
          '@rokn/native-store-reconciliation/v1',
        ].some(prefix => sourceKey.startsWith(prefix));
      if (financialRecoveryConflict) {
        // Neither intent has a trustworthy recency marker. Preserve both
        // instead of silently choosing one and losing a possible paid order;
        // the current stable scope remains authoritative for normal reads.
        continue;
      }
      await AsyncStorage.removeItem(sourceKey);
    }
  })();
  accountScopeMigrations.set(stableScope, flight);
  while (accountScopeMigrations.size > 4) {
    const oldestScope = accountScopeMigrations.keys().next().value;
    if (typeof oldestScope !== 'string') break;
    accountScopeMigrations.delete(oldestScope);
  }
  void flight.catch(() => {
    if (accountScopeMigrations.get(stableScope) === flight) {
      accountScopeMigrations.delete(stableScope);
    }
  });
  return flight;
};

/**
 * Account-scoped local data uses separate AsyncStorage keys derived from the
 * persisted session, including before Redux hydration and while offline.
 */
export const getCurrentAccountStorageScope = async (): Promise<string> => {
  const session = await getItem(AsyncKeys.USER_DATA);
  const profile = extractUserProfile(session);
  const stableIdentity =
    profile?.id ?? profile?.user_id ?? profile?.social_id ?? profile?.email;
  if (stableIdentity === undefined || stableIdentity === null) {
    return `guest-${await storageIdentityHash(
      await getGuestStorageIdentity(),
    )}`;
  }
  const normalizedIdentity = String(stableIdentity).trim().toLowerCase();
  const identityHash = await storageIdentityHash(normalizedIdentity);
  const stableScope = `user-${identityHash}`;
  await migrateProviderScopedAccountKeys(
    [identityHash, legacyStorageIdentityHash(normalizedIdentity)],
    stableScope,
  );
  return stableScope;
};

/** Start a clean anonymous journey when a learner leaves the device. */
export const rotateGuestStorageScope = async () => {
  const previousIdentity = await getGuestStorageIdentity();
  const previousScope = `guest-${await storageIdentityHash(previousIdentity)}`;
  const nextIdentity = Crypto.randomUUID();
  await AsyncStorage.setItem(GUEST_STORAGE_ID_KEY, nextIdentity).catch(
    () => undefined,
  );
  guestStorageIdentityPromise = Promise.resolve(nextIdentity);
  await clearAccountScopedStorage(previousScope);
  return previousScope;
};

export const accountScopedStorageKey = async (baseKey: string) =>
  `${baseKey}:${await getCurrentAccountStorageScope()}`;

const belongsToAccountScope = (key: string, accountScope: string) =>
  key.endsWith(`:${accountScope}`) || key.includes(`:${accountScope}:`);

/**
 * Remove only data owned by one signed-in account. Device preferences such as
 * language and the device-only push invalidation tombstone survive
 * logout, while course caches, queues and privacy preferences cannot leak into
 * a later account on the same phone. A normal logout may retain only immutable
 * payment recovery intents under this same account scope; deletion removes all.
 */
export const clearAccountScopedStorage = async (
  accountScope: string,
  options: {preserveFinancialRecovery?: boolean} = {},
): Promise<string[]> => {
  const normalizedScope = String(accountScope || '').trim();
  if (!/^[a-z0-9_-]+$/i.test(normalizedScope)) {
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  }

  const keys = await AsyncStorage.getAllKeys();
  const financialRecoveryKeys = [
    '@rokn/coin-checkout-attempt/v2',
    '@rokn/course-purchase-attempt/v2',
    '@rokn/native-store-reconciliation/v1',
  ];
  const ownedKeys = keys.filter(key => {
    if (!belongsToAccountScope(key, normalizedScope)) return false;
    return !(
      options.preserveFinancialRecovery &&
      financialRecoveryKeys.some(prefix => key.startsWith(prefix))
    );
  });
  if (ownedKeys.length) {
    await AsyncStorage.multiRemove(ownedKeys);
  }

  return ownedKeys;
};

export const removeItem = async (key: string) => {
  try {
    if (key === AsyncKeys.USER_DATA) {
      return await deleteSecureSession();
    }
    await AsyncStorage.removeItem(key);
    return true;
  } catch (error) {
    if (__DEV__) console.warn('removeItem', errorMessage(error));
  }
  return false;
};

export const clearStorage = async () => {
  await clearSecureSessionStorage();
};

/** Remove unscoped records written by retired builds before account scoping. */
export const clearLegacyUnscopedPersonalStorage = async () => {
  const exactLegacyKeys = [
    AsyncKeys.SAVE_LAST_SEARCH,
    AsyncKeys.CITY_ID,
    AsyncKeys.COUNTRY_ID,
    AsyncKeys.LOCATION,
    AsyncKeys.DEVICE_TOKEN,
    AsyncKeys.STATE_ID,
    '@rokn/notifications/read/v1',
    '@rokn/portfolio/custom-projects/v1',
    '@rokn/reminders/nudge-seen/v1',
    'VIDEO_QUALITY',
    'VIDEO_PLAYBACK_SPEED',
    'persist:settings',
    'PREF_WATCH_HISTORY',
    'PREF_NOTIFICATIONS',
    'PREF_MARKETING_NOTIFICATIONS',
    'PREF_REMINDER_HOUR',
    '@rokn/course-player/v3',
    '@rokn/search-history/v1',
    '@rokn/watch-later-folder-id/v2',
    '@rokn/saved-folder-options/v1',
    '@rokn/learning-dashboard/v1',
    '@rokn/learning-dashboard/v2',
    '@rokn/saved-lessons/v1',
    '@rokn/saved-lessons/v2',
    '@rokn/pending-privacy-preferences/v1',
    '@rokn/pending-watch-history-clear/v1',
    '@rokn/push-device-token/v1',
    '@rokn/push-unregister-pending/v1',
    '@rokn/push-open-pending/v1',
    '@rokn/pending-welcome-bonus/v2',
    '@rokn/notifications-cache/v1',
    '@rokn/notifications-cache/v2',
    '@rokn/product-events/v1',
    '@rokn/demo-experience/v1',
    '@rokn/coin-checkout-attempt/v2',
    '@rokn/course-purchase-attempt/v2',
  ];
  await AsyncStorage.multiRemove(exactLegacyKeys);
  // Early scoped builds used one deterministic namespace for every guest on
  // a shared phone. Remove that namespace instead of assigning it to whoever
  // signs in next.
  const legacyGuestScope = `account-${await storageIdentityHash('guest')}`;
  const keys = await AsyncStorage.getAllKeys();
  const scopedMarker = /:[a-z0-9_-]+-[a-f0-9]{24}(?::|$)/i;
  const dynamicPersonalPrefixes = [
    '@rokn/catalogue-page/v2:',
    '@rokn/catalogue-page/v3:',
    '@rokn/course-details/v1:',
    '@rokn/course-details/v2:',
    '@rokn/course-details/v3:',
    '@rokn/home-receipt/',
    '@rokn/course-chat-history/v1:',
    '@rokn/project-submission/v2:',
    '@rokn/watch-evidence/v1:',
    '@rokn/section-completion/v1:',
    '@rokn/course-purchase-attempt/v2:',
  ];
  const legacyGuestKeys = keys.filter(key =>
    belongsToAccountScope(key, legacyGuestScope),
  );
  const unownedDynamicKeys = keys.filter(
    key =>
      dynamicPersonalPrefixes.some(prefix => key.startsWith(prefix)) &&
      !scopedMarker.test(key),
  );
  const retiredKeys = Array.from(
    new Set([...legacyGuestKeys, ...unownedDynamicKeys]),
  );
  if (retiredKeys.length) await AsyncStorage.multiRemove(retiredKeys);
};

/**
 * git time
 * @param date timestamp
 * @returns time
 */
export const FormatAMPMHandler = (date: Date) => {
  let hours = date.getHours();
  const minutes = date.getMinutes().toString().padStart(2, '0');
  const ampm = hours >= 12 ? i18n.t('pm') : i18n.t('am');

  hours = hours % 12;
  hours = hours ? hours : 12;
  const strTime = hours + ':' + minutes + ' ' + ampm;
  return strTime;
};
/**
 * convert timestamp to date
 * @param date date fromat string
 * @param time true or false to show time
 * @returns Date
 */
export const getDateHandler = (date?: string, time?: boolean) => {
  const day = date ? new Date(date) : new Date();

  const hours = FormatAMPMHandler(day);
  return `${day.getFullYear()}-${day.getMonth() + 1}-${day.getDate()}${
    time ? ' - ' + hours : ''
  }`;
};

export const formatDate = (date: string | number | Date) => {
  const d = new Date(date);
  const month = (d.getMonth() + 1).toString().padStart(2, '0');
  const day = d.getDate().toString().padStart(2, '0');
  const year = d.getFullYear();
  return [year, month, day].join('-');
};

export const validateEmail = (email: string) => {
  const filter = /^([a-zA-Z0-9_.+-])+@(([a-zA-Z0-9-])+\.)+([a-zA-Z0-9]{2,4})+$/;
  if (filter.test(email)) {
    return true;
  } else {
    return false;
  }
};
export const validateNumber = (number: string) => {
  const filter = /[0-9]/;
  if (filter.test(number)) {
    return true;
  } else {
    return false;
  }
};
export const createNumbers = (numbers: number) => {
  return Array(numbers)
    .fill(undefined)
    .map((_, idx) => 1 + idx);
};
export const createSelectBoxNumbers = (numbers: number) => {
  return Array(numbers)
    .fill(undefined)
    .map((_, idx) => ({id: 1 + idx, name: 1 + idx}));
};

export const wait = (timeout: number) => {
  return new Promise<void>(resolve => setTimeout(resolve, timeout));
};

export const isOnlySpace = (str: string) => !/\S/.test(str);

export const checkImageStatus = (
  imgUrl: string,
  cb: (status: 'success' | 'error') => void,
) => {
  const controller = new AbortController();
  let settled = false;
  const finish = (status: 'success' | 'error') => {
    if (settled) return;
    settled = true;
    cb(status);
  };
  const timeout = setTimeout(() => controller.abort(), 6000);
  const isImage = (response: Response) =>
    response.ok &&
    /^image\//i.test(String(response.headers.get('content-type') || '').trim());

  void (async () => {
    try {
      const parsed = new URL(String(imgUrl || '').trim());
      if (
        !parsed.hostname ||
        (parsed.protocol !== 'https:' && !(__DEV__ && parsed.protocol === 'http:'))
      ) {
        finish('error');
        return;
      }

      const head = await fetch(parsed.toString(), {
        method: 'HEAD',
        signal: controller.signal,
      });
      if (isImage(head)) {
        finish('success');
        return;
      }
      // Retry only when the method or its metadata is unsupported. A
      // confirmed HTTP error must never become a successful image probe.
      if (!head.ok && ![405, 501].includes(head.status)) {
        finish('error');
        return;
      }
      const get = await fetch(parsed.toString(), {
        method: 'GET',
        headers: {Range: 'bytes=0-0'},
        signal: controller.signal,
      });
      finish(isImage(get) ? 'success' : 'error');
    } catch (error) {
      finish('error');
      if (__DEV__) console.warn('image status check failed', errorMessage(error));
    } finally {
      clearTimeout(timeout);
    }
  })();

  return () => controller.abort();
};

export const match = (android: number, ios: number) =>
  Platform.OS === 'android' ? PixelPerfect(android) : PixelPerfect(ios);

export const SecondsToMinutes = (seconds: number) => {
  const safeSeconds = Math.max(0, Math.floor(seconds));
  const minutes = Math.floor(safeSeconds / 60);
  const remainder = safeSeconds % 60;
  return `${minutes}:${remainder.toString().padStart(2, '0')}`;
};
export const removeEmptyValues = <T extends Record<string, unknown>>(
  state: T,
): T => {
  const stateStorage: Record<string, unknown> = state;
  for (const propName in stateStorage) {
    if (
      stateStorage[propName] === null ||
      stateStorage[propName] === undefined ||
      stateStorage[propName] === '' ||
      propName === 'payment_method'
    ) {
      delete stateStorage[propName];
    }
  }
  return state;
};
export function RemoveHTMLFromString(encodedString: string) {
  var translate_re = /&(nbsp|amp|quot|lt|gt);/g;
  const translate: Record<string, string> = {
    nbsp: ' ',
    amp: '&',
    quot: '"',
    lt: '<',
    gt: '>',
  };
  return encodedString
    ?.replace(translate_re, function (_entityMatch, entity) {
      return translate[entity];
    })
    ?.replace(/&#(\d+);/gi, function (_numericMatch, numStr) {
      var num = parseInt(numStr, 10);
      return String.fromCharCode(num);
    });
}

export {normalizeText} from '../utils/searchText';

export const get_url_extension = (url: string) => {
  return url?.split(/[#?]/)[0]?.split('.')?.pop()?.trim();
};
