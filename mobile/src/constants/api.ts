import axios, {
  type AxiosRequestConfig,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios';
import {useEffect} from 'react';
import {Platform} from 'react-native';
import appConfig from '../../app.json';
import {
  AsyncKeys,
  extractApiToken,
  getItem,
  removeItem,
  rotateGuestStorageScope,
} from './helpers';
import {
  getLoginReturnToSnapshot,
  navigate,
} from '../navigation/RootNavigationHelper';
import {savePendingLoginReturnTo} from '../navigation/authReturn';
// import {showMessage} from 'react-native-flash-message';
import {store} from '../store/store';
import {LogOut} from '../store/reducers/auth';
import {
  cancelLearningReminders,
  setSmartRemindersEnabled,
} from '../services/smartReminders';
import {invalidateLocalPushDeviceRegistration} from '../services/pushDeviceState';
import {roknApiUrl} from './apiBaseUrl';
import {secureRandomUuid} from '../utils/secureRandom';
import {observeServerTime} from '../utils/serverClock';
import {getInstallationId} from '../services/installationIdentity';
import {
  deleteSecureSessionIfToken,
  peekSecureSession,
} from '../services/secureSession';
// Expo inlines EXPO_PUBLIC_* values at build time; each release channel uses
// its configured Rokn host and has no hidden fallback origin.
export const mainUrl = roknApiUrl;
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
  diagnostic_code?: string;
  need_activation?: unknown;
};
export type APIData<DataType = unknown> = {
  status: APIStatus;
  error?: APIError;
  data?: DataType;
};

