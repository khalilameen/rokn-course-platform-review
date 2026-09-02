const mockConfigure = jest.fn();
const mockHasPlayServices = jest.fn();
const mockSignIn = jest.fn();

jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
}));

jest.mock('@react-native-google-signin/google-signin', () => ({
  GoogleSignin: {
    configure: (...args: unknown[]) => mockConfigure(...args),
    hasPlayServices: (...args: unknown[]) => mockHasPlayServices(...args),
    signIn: (...args: unknown[]) => mockSignIn(...args),
  },
  isSuccessResponse: (value: {type?: string}) => value?.type === 'success',
  statusCodes: {SIGN_IN_CANCELLED: 'SIGN_IN_CANCELLED'},
}));

import {
  hasNativeSocialCapability,
  signInWithNativeSocialProvider,
} from '../src/services/nativeSocialAuth';

describe('native social capability', () => {
  const originalWebClientId = process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID;

  afterEach(() => {
    jest.clearAllMocks();
    if (originalWebClientId === undefined) {
      delete process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID;
    } else {
      process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID = originalWebClientId;
    }
  });

  it('stays browser-capable without public native configuration', async () => {
    delete process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID;

    expect(hasNativeSocialCapability('google')).toBe(false);
    await expect(signInWithNativeSocialProvider('google')).resolves.toEqual({
      type: 'fallback',
    });
    expect(mockConfigure).not.toHaveBeenCalled();
  });

  it('returns a signed Google token when the native capability is complete', async () => {
    process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID =
      'public-web-client.apps.googleusercontent.com';
    mockHasPlayServices.mockResolvedValue(true);
    mockSignIn.mockResolvedValue({
      type: 'success',
      data: {idToken: 'signed-google-id-token'},
    });

    await expect(signInWithNativeSocialProvider('google')).resolves.toEqual({
      type: 'success',
      token: 'signed-google-id-token',
    });
    expect(mockConfigure).toHaveBeenCalledWith({
      webClientId: 'public-web-client.apps.googleusercontent.com',
      offlineAccess: false,
    });
    expect(mockHasPlayServices).toHaveBeenCalledTimes(1);
  });

  it('keeps Facebook on browser PKCE while native client config is incomplete', async () => {
    expect(hasNativeSocialCapability('facebook')).toBe(false);
    await expect(signInWithNativeSocialProvider('facebook')).resolves.toEqual({
      type: 'fallback',
    });
  });
});
