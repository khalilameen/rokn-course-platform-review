import {NativeModules, Platform} from 'react-native';
import {
  accountScopedStorageKey,
  getItem,
  removeItem,
  saveItem,
} from '../constants/helpers';

export const PUSH_TOKEN_KEY = '@rokn/push-device-token/v1';
export const LEGACY_PUSH_UNREGISTER_PENDING_KEY =
  '@rokn/push-unregister-pending/v1';
export const PUSH_TOKEN_INVALIDATION_PENDING_KEY =
  '@rokn/push-token-invalidation-pending/v1';

type PushTokenNativeModule = {
  deleteToken?: () => Promise<boolean>;
};

const nativePushTokenModule = () =>
  NativeModules?.RoknPushTokens as PushTokenNativeModule | undefined;

export const pushStorageKey = (baseKey: string) =>
  accountScopedStorageKey(baseKey);

export const getStoredPushDeviceToken = async () =>
  getItem<string>(await pushStorageKey(PUSH_TOKEN_KEY));

const deleteAndroidPushToken = async () => {
  if (Platform.OS !== 'android') return true;
  const nativePushTokens = nativePushTokenModule();
  if (!nativePushTokens?.deleteToken) return false;
  return nativePushTokens
    .deleteToken()
    .then(result => result !== false)
    .catch(() => false);
};

/** Retry a device-only tombstone. It contains no token, bearer or account id. */
export const retryPendingNativePushTokenInvalidation = async () => {
  if (Platform.OS !== 'android') {
    await removeItem(PUSH_TOKEN_INVALIDATION_PENDING_KEY);
    return true;
  }
  const pending = await getItem<boolean>(PUSH_TOKEN_INVALIDATION_PENDING_KEY);
  if (!pending) return true;
  const deleted = await deleteAndroidPushToken();
  if (deleted) {
    await removeItem(PUSH_TOKEN_INVALIDATION_PENDING_KEY);
  }
  return deleted;
};

/**
 * Forget the account/token binding even if the API is offline. On Android the
 * Firebase SDK token is deleted first, so a stale server record can no longer
 * deliver private notifications. No bearer or account data is retained.
 */
export const invalidateLocalPushDeviceRegistration = async () => {
  // Resolve account-scoped keys before a concurrent logout removes the session.
  const [tokenKey, pendingKey] = await Promise.all([
    pushStorageKey(PUSH_TOKEN_KEY),
    pushStorageKey(LEGACY_PUSH_UNREGISTER_PENDING_KEY),
  ]);

  const nativeTokenDeleted = await deleteAndroidPushToken();
  if (nativeTokenDeleted) {
    await removeItem(PUSH_TOKEN_INVALIDATION_PENDING_KEY);
  } else {
    // Device-scoped tombstone only. Never retain a bearer, FCM token or PII.
    await saveItem(PUSH_TOKEN_INVALIDATION_PENDING_KEY, true);
  }

  await Promise.all([removeItem(tokenKey), removeItem(pendingKey)]);
  return nativeTokenDeleted;
};
