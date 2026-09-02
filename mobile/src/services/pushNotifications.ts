import {Platform} from 'react-native';
import * as Notifications from 'expo-notifications';
import {BrandColors} from '../constants/brandTokens';
import {publicRequest, type RoknRequestConfig} from '../constants/api';
import {
  parseRoknDestination,
  safeRoknRouteId,
} from '../navigation/deepLinks';
import {
  navigate,
  openRoknDestination,
} from '../navigation/RootNavigationHelper';
import {
  AsyncKeys,
  extractApiToken,
  getCurrentAccountStorageScope,
  getItem,
  removeItem,
  saveItem,
} from '../constants/helpers';
import {
  cancelLearningReminders,
  getSmartRemindersEnabled,
} from './smartReminders';
import {
  getStoredPushDeviceToken,
  invalidateLocalPushDeviceRegistration,
  LEGACY_PUSH_UNREGISTER_PENDING_KEY,
  PUSH_TOKEN_KEY,
  pushStorageKey,
  retryPendingNativePushTokenInvalidation,
} from './pushDeviceState';
import {normalizeNotificationKind} from './notificationCampaigns';
import {getInstallationId} from './installationIdentity';
import {
  getNotification,
  markNotificationRead,
} from './api/notifications';
import {
  getBackendPushToken,
  subscribeToBackendPushTokenRefresh,
} from './nativePushTokens';

const PUSH_CHANNELS = {
  updates: 'rokn-updates',
  learning: 'rokn-learning',
  offers: 'rokn-offers',
} as const;

const PENDING_NOTIFICATION_OPEN_KEY = '@rokn/push-open-pending/v1';

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

const sessionStillCurrent = async (token: string, accountScope: string) =>
  (await currentSessionToken()) === token &&
  (await getCurrentAccountStorageScope()) === accountScope;

const removeTokenFromCapturedSession = async (
  token: string,
  sessionToken: string,
) => {
  await publicRequest.delete('user/device-token', {
    data: {device_token: token},
    headers: {Authorization: `Bearer ${sessionToken}`},
    skipPersistedSessionInvalidation: true,
  } as RoknRequestConfig);
};

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

export const prepareNotificationChannels = async () => {
  if (Platform.OS !== 'android') return;
  await Promise.all([
    Notifications.setNotificationChannelAsync(PUSH_CHANNELS.updates, {
      name: 'تحديثات الحساب',
      description: 'نتائج المشاريع والشهادات وحركة الرصيد',
      importance: Notifications.AndroidImportance.DEFAULT,
      vibrationPattern: [0, 160],
      lightColor: BrandColors.primary,
    }),
    Notifications.setNotificationChannelAsync(PUSH_CHANNELS.learning, {
      name: 'تذكيرات التعلّم',
      description: 'تذكيرات هادئة مرتبطة بمكانك داخل الكورس',
      importance: Notifications.AndroidImportance.DEFAULT,
      vibrationPattern: [0, 160],
      lightColor: BrandColors.primary,
    }),
    Notifications.setNotificationChannelAsync(PUSH_CHANNELS.offers, {
      name: 'عروض ركن',
      description: 'الكورسات الجديدة وعروض الرصيد التي اخترت استقبالها',
      importance: Notifications.AndroidImportance.DEFAULT,
      vibrationPattern: [0, 160],
      lightColor: BrandColors.primary,
    }),
  ]);
};

