const mockGet = jest.fn();

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
  publicRequest: {get: (...args: unknown[]) => mockGet(...args), post: jest.fn()},
}));
jest.mock('../src/services/secureSession', () => ({
  loadPendingSocialAuthAttempt: jest.fn(),
  savePendingSocialAuthAttempt: jest.fn(),
  deletePendingSocialAuthAttempt: jest.fn(),
  saveSecureSession: jest.fn(),
}));

import {getSocialAuthMethods} from '../src/services/socialAuth';

describe('social auth discovery', () => {
  beforeEach(() => jest.clearAllMocks());

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
            google:
              'https://identity.rokn.app/api/v1/social-auth/google/start',
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
  });
});
