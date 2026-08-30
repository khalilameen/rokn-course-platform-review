import {NativeModules, Platform} from 'react-native';
import * as Notifications from 'expo-notifications';
import {
  deleteToken,
  getMessaging,
  getToken,
  onTokenRefresh,
  registerDeviceForRemoteMessages,
} from '@react-native-firebase/messaging';

type PushTokenNativeModule = {
  deleteToken?: () => Promise<boolean>;
};

const rawTokenValue = (value: unknown): string => {
  if (typeof value === 'string') return value.trim();
  if (value && typeof value === 'object' && 'data' in value) {
    return String((value as {data?: unknown}).data || '').trim();
  }
  return '';
};

const androidPushTokens = () =>
  NativeModules?.RoknPushTokens as PushTokenNativeModule | undefined;

/** Return the provider token understood by the backend on each native OS. */
export const getBackendPushToken = async () => {
  if (Platform.OS === 'ios') {
    const messaging = getMessaging();
    await registerDeviceForRemoteMessages(messaging);
    return (await getToken(messaging)).trim();
  }
  if (Platform.OS === 'android') {
    return rawTokenValue(await Notifications.getDevicePushTokenAsync());
  }
  return '';
};

/** Delete the provider token before forgetting the account binding locally. */
export const deleteBackendPushToken = async () => {
  if (Platform.OS === 'ios') {
    await deleteToken(getMessaging());
    return true;
  }
  if (Platform.OS === 'android') {
    const nativePushTokens = androidPushTokens();
    if (!nativePushTokens?.deleteToken) return false;
    return nativePushTokens
      .deleteToken()
      .then(result => result !== false)
      .catch(() => false);
  }
  return true;
};

/** Subscribe to token rotation without requesting notification permission. */
export const subscribeToBackendPushTokenRefresh = (
  listener: (token: string) => void,
) => {
  if (Platform.OS === 'ios') {
    return onTokenRefresh(getMessaging(), token => {
      const normalized = token.trim();
      if (normalized) listener(normalized);
    });
  }
  if (Platform.OS === 'android') {
    const subscription = Notifications.addPushTokenListener(token => {
      const normalized = rawTokenValue(token);
      if (normalized) listener(normalized);
    });
    return () => subscription.remove();
  }
  return () => undefined;
};