const registerTokenForCurrentAccount = async (token: string) => {
  const sessionToken = await currentSessionToken();
  if (!token || !sessionToken) return false;
  if (!(await getSmartRemindersEnabled())) return false;

  const accountScope = await getCurrentAccountStorageScope();
  const tokenKey = await pushStorageKey(PUSH_TOKEN_KEY);
  const previousToken = await getItem<string>(tokenKey);
  const installationId = await getInstallationId();
  if (!(await sessionStillCurrent(sessionToken, accountScope))) return false;
  try {
    await publicRequest.post('user/device-token', {
      device_token: token,
      device_type: Platform.OS,
      device_os: Platform.OS,
      ...(installationId ? {device_id: installationId} : {}),
    });
  } catch (error) {
    if (
      error instanceof Error &&
      error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
    ) {
      await removeTokenFromCapturedSession(token, sessionToken).catch(
        () => undefined,
      );
    }
    throw error;
  }
  if (!(await sessionStillCurrent(sessionToken, accountScope))) {
    await removeTokenFromCapturedSession(token, sessionToken).catch(
      () => undefined,
    );
    return false;
  }
  await saveItem(tokenKey, token);
  // The backend now owns authenticated learning-reminder cadence. Clear any
  // guest timer left on this installation before login so it cannot double it.
  cancelLearningReminders();
  await removeItem(
    await pushStorageKey(LEGACY_PUSH_UNREGISTER_PENDING_KEY),
  );

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

  await prepareNotificationChannels();
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
export const clearCurrentPushDeviceRegistration = async () => {
  const pendingKey = await pendingNotificationStorageKey();
  pushNavigationGeneration += 1;
  pendingNotificationResponse = null;
  openNotificationByIdFlights.clear();
  responseOpenFlights.clear();
  recentlyOpenedResponses.clear();
  const [invalidation] = await Promise.allSettled([
    invalidateLocalPushDeviceRegistration(),
    removeItem(pendingKey),
    Notifications.clearLastNotificationResponseAsync(),
    // Delivered notifications belong to the account being closed. Leaving
    // their title/body in the OS tray leaks the previous learner's inbox on a
    // shared phone even though tap routing itself is account-scoped.
    Notifications.dismissAllNotificationsAsync(),
    Notifications.setBadgeCountAsync(0),
  ]);
  if (invalidation.status === 'rejected') throw invalidation.reason;
  return invalidation.value;
};

/** Reconcile token rotation or a previously interrupted unregister. Never prompts. */
export const reconcilePushRegistration = async () => {
  // Runs during bootstrap and foreground transitions even for guests.
  const hasSession = Boolean(await currentSessionToken());
  if (hasSession) {
    // Authentication transfers reminder ownership to the backend even before
    // a token refresh succeeds. Retire a guest timer here as well as after
    // registration, otherwise an offline first login can fire the old local
    // reminder beside the durable inbox campaign later.
    cancelLearningReminders();
  }
  if (!(await retryPendingNativePushTokenInvalidation())) return false;
  if (!hasSession) return false;
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

const navigateToNotificationData = (
  data: Record<string, unknown>,
) => {
  const explicitLink = [data.link, data.deep_link, data.action_url].find(
    value => typeof value === 'string' && value.trim(),
  );
  const courseId = safeRoknRouteId(data.course_id);
  const kind = normalizeNotificationKind(data.notification_type || data.type);
  const explicitDestination =
    typeof explicitLink === 'string'
      ? parseRoknDestination(explicitLink)
      : null;
  const fallbackLink = courseId
      ? `rokn://course/${encodeURIComponent(courseId)}${
          kind === 'continue_course' || kind === 'learning_reminder'
            ? '/watch'
            : ''
        }`
      : kind === 'coin_offer' || kind === 'coin_reward'
      ? 'rokn://wallet'
      : 'rokn://home';
  const destination =
    explicitDestination || parseRoknDestination(fallbackLink);
  if (destination) {
    return openRoknDestination(destination);
  }
  return false;
};

const notificationIdFromResponse = (
  response: Notifications.NotificationResponse,
) => {
  const value = response.notification.request.content.data?.notification_id;
  const id = String(value || '').trim();
  return /^\d+$/.test(id) ? id : null;
};

const pendingNotificationStorageKey = () =>
  pushStorageKey(PENDING_NOTIFICATION_OPEN_KEY);

const openNotificationByIdFlights = new Map<string, Promise<boolean>>();
const responseOpenFlights = new Map<string, Promise<boolean>>();
const recentlyOpenedResponses = new Map<string, number>();
const RESPONSE_DEDUPE_MS = 8_000;
let pushNavigationGeneration = 0;

const notificationResponseKey = (
  response: Notifications.NotificationResponse,
) => {
  const identifier = String(response.notification.request.identifier || '').trim();
  const action = String(response.actionIdentifier || 'default').trim();
  if (identifier) return `${identifier}:${action}`;
  // Defensive compatibility for vendor bridges that omit the native request
  // identifier. Do not collapse every such tap into one global "undefined"
  // key or a second, different notification will be ignored for eight seconds.
  let payload = '';
  try {
    payload = JSON.stringify(response.notification.request.content.data || {});
  } catch {
    payload = String(response.notification.request.content.title || 'notification');
  }
  return `payload:${payload.slice(0, 1000)}:${action}`;
};

const openNotificationById = async (notificationId: string) => {
  const accountScope = await getCurrentAccountStorageScope();
  const generation = pushNavigationGeneration;
  const flightKey = `${accountScope}:${notificationId}`;
  const existing = openNotificationByIdFlights.get(flightKey);
  if (existing) return existing;
  const flight = getNotification(notificationId)
    .then(async notification => {
      if (
        generation !== pushNavigationGeneration ||
        (await getCurrentAccountStorageScope()) !== accountScope
      ) {
        return false;
      }
      const opened = navigateToNotificationData({
        link: notification.link,
        notification_type: notification.kind,
        course_id: notification.courseId,
      });
      if (opened) {
        void markNotificationRead(notificationId).catch(() => undefined);
      }
      return opened;
    })
    .catch(async error => {
      if (
        generation !== pushNavigationGeneration ||
        (await getCurrentAccountStorageScope()) !== accountScope
      ) {
        return false;
      }
      const status = Number(
        (error as {status?: unknown})?.status ||
          (error as {response?: {status?: unknown}})?.response?.status ||
          0,
      );
      // The inbox may have been pruned after the OS kept an old tap. Never
      // route from its stale push payload; open the current inbox instead.
      if (status === 404) return navigate('Notifications');
      // A notification tap must still lead somewhere useful while the network
      // is slow or unavailable. The inbox owns its account-scoped stale cache
      // and retry state, so it is the safe fallback for every fetch failure.
      return navigate('Notifications');
    })
    .finally(() => {
      if (openNotificationByIdFlights.get(flightKey) === flight) {
        openNotificationByIdFlights.delete(flightKey);
      }
    });
  openNotificationByIdFlights.set(flightKey, flight);
  return flight;
};

export const openNotificationLink = async (
  response: Notifications.NotificationResponse,
) => {
  const accountScope = await getCurrentAccountStorageScope();
  const generation = pushNavigationGeneration;
  const responseKey = `${accountScope}:${notificationResponseKey(response)}`;
  const recentOpen = recentlyOpenedResponses.get(responseKey) || 0;
  const elapsed = Date.now() - recentOpen;
  if (elapsed >= 0 && elapsed < RESPONSE_DEDUPE_MS) return true;
  const existing = responseOpenFlights.get(responseKey);
  if (existing) return existing;

  const flight = (async () => {
    if (
      generation !== pushNavigationGeneration ||
      (await getCurrentAccountStorageScope()) !== accountScope
    ) {
      return false;
    }
    const notificationId = notificationIdFromResponse(response);
    if (notificationId && !(await currentSessionToken())) {
      // A tap can outlive the account that received it. Its durable inbox row
      // must never open under a guest or a later account from the old payload.
      pendingNotificationResponse = null;
      await removeItem(await pendingNotificationStorageKey());
      await Notifications.clearLastNotificationResponseAsync();
      return false;
    }
    const opened = notificationId
      ? await openNotificationById(notificationId)
      : navigateToNotificationData(
          (response.notification.request.content.data || {}) as Record<
            string,
            unknown
          >,
        );
    if (opened) {
      const now = Date.now();
      recentlyOpenedResponses.set(responseKey, now);
      recentlyOpenedResponses.forEach((openedAt, key) => {
        if (now - openedAt >= RESPONSE_DEDUPE_MS) {
          recentlyOpenedResponses.delete(key);
        }
      });
    }
    return opened;
  })().finally(() => {
    if (responseOpenFlights.get(responseKey) === flight) {
      responseOpenFlights.delete(responseKey);
    }
  });
  responseOpenFlights.set(responseKey, flight);
  return flight;
};

let pendingNotificationResponse: {
  response: Notifications.NotificationResponse;
  clearNativeResponse: boolean;
} | null = null;
let notificationNavigationReady = false;

export const setNotificationNavigationReady = (ready: boolean) => {
  notificationNavigationReady = ready;
};

const deliverNotificationResponse = async (
  response: Notifications.NotificationResponse,
  clearNativeResponse: boolean,
) => {
  const notificationId = notificationIdFromResponse(response);
  if (notificationId && !(await currentSessionToken())) {
    pendingNotificationResponse = null;
    await removeItem(await pendingNotificationStorageKey());
    await Notifications.clearLastNotificationResponseAsync();
    return true;
  }
  if (notificationId) {
    await saveItem(await pendingNotificationStorageKey(), notificationId);
  }
  if (!notificationNavigationReady) {
    pendingNotificationResponse = {response, clearNativeResponse};
    return false;
  }
  if (!(await openNotificationLink(response))) {
    pendingNotificationResponse = {response, clearNativeResponse};
    return false;
  }
  if (notificationId) {
    await removeItem(await pendingNotificationStorageKey());
  }
  if (clearNativeResponse) {
    await Notifications.clearLastNotificationResponseAsync();
  }
  return true;
};

/** Complete a notification tap only after NavigationContainer is ready. */
export const flushPendingNotificationNavigation = async () => {
  if (!notificationNavigationReady) return false;
  const storedKey = await pendingNotificationStorageKey();
  const storedId = await getItem<string>(storedKey);
  if (storedId && /^\d+$/.test(storedId)) {
    if (!(await openNotificationById(storedId))) return false;
    await removeItem(storedKey);
    pendingNotificationResponse = null;
    await Notifications.clearLastNotificationResponseAsync();
    return true;
  }
  if (storedId) await removeItem(storedKey);

  const pending = pendingNotificationResponse;
  if (!pending) return false;
  if (!(await openNotificationLink(pending.response))) return false;

  pendingNotificationResponse = null;
  if (pending.clearNativeResponse) {
    await Notifications.clearLastNotificationResponseAsync();
  }
  return true;
};

export const subscribeToPushResponses = () => {
  const subscription = Notifications.addNotificationResponseReceivedListener(
    response => {
      void deliverNotificationResponse(response, false);
    },
  );

  void Notifications.getLastNotificationResponseAsync()
    .then(async response => {
      if (!response) return;
      await deliverNotificationResponse(response, true);
    })
    .catch(() => undefined);
  void flushPendingNotificationNavigation().catch(() => undefined);

  return () => subscription.remove();
};