export type RoknRequestConfig = AxiosRequestConfig & {
  skipPersistedSessionInvalidation?: boolean;
  skipAuthorization?: boolean;
  /** Attach a bearer only when bootstrap has already restored it in memory. */
  optionalAuthorization?: boolean;
  /** Internal guard for bounded read recovery after transport/origin wake-up. */
  roknNetworkRetryCount?: number;
  /** Bearer captured when this request crossed the account boundary. */
  roknSessionToken?: string;
  /** Covers guest and authenticated responses, including account switches. */
  roknSessionEpoch?: number;
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null;

export const InternalError = {
  message: 'تعذّر إكمال الطلب\nحاول مرة أخرى',
  errors: {},
  code: -500,
  diagnostic_code: 'INTERNAL_REQUEST_ERROR',
};
const assertResponseStillBelongsToSession = async (
  config?: Record<string, unknown>,
) => {
  const requestEpoch = Number(config?.roknSessionEpoch);
  if (
    Number.isSafeInteger(requestEpoch) &&
    peekSecureSession().epoch !== requestEpoch
  ) {
    throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }
  const requestToken =
    typeof config?.roknSessionToken === 'string'
      ? config.roknSessionToken.trim()
      : '';
  if (!requestToken) return;
  const activeToken = extractApiToken(await getItem(AsyncKeys.USER_DATA));
  if (activeToken !== requestToken) {
    throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }
};

export const onFulfilledRequest = async (response: AxiosResponse) => {
  const body = isRecord(response.data) ? response.data : undefined;
  observeServerTime(body?.server_time ?? response.headers?.date);
  await assertResponseStillBelongsToSession(
    isRecord(response.config) ? response.config : undefined,
  );
  return response;
};
let handledExpiredToken: string | null = null;

// Laravel Cloud may return a short run of 502/503/504 responses while a
// hibernated origin is waking. Public catalogue and login discovery are the
// first reads a learner makes, so surfacing that infrastructure transition as
// two unrelated product errors makes a healthy deployment look broken. Reads
// are safe to replay; writes keep their endpoint-owned idempotency rules.
const READ_RECOVERY_DELAYS_MS = [450, 900, 1_800, 3_000, 4_500, 5_000] as const;

const transientReadFailure = ({
  errorCode,
  errorMessage,
  responseStatus,
}: {
  errorCode: string;
  errorMessage: string;
  responseStatus: number;
}) =>
  (!responseStatus &&
    (errorCode === 'ERR_NETWORK' ||
      errorCode === 'ENETUNREACH' ||
      errorCode === 'ECONNRESET' ||
      errorCode === 'ECONNABORTED' ||
      errorCode === 'ETIMEDOUT' ||
      errorMessage.includes('network error') ||
      errorMessage.includes('timeout'))) ||
  [408, 425, 502, 503, 504].includes(responseStatus);

const bearerTokenUsedByRequest = (
  config?: Record<string, unknown>,
): string | null => {
  const rawHeaders = config?.headers;
  if (!rawHeaders || typeof rawHeaders !== 'object') return null;
  const headerRecord = rawHeaders as Record<string, unknown>;
  let authorization: unknown;
  if (typeof headerRecord.get === 'function') {
    authorization = (
      headerRecord.get as (this: unknown, name: string) => unknown
    ).call(rawHeaders, 'Authorization');
  } else {
    authorization = headerRecord.Authorization ?? headerRecord.authorization;
  }
  const matched = String(authorization || '').match(/^Bearer\s+(.+)$/i);
  return matched?.[1]?.trim() || null;
};

export const onRejectedResponse = async (error: unknown): Promise<never> => {
  const errorRecord = isRecord(error) ? error : {};
  const response = isRecord(errorRecord.response)
    ? errorRecord.response
    : undefined;
  const responseBody = isRecord(response?.data) ? response.data : undefined;
  const responseHeaders = isRecord(response?.headers)
    ? response.headers
    : undefined;
  observeServerTime(responseBody?.server_time ?? responseHeaders?.date);
  const config = isRecord(errorRecord.config) ? errorRecord.config : undefined;
  await assertResponseStillBelongsToSession(config);
  const method = String(config?.method || 'get').toLowerCase();
  const errorCode = String(errorRecord.code || '').toUpperCase();
  const errorMessage = String(errorRecord.message || '').toLowerCase();
  const retryCount = Number(config?.roknNetworkRetryCount || 0);
  const responseStatus = Number(response?.status || 0);
  const safeTransientReadFailure = transientReadFailure({
    errorCode,
    errorMessage,
    responseStatus,
  });

  // Cover both a socket lost during Wi-Fi/mobile-data hand-off and the brief
  // gateway responses returned while the origin wakes. Keep the retry budget
  // finite and never replay a mutation whose server result may be unknown.
  if (
    safeTransientReadFailure &&
    retryCount < READ_RECOVERY_DELAYS_MS.length &&
    (method === 'get' || method === 'head')
  ) {
    const retryConfig = {
      ...(config as RoknRequestConfig),
      roknNetworkRetryCount: retryCount + 1,
    };
    await new Promise(resolve =>
      setTimeout(resolve, READ_RECOVERY_DELAYS_MS[retryCount]),
    );
    return publicRequest.request(retryConfig).then(
      value => value as never,
      retryError => Promise.reject(retryError),
    );
  }

  if (
    response?.status === 401 &&
    config?.skipPersistedSessionInvalidation !== true
  ) {
    const rejectedToken = bearerTokenUsedByRequest(config);
    // A public guest request has no session to invalidate. Do not turn an
    // unexpected gateway 401 into a keychain read or Login navigation.
    if (!rejectedToken) return Promise.reject(response ?? error);
    const session = await getItem(AsyncKeys.USER_DATA);
    const expiredToken = extractApiToken(session);
    // Several requests can fail together. Handle this bearer once so the
    // learner gets one Login screen instead of a stack of duplicates. A late
    // 401 from a request sent before reauthentication must never erase the
    // newer session that is already durable on the device.
    if (
      expiredToken &&
      rejectedToken === expiredToken &&
      handledExpiredToken !== expiredToken
    ) {
      handledExpiredToken = expiredToken;
      const returnTo = getLoginReturnToSnapshot();
      // Persist before deleting the session. If Android kills the process
      // while Login or the provider browser is opening, cold start restores
      // the same course/lesson instead of silently dropping the learner home.
      if (returnTo) {
        await savePendingLoginReturnTo(returnTo).catch(() => undefined);
      }
      cancelLearningReminders();
      await setSmartRemindersEnabled(false).catch(() => undefined);
      await invalidateLocalPushDeviceRegistration().catch(() => undefined);
      await import('../components/VideoPlayer/courseLearningApi')
        .then(module => module.quiesceLearningRuntime())
        .catch(() => undefined);
      await Promise.all([
        import('../components/VideoPlayer/courseChat/persistence')
          .then(module => module.quiesceCourseChatPersistence())
          .catch(() => undefined),
        import('../components/VideoPlayer/attachmentActions')
          .then(module => module.quiescePrivateAttachmentDownloads())
          .catch(() => undefined),
      ]);
      // Reauthentication can complete while the old 401 cleanup is awaiting
      // native storage or runtime quiescence (for example a late provider
      // callback/deep link). Never let that old response erase the newer
      // durable session or navigate its owner back to Login.
      const invalidated = await deleteSecureSessionIfToken(expiredToken);
      if (!invalidated) {
        if (handledExpiredToken === expiredToken) handledExpiredToken = null;
        return Promise.reject(response ?? error);
      }
      // A 401 asks for reauthentication; it is not account deletion. Keep the
      // owner's scoped progress, pending submissions and editor drafts. If a
      // different person signs in next, secureSession clears the previous
      // scope before committing the replacement profile.
      await removeItem(AsyncKeys.IS_LOGIN);
      await rotateGuestStorageScope().catch(() => undefined);
      // All awaited cleanup belongs to the expired bearer. If a provider
      // callback committed another session meanwhile, its synchronous Redux
      // state and navigation now own the app.
      if (extractApiToken(peekSecureSession().session)) {
        return Promise.reject(response ?? error);
      }
      handledExpiredToken = null;
      store.dispatch(LogOut());
      navigate('Login', returnTo ? {returnTo} : undefined);
    }
  }

  // Record only terminal availability failures. Validation, permissions and
  // expected not-found responses are product outcomes, not operational
  // incidents. Import lazily so diagnostics never become an HTTP dependency.
  if (safeTransientReadFailure || responseStatus >= 500) {
    const diagnosticError =
      error instanceof Error
        ? error
        : Object.assign(
            new Error(responseStatus ? `HTTP_${responseStatus}` : 'NETWORK_ERROR'),
            errorRecord,
          );
    void import('../services/operationalTelemetry')
      .then(({reportClientError}) =>
        reportClientError(diagnosticError, {source: 'api_transport'}),
      )
      .catch(() => undefined);
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
    const learnerMessage = /[\u0600-\u06ff]/u.test(data.message)
      ? data.message
      : 'تعذّر إكمال الطلب\nحاول مرة أخرى';
    return {
      message: learnerMessage,
      errors: isRecord(data.errors) ? data.errors : {},
      code: data.status,
      diagnostic_code:
        typeof data.code === 'string' && data.code.trim()
          ? data.code.trim()
          : 'API_REQUEST_REJECTED',
      need_activation: data.need_activation,
    };
  }
  return InternalError;
};
export const UnhandledError = {
  message: 'تعذّر قراءة الرد\nحاول مرة أخرى',
  errors: {},
  code: -400,
  diagnostic_code: 'UNHANDLED_RESPONSE_ERROR',
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
  const requestConfig = config as RoknRequestConfig;
  const isNetworkRetry = Number(requestConfig.roknNetworkRetryCount || 0) > 0;
  if (isNetworkRetry) {
    // Axios runs request interceptors again for the one read-only network
    // retry. Keep that retry attached to the session which started the
    // request: otherwise a Wi-Fi/mobile hand-off followed by logout/login can
    // silently resend the old screen's request with the next account's bearer
    // and accept its response as if it belonged to the original journey.
    await assertResponseStillBelongsToSession(
      requestConfig as Record<string, unknown>,
    );
  } else {
    requestConfig.roknSessionEpoch = peekSecureSession().epoch;
  }
  let userData: unknown = '';
  if (!isNetworkRetry && requestConfig.skipAuthorization !== true) {
    if (requestConfig.optionalAuthorization === true) {
      const snapshot = peekSecureSession();
      userData = snapshot.ready ? snapshot.session : '';
    } else {
      userData = (await getItem(AsyncKeys.USER_DATA)) || '';
    }
  }

  if (config?.method === 'post') {
    if (!config?.data) {
      config.data = {};
    }
  } else if (config?.method === 'get') {
    if (!config?.params) {
      config.params = {};
    }
  }

  const languageTag =
    typeof language === 'string' &&
    language.trim().toLowerCase().replace('_', '-').startsWith('en')
      ? 'en'
      : 'ar';
  config.headers.set('Accept-Language', languageTag);
  if (!config.headers.has('X-Request-Id')) {
    config.headers.set('X-Request-Id', secureRandomUuid());
  }
  config.headers.set('X-Rokn-Platform', Platform.OS);
  const installationId = await getInstallationId();
  if (installationId) {
    config.headers.set('X-Rokn-Installation', installationId);
  }
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
  if (
    apiToken &&
    requestConfig.skipAuthorization !== true &&
    !config.headers.has('Authorization')
  ) {
    config.headers.set('Authorization', `Bearer ${apiToken}`);
    requestConfig.roknSessionToken = apiToken;
  }

  return config;
};
export const responseError = (error: unknown) => Promise.reject(error);

export const publicRequest = axios.create({
  headers: headers,
  baseURL: mainUrl,
});
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
