const mockGet = jest.fn();
const mockStorageGet = jest.fn();
const mockStorageSet = jest.fn();

jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: (...args: unknown[]) => mockStorageGet(...args),
  setItem: (...args: unknown[]) => mockStorageSet(...args),
}));

jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
  Dimensions: {get: () => ({width: 390, height: 844})},
  NativeModules: {StatusBarManager: {HEIGHT: 24}},
  StatusBar: {currentHeight: 24},
  StyleSheet: {create: (styles: unknown) => styles},
}));
jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));
jest.mock('expo-apple-authentication', () => ({
  AppleAuthenticationScope: {FULL_NAME: 0, EMAIL: 1},
  isAvailableAsync: jest.fn(),
  signInAsync: jest.fn(),
}));
jest.mock('expo-web-browser', () => ({
  maybeCompleteAuthSession: jest.fn(),
  openAuthSessionAsync: jest.fn(),
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: jest.fn(),
  },
}));
jest.mock('../src/services/secureSession', () => ({
  loadPendingSocialAuthAttempt: jest.fn(),
  savePendingSocialAuthAttempt: jest.fn(),
  replacePendingSocialAuthAttempt: jest.fn(),
  deletePendingSocialAuthAttempt: jest.fn(),
  saveSecureSession: jest.fn(),
}));

import {getSocialAuthMethods} from '../src/services/socialAuth';

describe('social auth discovery', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockStorageGet.mockResolvedValue(null);
    mockStorageSet.mockResolvedValue(undefined);
  });

  it('does not disguise an old mismatched host as a successful active-host configuration', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          providers: ['google', 'tiktok'],
          authorization_urls: {
            google: 'https://rokn.app/api/v1/social-auth/google/start',
            tiktok: null,
          },
          recommended_provider: 'google',
        },
      },
    });

    const methods = await getSocialAuthMethods();

    expect(methods.providers).toEqual([]);
    expect(methods.authorizationUrls).toEqual({});
    expect(mockGet).toHaveBeenCalledWith('auth-methods', {
      skipAuthorization: true,
    });
  });

  it('uses the backend-declared independent OAuth API host', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          providers: ['google'],
          authorization_api_url: 'https://identity.rokn.app/api/v1',
          authorization_urls: {
            google: 'https://identity.rokn.app/api/v1/social-auth/google/start',
          },
        },
      },
    });

    await expect(getSocialAuthMethods()).resolves.toMatchObject({
      providers: ['google'],
      authorizationApiUrl: 'https://identity.rokn.app/api/v1',
      authorizationUrls: {
        google: 'https://identity.rokn.app/api/v1/social-auth/google/start',
      },
    });
    expect(mockStorageSet).toHaveBeenCalledWith(
      '@rokn/social-auth-methods/v1',
      expect.stringContaining('identity.rokn.app'),
    );
  });

  it('keeps the last valid provider contract through a transient outage', async () => {
    mockGet.mockRejectedValue(new Error('NETWORK_UNAVAILABLE'));
    mockStorageGet.mockResolvedValue(
      JSON.stringify({
        savedAt: Date.now() - 60_000,
        methods: {
          providers: ['google'],
          authorizationApiUrl: 'https://identity.rokn.app/api/v1',
          authorizationUrls: {
            google: 'https://identity.rokn.app/api/v1/social-auth/google/start',
          },
          recommendedProvider: 'google',
          recommendationText: null,
          welcomeBonus: 20,
        },
      }),
    );

    await expect(getSocialAuthMethods()).resolves.toMatchObject({
      providers: ['google'],
      recommendedProvider: 'google',
      welcomeBonus: 20,
    });
  });

  it('keeps a claimed provider when a partial 200 response omits only its URL', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          providers: ['google', 'tiktok'],
          authorization_urls: {
            google:
              'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/google/start',
            tiktok: null,
          },
          recommended_provider: 'google',
        },
      },
    });
    mockStorageGet.mockResolvedValue(
      JSON.stringify({
        savedAt: Date.now() - 60_000,
        methods: {
          providers: ['google', 'tiktok'],
          authorizationApiUrl:
            'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1',
          authorizationUrls: {
            google:
              'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/google/start',
            tiktok:
              'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/tiktok/start',
          },
        },
      }),
    );

    await expect(getSocialAuthMethods()).resolves.toMatchObject({
      providers: ['google', 'tiktok'],
      authorizationUrls: {
        tiktok:
          'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/tiktok/start',
      },
    });
  });

  it('does not let the cache conceal an explicitly unsafe provider URL', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          providers: ['google'],
          authorization_urls: {
            google: 'https://attacker.example/api/v1/social-auth/google/start',
          },
        },
      },
    });
    mockStorageGet.mockResolvedValue(
      JSON.stringify({
        savedAt: Date.now() - 60_000,
        methods: {
          providers: ['google'],
          authorizationApiUrl:
            'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1',
          authorizationUrls: {
            google:
              'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/google/start',
          },
        },
      }),
    );

    await expect(getSocialAuthMethods()).resolves.toMatchObject({
      providers: [],
      authorizationUrls: {},
    });
  });

  it('does not let an expired provider contract hide a real outage', async () => {
    mockGet.mockRejectedValue(new Error('NETWORK_UNAVAILABLE'));
    mockStorageGet.mockResolvedValue(
      JSON.stringify({
        savedAt: Date.now() - 25 * 60 * 60 * 1000,
        methods: {
          providers: ['google'],
          authorizationApiUrl: 'https://identity.rokn.app/api/v1',
          authorizationUrls: {
            google: 'https://identity.rokn.app/api/v1/social-auth/google/start',
          },
        },
      }),
    );

    await expect(getSocialAuthMethods()).rejects.toThrow('NETWORK_UNAVAILABLE');
  });
});
