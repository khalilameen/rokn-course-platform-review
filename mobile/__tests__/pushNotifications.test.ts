const mockGetItem = jest.fn(async (_key: string): Promise<any> => null);
const mockSaveItem = jest.fn(async (_key: string, _value: unknown) => true);
const mockRemoveItem = jest.fn(async (_key: string) => true);
const mockPost = jest.fn(async (_url: string, _body: unknown) => ({data: {}}));
const mockDelete = jest.fn(async (_url: string, _config: unknown) => ({data: {}}));
const mockGetPermissions = jest.fn();
const mockRequestPermissions = jest.fn();
const mockGetDeviceToken = jest.fn();
const mockNavigate = jest.fn();
const mockDeleteNativeToken = jest.fn(async () => true);
const mockOpenUrl = jest.fn(async (_url?: unknown) => true);

jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
  Linking: {openURL: (url: unknown) => mockOpenUrl(url)},
  NativeModules: {
    RoknPushTokens: {deleteToken: () => mockDeleteNativeToken()},
  },
}));

jest.mock('expo-notifications', () => ({
  AndroidImportance: {DEFAULT: 3},
  setNotificationHandler: jest.fn(),
  setNotificationChannelAsync: jest.fn(async () => null),
  getPermissionsAsync: () => mockGetPermissions(),
  requestPermissionsAsync: () => mockRequestPermissions(),
  getDevicePushTokenAsync: () => mockGetDeviceToken(),
  addNotificationResponseReceivedListener: jest.fn(() => ({
    remove: jest.fn(),
  })),
  getLastNotificationResponseAsync: jest.fn(async () => null),
  clearLastNotificationResponseAsync: jest.fn(async () => undefined),
}));

jest.mock('../src/constants/helpers', () => ({
  AsyncKeys: {USER_DATA: 'USER_DATA'},
  accountScopedStorageKey: jest.fn(async (key: string) => `${key}:account-1`),
  extractApiToken: (session: any) => session?.api_token || null,
  getItem: (key: string) => mockGetItem(key),
  saveItem: (key: string, value: unknown) => mockSaveItem(key, value),
  removeItem: (key: string) => mockRemoveItem(key),
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    post: (url: string, body: unknown) => mockPost(url, body),
    delete: (url: string, config: unknown) => mockDelete(url, config),
  },
}));

jest.mock('../src/navigation/RootNavigationHelper', () => ({
  navigate: (...args: unknown[]) => mockNavigate(...args),
}));

import {
  openNotificationLink,
  registerPushDeviceIfEligible,
  unregisterPushDevice,
} from '../src/services/pushNotifications';
import {
  invalidateLocalPushDeviceRegistration,
  PUSH_TOKEN_INVALIDATION_PENDING_KEY,
  retryPendingNativePushTokenInvalidation,
} from '../src/services/pushDeviceState';

