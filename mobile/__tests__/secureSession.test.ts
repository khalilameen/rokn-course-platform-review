import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import {NativeModules, Platform} from 'react-native';
import {
  assertSecureSessionStorageAvailable,
  deleteSecureSession,
  extractApiToken,
  extractUserProfile,
  migrateLegacySession,
  resetSecureSessionMigrationForTests,
  restoreSecureAuthState,
  sanitizeSessionForStorage,
  saveSecureSession,
  secureStoreOptionsForPlatform,
} from '../src/services/secureSession';

const secureValues = new Map<string, string>();
const secureGet = SecureStore.getItemAsync as jest.MockedFunction<
  typeof SecureStore.getItemAsync
>;
const secureSet = SecureStore.setItemAsync as jest.MockedFunction<
  typeof SecureStore.setItemAsync
>;
const secureDelete = SecureStore.deleteItemAsync as jest.MockedFunction<
  typeof SecureStore.deleteItemAsync
>;
const secureIsAvailable = SecureStore.isAvailableAsync as jest.MockedFunction<
  typeof SecureStore.isAvailableAsync
>;

describe('secure mobile session persistence', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    secureValues.clear();
    resetSecureSessionMigrationForTests();
    await AsyncStorage.clear();
    secureGet.mockImplementation(async key => secureValues.get(key) ?? null);
    secureIsAvailable.mockResolvedValue(true);
    secureSet.mockImplementation(async (key, value) => {
      secureValues.set(key, value);
    });
    secureDelete.mockImplementation(async key => {
      secureValues.delete(key);
    });
  });

  it('passes iOS keychain accessibility only to the iOS native module', () => {
    expect(secureStoreOptionsForPlatform('android')).toEqual({});
    expect(secureStoreOptionsForPlatform('ios')).toEqual({
      keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
    });
  });

  it('proves secure storage can round-trip before opening a social provider', async () => {
    await expect(assertSecureSessionStorageAvailable()).resolves.toBeUndefined();
    expect(secureSet).toHaveBeenCalledWith(
      'rokn.auth.storage-probe.v1',
      expect.stringMatching(/^rokn-\d+$/),
      expect.any(Object),
    );
    expect(secureValues.has('rokn.auth.storage-probe.v1')).toBe(false);
  });

  it('uses the Rokn Android Keystore bridge on Android releases', async () => {
    const originalPlatform = Platform.OS;
    const androidValues = new Map<string, string>();
    const nativeModule = {
      setItem: jest.fn(async (key: string, value: string) => {
        androidValues.set(key, value);
      }),
      getItem: jest.fn(async (key: string) => androidValues.get(key) ?? null),
      deleteItem: jest.fn(async (key: string) => {
        androidValues.delete(key);
      }),
    };
    Object.defineProperty(Platform, 'OS', {value: 'android', configurable: true});
    NativeModules.RoknSecureSession = nativeModule;
    resetSecureSessionMigrationForTests();

    try {
      await expect(
        assertSecureSessionStorageAvailable(),
      ).resolves.toBeUndefined();
      expect(nativeModule.setItem).toHaveBeenCalledWith(
        'rokn.auth.storage-probe.v1',
        expect.stringMatching(/^rokn-\d+$/),
      );
      expect(nativeModule.deleteItem).toHaveBeenCalledWith(
        'rokn.auth.storage-probe.v1',
      );
      expect(secureSet).not.toHaveBeenCalled();
    } finally {
      delete NativeModules.RoknSecureSession;
      Object.defineProperty(Platform, 'OS', {
        value: originalPlatform,
        configurable: true,
      });
      resetSecureSessionMigrationForTests();
    }
  });

  it('fails before OAuth when the native secure store cannot persist', async () => {
    secureSet.mockRejectedValueOnce(new Error('native write failed'));
    await expect(assertSecureSessionStorageAvailable()).rejects.toThrow(
      'SESSION_STORAGE_UNAVAILABLE',
    );
  });

  it('migrates both legacy plaintext copies without losing the signed-in user', async () => {
    const legacySession = {
      api_token: 'legacy-api-token',
      access_token: 'provider-token',
      user: {id: 42, name: 'Rokn learner', refresh_token: 'refresh-token'},
    };
    await AsyncStorage.setItem('USER_DATA', JSON.stringify(legacySession));
    await AsyncStorage.setItem(
      'persist:auth',
      JSON.stringify({
        userData: JSON.stringify(legacySession),
        isLogin: 'true',
      }),
    );

    const restoredAuth = await restoreSecureAuthState();
    const restored = restoredAuth.session;

    expect(restoredAuth.isAuthenticated).toBe(true);
    expect(extractApiToken(restored)).toBe('legacy-api-token');
    expect(extractUserProfile(restored)).toMatchObject({
      id: 42,
      name: 'Rokn learner',
    });
    expect(secureSet).toHaveBeenCalledWith(
      'rokn.auth.api-token.v1',
      'legacy-api-token',
      expect.any(Object),
    );
    expect(await AsyncStorage.getItem('persist:auth')).toBeNull();
    const plaintextProfile = await AsyncStorage.getItem('USER_DATA');
    expect(plaintextProfile).not.toContain('legacy-api-token');
    expect(plaintextProfile).not.toContain('provider-token');
    expect(plaintextProfile).not.toContain('refresh-token');
  });

  it('does not erase a legacy token when the secure migration write fails', async () => {
    const legacy = {api_token: 'keep-me', user: {id: 9}};
    await AsyncStorage.setItem('USER_DATA', JSON.stringify(legacy));
    await AsyncStorage.setItem(
      'persist:auth',
      JSON.stringify({userData: JSON.stringify(legacy)}),
    );
    secureSet.mockRejectedValueOnce(new Error('keychain unavailable'));

    await expect(migrateLegacySession()).rejects.toThrow(
      'keychain unavailable',
    );

    expect(await AsyncStorage.getItem('USER_DATA')).toContain('keep-me');
    expect(await AsyncStorage.getItem('persist:auth')).toContain('keep-me');
  });

  it('stores only the API token securely and keeps a sanitized profile', async () => {
    const session = {
      api_token: 'api-secret',
      access_token: 'social-secret',
      oauthToken: 'oauth-secret',
      jwt: 'signed-jwt',
      user: {
        id: 7,
        name: 'Student',
        password: 'never-store-this',
        provider_token: 'nested-provider-secret',
        clientSecret: 'nested-client-secret',
        token_balance: 120,
      },
    };

    await saveSecureSession(session);

    expect(secureValues.get('rokn.auth.api-token.v1')).toBe('api-secret');
    expect(await AsyncStorage.getItem('USER_DATA')).toBe(
      JSON.stringify({user: {id: 7, name: 'Student', token_balance: 120}}),
    );
    expect(sanitizeSessionForStorage(session)).toEqual({
      user: {id: 7, name: 'Student', token_balance: 120},
    });
  });

  it('re-sanitizes an already migrated profile when credential aliases evolve', async () => {
    await AsyncStorage.setItem(
      'USER_DATA',
      JSON.stringify({
        user: {id: 8, name: 'Learner'},
        providerToken: 'old-provider-token',
      }),
    );
    await AsyncStorage.setItem('@rokn/auth-secure-migration-v1', '1');
    secureValues.set('rokn.auth.api-token.v1', 'secure-api-token');

    const restored = await restoreSecureAuthState();

    expect(restored.isAuthenticated).toBe(true);
    expect(restored.session).toMatchObject({
      api_token: 'secure-api-token',
      user: {id: 8, name: 'Learner'},
    });
    expect(await AsyncStorage.getItem('USER_DATA')).toBe(
      JSON.stringify({user: {id: 8, name: 'Learner'}}),
    );
  });

  it('removes secure and legacy session copies on logout', async () => {
    secureValues.set('rokn.auth.api-token.v1', 'api-secret');
    await AsyncStorage.setItem('USER_DATA', JSON.stringify({user: {id: 7}}));
    await AsyncStorage.setItem('persist:auth', '{"userData":"{}"}');

    await expect(deleteSecureSession()).resolves.toBe(true);

    expect(secureValues.has('rokn.auth.api-token.v1')).toBe(false);
    expect(await AsyncStorage.getItem('USER_DATA')).toBeNull();
    expect(await AsyncStorage.getItem('persist:auth')).toBeNull();
  });

  it('hydrates the keychain once and serves later request interceptors from memory', async () => {
    await saveSecureSession({api_token: 'cached-token', user: {id: 15}});
    resetSecureSessionMigrationForTests();
    secureGet.mockClear();

    const first = await restoreSecureAuthState();
    const readsAfterHydration = secureGet.mock.calls.length;
    const second = await restoreSecureAuthState();

    expect(first.session).toEqual(second.session);
    expect(readsAfterHydration).toBeGreaterThan(0);
    expect(secureGet).toHaveBeenCalledTimes(readsAfterHydration);
  });
});
