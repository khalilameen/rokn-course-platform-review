/* eslint-env jest */

jest.mock('@react-native-async-storage/async-storage', () =>
  require('@react-native-async-storage/async-storage/jest/async-storage-mock'),
);

jest.mock('expo-secure-store', () => ({
  WHEN_UNLOCKED_THIS_DEVICE_ONLY: 'WHEN_UNLOCKED_THIS_DEVICE_ONLY',
  getItemAsync: jest.fn(),
  setItemAsync: jest.fn(),
  deleteItemAsync: jest.fn(),
}));

// Native push providers are exercised with scenario-specific factories in the
// push suite. Keep unrelated contract tests independent from unavailable iOS
// and Android runtimes.
jest.mock('expo-notifications', () => ({
  AndroidImportance: {MAX: 5},
  AndroidNotificationVisibility: {PUBLIC: 1},
  addNotificationResponseReceivedListener: jest.fn(() => ({remove: jest.fn()})),
  addPushTokenListener: jest.fn(() => ({remove: jest.fn()})),
  getDevicePushTokenAsync: jest.fn(),
  getLastNotificationResponseAsync: jest.fn(async () => null),
  getPermissionsAsync: jest.fn(async () => ({granted: false})),
  requestPermissionsAsync: jest.fn(async () => ({granted: false})),
  setNotificationChannelAsync: jest.fn(),
}));

jest.mock('@react-native-firebase/messaging', () => ({
  deleteToken: jest.fn(async () => undefined),
  getMessaging: jest.fn(() => ({})),
  getToken: jest.fn(async () => ''),
  onTokenRefresh: jest.fn(() => jest.fn()),
  registerDeviceForRemoteMessages: jest.fn(async () => undefined),
}));
