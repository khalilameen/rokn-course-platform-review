import axios, {
  type AxiosRequestConfig,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios';
import {useEffect} from 'react';
import {Platform} from 'react-native';
import appConfig from '../../app.json';
import {AsyncKeys, extractApiToken, getItem, removeItem} from './helpers';
import {
  getLoginReturnToSnapshot,
  navigate,
} from '../navigation/RootNavigationHelper';
// import {showMessage} from 'react-native-flash-message';
import {store} from '../store/store';
import {LogOut} from '../store/reducers/auth';
import {
  cancelLearningReminders,
  setSmartRemindersEnabled,
} from '../services/smartReminders';
import {invalidateLocalPushDeviceRegistration} from '../services/pushDeviceState';
// Never fall back to the unrelated legacy medical API this mobile shell was
// originally forked from. Expo inlines EXPO_PUBLIC_* values at build time, so
// release/staging channels can point at their own Rokn host without a code change.
const configuredApiUrl = process.env.EXPO_PUBLIC_API_URL?.trim();
// Test builds must still exercise Rokn's own public contract. Release
// artifacts are stricter and refuse to build without an explicit HTTPS URL,
// but falling back to an unrelated/retired development host made otherwise
// healthy APKs look completely broken on a real phone.
const defaultRoknApiUrl = 'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/';
export const mainUrl = (configuredApiUrl || defaultRoknApiUrl).replace(
  /\/?$/,
  '/',
);
export const headers = {
  Accept: 'application/json',
  'Cache-Control': 'no-cache',
  Pragma: 'no-cache',
  Expires: '0',
};
export enum APIStatus {
  IDLE,
  PENDING,
  REJECTED,
  FULFILLED,
}

export type APIError = {
  message: string;
  code: number;
  errors: object;
  need_activation?: unknown;
};
export type APIData<DataType = unknown> = {
  status: APIStatus;
  error?: APIError;
  data?: DataType;
};

export type RoknRequestConfig = AxiosRequestConfig & {
  skipPersistedSessionInvalidation?: boolean;
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null;

export const InternalError = {
  message: 'Internal error during request.',
  errors: {},
  code: -500,
};
export const onFulfilledRequest = (response: AxiosResponse) => response;
let handledExpiredToken: string | null = null;
export const onRejectedResponse = async (error: unknown): Promise<never> => {
  const errorRecord = isRecord(error) ? error : {};
  const response = isRecord(errorRecord.response)
    ? errorRecord.response
    : undefined;
  const config = isRecord(errorRecord.config) ? errorRecord.config : undefined;
  if (
    response?.status === 401 &&
    config?.skipPersistedSessionInvalidation !== true
  ) {
    const session = await getItem(AsyncKeys.USER_DATA);
    const expiredToken = extractApiToken(session);
    // Several requests can fail together. Handle this bearer once so the
    // learner gets one Login screen instead of a stack of duplicates.
    if (expiredToken && handledExpiredToken !== expiredToken) {
      handledExpiredToken = expiredToken;
      const returnTo = getLoginReturnToSnapshot();
      cancelLearningReminders();
      await setSmartRemindersEnabled(false).catch(() => undefined);
      await invalidateLocalPushDeviceRegistration().catch(() => undefined);
      await removeItem(AsyncKeys.IS_LOGIN);
      await removeItem(AsyncKeys.USER_DATA);
      store.dispatch(LogOut());
      navigate('Login', returnTo ? {returnTo} : undefined);
    }
  }

  //   return Promise.reject(InternalError);
  return Promise.reject(response ?? error);
};
export const getExceptionPayload = (ex: unknown): APIError => {
  if (!isRecord(ex)) return InternalError;
  const response = isRecord(ex.response) ? ex.response : undefined;
  const data = response?.data ?? ex.data;
  if (!isRecord(data)) {
    return InternalError;
  }
  if (
    Object.prototype.hasOwnProperty.call(data, 'message') &&
    typeof data.message === 'string' &&
    Object.prototype.hasOwnProperty.call(data, 'status') &&
    typeof data.status === 'number'
  ) {
    return {
      message: data.message,
      errors: isRecord(data.errors) ? data.errors : {},
      code: data.status,
      need_activation: data.need_activation,
    };
  }
  return InternalError;
};
export const UnhandledError = {
  message: 'Cannot handle error data.',
  errors: {},
  code: -400,
};
export const useAPIData = <DataType>(
  response: APIData<DataType>,
  handlers: {
    onFulfilled?: (data: DataType) => void;
    onRejected?: (error: APIError) => void;
    onPending?: () => void;
  },
) => {
  const {onFulfilled, onRejected, onPending} = handlers;

  useEffect(() => {
    if (response.status === APIStatus.REJECTED && onRejected) {
      onRejected(response.error || UnhandledError);
    }
  }, [response.status, response.error, onRejected]);
  useEffect(() => {
    if (response.status === APIStatus.FULFILLED && onFulfilled) {
      onFulfilled(response.data!);
    }
  }, [response.status, response.data, onFulfilled]);
  useEffect(() => {
    if (response.status === APIStatus.PENDING && onPending) {
      onPending();
    }
  }, [response.status, onPending]);
};
export const responseConfig = async (config: InternalAxiosRequestConfig) => {
  const language = await getItem<string>(AsyncKeys.LANGUAGE);
  const userData = (await getItem(AsyncKeys.USER_DATA)) || '';

  if (config?.method === 'post') {
    if (!config?.data) {
      config.data = {};
    }
  } else if (config?.method === 'get') {
    if (!config?.params) {
      config.params = {};
    }
  }

  config.headers.set('Accept-language', language ?? 'ar');
  config.headers.set('X-Rokn-Platform', Platform.OS);
  config.headers.set('X-Rokn-App-Version', appConfig.expo.version);
  config.headers.set(
    'X-Rokn-App-Build',
    String(
      Platform.OS === 'ios'
        ? appConfig.expo.ios.buildNumber
        : appConfig.expo.android.versionCode,
    ),
  );

  const apiToken = extractApiToken(userData);
  if (apiToken && !config.headers.has('Authorization')) {
    config.headers.set('Authorization', `Bearer ${apiToken}`);
  }

  return config;
};
export const responseError = (error: unknown) => Promise.reject(error);

export const publicRequest = axios.create({
  headers: headers,
  baseURL: mainUrl,
});
// publicRequest.defaults.timeout = 600000;
publicRequest.defaults.timeout = 15000;
publicRequest.defaults.timeoutErrorMessage = 'timeout';
publicRequest.defaults.maxRedirects = 0;
// interceptors
publicRequest.interceptors.request.use(responseConfig, responseError);
publicRequest.interceptors.response.use(onFulfilledRequest, onRejectedResponse);

const fieldErrorValue = (value: unknown, keyName: string) => {
  if (
    !isRecord(value) ||
    value.key !== keyName ||
    !Array.isArray(value.value)
  ) {
    return undefined;
  }
  return value.value[0];
};

export const renderObjctError = (errors: unknown, keyName: string) => {
  if (!isRecord(errors)) return undefined;
  for (const element of Object.values(errors)) {
    const value = fieldErrorValue(element, keyName);
    if (value !== undefined) return value;
  }
  return undefined;
};
/**
 * show input erorr message
 * @param data Array of errors
 * @param key input name to return error message
 * @returns string or boolean
 */
export const renderArrayError = (
  data: unknown,
  key: string,
): string | boolean => {
  if (!Array.isArray(data)) return false;
  const value = data
    .map(item => fieldErrorValue(item, key))
    .find(item => item !== undefined);
  return typeof value === 'string' ? value : false;
};
