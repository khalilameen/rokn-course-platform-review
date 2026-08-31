const mockOpenAuthSession = jest.fn();
const mockOpenUrl = jest.fn();
let redirectHandler: ((event: {url: string}) => void) | undefined;

jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
  Linking: {
    addEventListener: jest.fn(
      (_event: string, handler: (event: {url: string}) => void) => {
        redirectHandler = handler;
        return {remove: jest.fn()};
      },
    ),
    openURL: (...args: unknown[]) => mockOpenUrl(...args),
  },
  AppState: {
    addEventListener: jest.fn(() => ({remove: jest.fn()})),
  },
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
  openAuthSessionAsync: (...args: unknown[]) => mockOpenAuthSession(...args),
}));

jest.mock('../src/constants/api', () => ({
  mainUrl: 'https://rokn.app/api/v1/',
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

import {signInWithSocialProvider} from '../src/services/socialAuth';

describe('browser social auth launch', () => {
  it('opens a deterministic encoded PKCE request on Android', async () => {
    mockOpenUrl.mockImplementation(async () => {
      redirectHandler?.({url: 'rokn://auth?error=LOGIN_CANCELLED'});
    });

    await expect(
      signInWithSocialProvider('google', {
        providers: ['google'],
        authorizationUrls: {
          google: 'not-a-runtime-safe-url',
        },
        welcomeBonus: 20,
        recommendedProvider: 'google',
        recommendationText: null,
      }),
    ).rejects.toThrow('LOGIN_CANCELLED');

    expect(mockOpenUrl).toHaveBeenCalledWith(
      expect.stringMatching(
        /^https:\/\/rokn\.app\/api\/v1\/social-auth\/google\/start\?return_to=rokn%3A%2F%2Fauth&code_challenge=[A-Za-z0-9_-]{43}&code_challenge_method=S256$/,
      ),
    );
    expect(mockOpenAuthSession).not.toHaveBeenCalled();
  });
});
