import AsyncStorage from '@react-native-async-storage/async-storage';
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

const storageIdentityHash = (value: string) => {
  let hash = 7;
  for (let index = 0; index < value.length; index += 1) {
    hash = (hash * 31 + value.charCodeAt(index)) % 2147483647;
  }
  return hash.toString(36);
};

/**
 * Account-scoped local data uses separate AsyncStorage keys derived from the
 * persisted session, including before Redux hydration and while offline.
 */
export const getCurrentAccountStorageScope = async (): Promise<string> => {
  const session = await getItem(AsyncKeys.USER_DATA);
  const profile = extractUserProfile(session);
  const stableIdentity =
    profile?.id ??
    profile?.user_id ??
    profile?.social_id ??
    profile?.email ??
    'guest';
  const provider = String(
    profile?.social_provider || (profile?.review ? 'review' : 'account'),
  );

  return `${
    provider.replace(/[^a-z0-9_-]/gi, '').toLowerCase() || 'account'
  }-${storageIdentityHash(String(stableIdentity).trim().toLowerCase())}`;
};

export const accountScopedStorageKey = async (baseKey: string) =>
  `${baseKey}:${await getCurrentAccountStorageScope()}`;

const belongsToAccountScope = (key: string, accountScope: string) =>
  key.endsWith(`:${accountScope}`) || key.includes(`:${accountScope}:`);

/**
 * Remove only data owned by one signed-in account. Device preferences such as
 * language and the device-only push invalidation tombstone survive
 * logout, while course caches, queues and privacy preferences cannot leak into
 * a later account on the same phone.
 */
export const clearAccountScopedStorage = async (
  accountScope: string,
): Promise<string[]> => {
  const normalizedScope = String(accountScope || '').trim();
  if (!/^[a-z0-9_-]+$/i.test(normalizedScope)) {
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  }

  const keys = await AsyncStorage.getAllKeys();
  const ownedKeys = keys.filter(key =>
    belongsToAccountScope(key, normalizedScope),
  );
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
  fetch(imgUrl)
    .then(res => {
      if (res.status === 404) {
        cb('error');
      } else {
        cb('success');
      }
    })
    .catch(err => {
      cb('error');
      if (__DEV__) console.warn('image status check failed', errorMessage(err));

      // setDefaultImage(true);
    });
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

export function normalizeText(text: string) {
  //remove special characters
  text = text.replace(
    /([^\u0621-\u063A\u0641-\u064A\u0660-\u0669a-zA-Z 0-9])/g,
    '',
  );

  //normalize Arabic
  text = text.replace(/(آ|إ|أ)/g, 'ا');
  text = text.replace(/(ة)/g, 'ه');
  text = text.replace(/(ئ|ؤ)/g, 'ء');
  text = text.replace(/(ى)/g, 'ي');

  //convert arabic numerals to english counterparts.
  var starter = 0x660;
  for (var i = 0; i < 10; i++) {
    text = text.replace(
      new RegExp(String.fromCharCode(starter + i), 'g'),
      String.fromCharCode(48 + i),
    );
  }

  return text;
}

export const get_url_extension = (url: string) => {
  return url?.split(/[#?]/)[0]?.split('.')?.pop()?.trim();
};
