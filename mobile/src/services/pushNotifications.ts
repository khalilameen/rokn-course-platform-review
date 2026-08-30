import {Platform} from 'react-native';
import * as Notifications from 'expo-notifications';
import {publicRequest} from '../constants/api';
import {parseRoknDestination} from '../navigation/deepLinks';
import {navigate} from '../navigation/RootNavigationHelper';
import {
  AsyncKeys,
  extractApiToken,
  getItem,
  removeItem,
  saveItem,
} from '../constants/helpers';
import {getSmartRemindersEnabled} from './smartReminders';
import {
  getStoredPushDeviceToken,
  invalidateLocalPushDeviceRegistration,
  LEGACY_PUSH_UNREGISTER_PENDING_KEY,
  PUSH_TOKEN_KEY,
  pushStorageKey,
  retryPendingNativePushTokenInvalidation,
} from './pushDeviceState';
import {normalizeNotificationKind} from './notificationCampaigns';
import {
  getBackendPushToken,
  subscribeToBackendPushTokenRefresh,
} from './nativePushTokens';

const PUSH_CHANNELS = {
  updates: 'rokn-updates',
  learning: 'rokn-learning',
  offers: 'rokn-offers',
} as const;

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldPlaySound: false,
    shouldSetBadge: false,
    shouldShowBanner: true,
    shouldShowList: true,
  }),
});

const currentSessionToken = async () =>
  extractApiToken(await getItem(AsyncKeys.USER_DATA));

const permissionGranted = async (requestPermission: boolean) => {
  type PermissionSnapshot = {
    granted?: boolean;
    status?: string;
    canAskAgain?: boolean;
  };
  const current =
    (await Notifications.getPermissionsAsync()) as PermissionSnapshot;
  if (current.granted || current.status === 'granted') return true;
  if (!requestPermission || !current.canAskAgain) return false;
  const requested =
    (await Notifications.requestPermissionsAsync()) as PermissionSnapshot;
  return requested.granted || requested.status === 'granted';
};

const prepareAndroidChannel = async () => {
  if (Platform.OS !== 'android') return;
  await Promise.all([
    Notifications.setNotificationChannelAsync(PUSH_CHANNELS.updates, {
      name: 'تحديثات الحساب',
      description: 'نتائج المشاريع والشهادات وحركة الرصيد',
      importance: Notifications.AndroidImportance.DEFAULT,
      vibrationPattern: [0, 160],
      lightColor: '#2C69DB',
    }),
    Notifications.setNotificationChannelAsync(PUSH_CHANNELS.learning, {
      name: 'تذكيرات التعلّم',
      description: 'تذكيرات هادئة مرتبطة بمكانك داخل الكورس',
      importance: Notifications.AndroidImportance.DEFAULT,
      vibrationPattern: [0, 160],
      lightColor: '#2C69DB',
    }),
    Notifications.setNotificationChannelAsync(PUSH_CHANNELS.offers, {
      name: 'عروض ركن',
      description: 'الكورسات الجديدة وعروض الرصيد التي اخترت استقبالها',
      importance: Notifications.AndroidImportance.DEFAULT,
      vibrationPattern: [0, 160],
      lightColor: '#2C69DB',
    }),
  ]);
};

const registerTokenForCurrentAccount = async (token: string) => {
  if (!token || !(await currentSessionToken())) return false;
  if (!(await getSmartRemindersEnabled())) return false;

  const tokenKey = await pushStorageKey(PUSH_TOKEN_KEY);
  const previousToken = await getItem<string>(tokenKey);
  await publicRequest.post('user/device-token', {
    device_token: token,
    device_type: Platform.OS,
    device_os: Platform.OS,
  });
  await saveItem(tokenKey, token);
  await removeItem(await pushStorageKey(LEGACY_PUSH_UNREGISTER_PENDING_KEY));

  if (previousToken && previousToken !== token) {
    await publicRequest
      .delete('user/device-token', {data: {device_token: previousToken}})
      .catch(() => undefined);
  }

  return true;
};

/**
 * Register only after both account authentication and the learner's explicit
 * opt-in. Calling with requestPermission=false is safe during bootstrap: it
 * never opens an OS prompt.
 */
export const registerPushDeviceIfEligible = async ({
  requestPermission = false,
}: {requestPermission?: boolean} = {}) => {
  // Never mint/register a replacement while a pre-logout FCM token may still
  // be alive. The device-only tombstone is safe to retry even for a guest.
  if (!(await retryPendingNativePushTokenInvalidation())) return false;
  if (!(await currentSessionToken())) return false;
  if (!(await getSmartRemindersEnabled())) return false;
  if (!(await permissionGranted(requestPermission))) return false;

  await prepareAndroidChannel();
  const token = await getBackendPushToken();
  if (!token) return false;

  return registerTokenForCurrentAccount(token);
};

export const unregisterPushDevice = async () => {
  const token = await getStoredPushDeviceToken();
  let removedFromServer = !token;
  if (token && (await currentSessionToken())) {
    removedFromServer = await publicRequest
      .delete('user/device-token', {data: {device_token: token}})
      .then(() => true)
      .catch(() => false);
  }
  await invalidateLocalPushDeviceRegistration();
  return removedFromServer;
};

/** Read the token registered for this installation without making a request. */
export const getCurrentPushDeviceToken = async () => getStoredPushDeviceToken();

/** Invalidate Firebase and clear local registration after a logout attempt. */
export const clearCurrentPushDeviceRegistration = () =>
  invalidateLocalPushDeviceRegistration();

/** Reconcile token rotation or a previously interrupted unregister. Never prompts. */
export const reconcilePushRegistration = async () => {
  // Runs during bootstrap and foreground transitions even for guests.
  if (!(await retryPendingNativePushTokenInvalidation())) return false;
  if (!(await currentSessionToken())) return false;
  const optedIn = await getSmartRemindersEnabled();
  if (optedIn) {
    return registerPushDeviceIfEligible({requestPermission: false}).catch(
      () => false,
    );
  }
  return unregisterPushDevice().catch(() => false);
};

export const subscribeToPushTokenRefresh = () =>
  subscribeToBackendPushTokenRefresh(token => {
    void registerTokenForCurrentAccount(token).catch(() => undefined);
  });

export const openNotificationLink = async (
  response: Notifications.NotificationResponse,
) => {
  const data = response.notification.request.content.data || {};
  const explicitLink = [data.link, data.deep_link, data.action_url].find(
    value => typeof value === 'string' && value.trim(),
  );
  const courseId =
    typeof data.course_id === 'string' ? data.course_id.trim() : '';
  const kind = normalizeNotificationKind(data.notification_type || data.type);
  const link =
    typeof explicitLink === 'string'
      ? explicitLink
      : courseId
      ? `rokn://course/${encodeURIComponent(courseId)}${
          kind === 'continue_course' || kind === 'learning_reminder'
            ? '/watch'
            : ''
        }`
      : kind === 'coin_offer' || kind === 'coin_reward'
      ? 'rokn://wallet'
      : 'rokn://home';
  const destination = parseRoknDestination(link);
  if (destination) {
    navigate(
      destination.name,
      'params' in destination ? destination.params : undefined,
    );
    return;
  }
};

export const subscribeToPushResponses = () => {
  const subscription = Notifications.addNotificationResponseReceivedListener(
    response => {
      void openNotificationLink(response);
    },
  );

  void Notifications.getLastNotificationResponseAsync()
    .then(async response => {
      if (!response) return;
      await openNotificationLink(response);
      await Notifications.clearLastNotificationResponseAsync();
    })
    .catch(() => undefined);

  return () => subscription.remove();
};