describe('push notification opt-in', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGetPermissions.mockResolvedValue({
      granted: true,
      status: 'granted',
      canAskAgain: true,
    });
    mockRequestPermissions.mockResolvedValue({
      granted: true,
      status: 'granted',
      canAskAgain: true,
    });
    mockGetDeviceToken.mockResolvedValue({type: 'fcm', data: 'fcm-token'});
  });

  it('never prompts or registers a guest', async () => {
    mockGetItem.mockResolvedValue(null);

    await expect(
      registerPushDeviceIfEligible({requestPermission: true}),
    ).resolves.toBe(false);
    expect(mockRequestPermissions).not.toHaveBeenCalled();
    expect(mockPost).not.toHaveBeenCalled();
  });

  it('registers the raw FCM token only after auth and stored opt-in', async () => {
    mockGetItem.mockImplementation(async (key: string) => {
      if (key === 'USER_DATA') return {api_token: 'session-token'};
      if (key.startsWith('PREF_NOTIFICATIONS')) return true;
      return null;
    });

    await expect(registerPushDeviceIfEligible()).resolves.toBe(true);
    expect(mockPost).toHaveBeenCalledWith('user/device-token', {
      device_token: 'fcm-token',
      device_type: 'android',
      device_os: 'android',
    });
  });

  it('removes this installation token when notifications are disabled', async () => {
    mockGetItem.mockImplementation(async (key: string) => {
      if (key === 'USER_DATA') return {api_token: 'session-token'};
      if (key.includes('@rokn/push-device-token')) return 'fcm-token';
      return null;
    });

    await expect(unregisterPushDevice()).resolves.toBe(true);
    expect(mockDelete).toHaveBeenCalledWith('user/device-token', {
      data: {device_token: 'fcm-token'},
    });
    expect(mockDeleteNativeToken).toHaveBeenCalledTimes(1);
    expect(mockRemoveItem).toHaveBeenCalledWith(
      '@rokn/push-device-token/v1:account-1',
    );
  });

  it('invalidates the native token even when server revoke is offline', async () => {
    mockGetItem.mockImplementation(async (key: string) => {
      if (key === 'USER_DATA') return {api_token: 'session-token'};
      if (key.includes('@rokn/push-device-token')) return 'fcm-token';
      return null;
    });
    mockDelete.mockRejectedValueOnce(new Error('offline'));

    await expect(unregisterPushDevice()).resolves.toBe(false);
    expect(mockDeleteNativeToken).toHaveBeenCalledTimes(1);
    expect(mockRemoveItem).toHaveBeenCalledWith(
      '@rokn/push-device-token/v1:account-1',
    );
  });

  it('retries a failed native deletion after restart without storing identity', async () => {
    mockDeleteNativeToken.mockRejectedValueOnce(new Error('firebase offline'));
    await expect(invalidateLocalPushDeviceRegistration()).resolves.toBe(false);
    expect(mockSaveItem).toHaveBeenCalledWith(
      PUSH_TOKEN_INVALIDATION_PENDING_KEY,
      true,
    );

    mockGetItem.mockImplementation(async (key: string) =>
      key === PUSH_TOKEN_INVALIDATION_PENDING_KEY ? true : null,
    );
    await expect(retryPendingNativePushTokenInvalidation()).resolves.toBe(true);
    expect(mockDeleteNativeToken).toHaveBeenCalledTimes(2);
    expect(mockRemoveItem).toHaveBeenCalledWith(
      PUSH_TOKEN_INVALIDATION_PENDING_KEY,
    );
  });

  it('fails closed when the native token module is missing', async () => {
    const reactNative = jest.requireMock('react-native') as {
      NativeModules: {RoknPushTokens?: unknown};
    };
    const module = reactNative.NativeModules.RoknPushTokens;
    delete reactNative.NativeModules.RoknPushTokens;

    await expect(invalidateLocalPushDeviceRegistration()).resolves.toBe(false);
    expect(mockSaveItem).toHaveBeenCalledWith(
      PUSH_TOKEN_INVALIDATION_PENDING_KEY,
      true,
    );
    reactNative.NativeModules.RoknPushTokens = module;
  });

  it('opens the relative course links emitted by the backend inside the app', async () => {
    await openNotificationLink({
      notification: {
        request: {content: {data: {link: '/courses/42'}}},
      },
    } as any);

    expect(mockNavigate).toHaveBeenCalledWith('CourseDetails', {
      courseId: '42',
    });
  });

  it('derives a continue-course destination from structured push data', async () => {
    await openNotificationLink({
      notification: {
        request: {
          content: {
            data: {
              notification_type: 'enrolled_stalled',
              course_id: 'course-72',
            },
          },
        },
      },
    } as any);

    expect(mockNavigate).toHaveBeenCalledWith('Reels', {
      courseId: 'course-72',
    });
  });

  it('ignores arbitrary external links supplied in a push payload', async () => {
    await openNotificationLink({
      notification: {
        request: {
          content: {data: {link: 'https://attacker.example/login'}},
        },
      },
    } as any);

    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockOpenUrl).not.toHaveBeenCalled();
  });
});
