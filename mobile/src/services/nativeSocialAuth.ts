import {Platform} from 'react-native';
import type {SocialProvider} from './socialAuth';

export type NativeSocialProvider = Extract<
  SocialProvider,
  'google' | 'facebook'
>;

export type NativeSocialResult =
  | {type: 'success'; token: string}
  | {type: 'cancel'}
  | {type: 'fallback'};

const configuredGoogleClientIds = () => {
  const webClientId = process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID?.trim() || '';
  const iosClientId = process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID?.trim() || '';
  return {webClientId, iosClientId};
};

/**
 * A provider is native-capable only when both its JS bridge and public native
 * configuration are complete. Facebook deliberately remains browser-first
 * until its public app id and client token are installed together; exposing a
 * half-configured SDK turns an otherwise working PKCE login into a dead end.
 */
export const hasNativeSocialCapability = (
  provider: NativeSocialProvider,
): boolean => {
  if (provider === 'facebook') return false;
  const {webClientId, iosClientId} = configuredGoogleClientIds();
  return Boolean(webClientId && (Platform.OS !== 'ios' || iosClientId));
};

export const signInWithNativeSocialProvider = async (
  provider: NativeSocialProvider,
): Promise<NativeSocialResult> => {
  if (!hasNativeSocialCapability(provider) || provider !== 'google') {
    return {type: 'fallback'};
  }

  const {webClientId, iosClientId} = configuredGoogleClientIds();
  try {
    // Loaded only after the capability gate. Builds without native Google
    // configuration continue through browser PKCE instead of failing at app
    // startup or hiding the provider.
    const google = require('@react-native-google-signin/google-signin') as typeof import('@react-native-google-signin/google-signin');
    google.GoogleSignin.configure({
      webClientId,
      ...(iosClientId ? {iosClientId} : {}),
      offlineAccess: false,
    });
    if (Platform.OS === 'android') {
      await google.GoogleSignin.hasPlayServices({
        showPlayServicesUpdateDialog: true,
      });
    }
    const response = await google.GoogleSignin.signIn();
    if (!google.isSuccessResponse(response)) {
      return {type: 'cancel'};
    }
    const token = response.data.idToken?.trim() || '';
    return token ? {type: 'success', token} : {type: 'fallback'};
  } catch (error: unknown) {
    const code =
      typeof error === 'object' && error !== null && 'code' in error
        ? String(error.code)
        : '';
    let cancelledCode = '';
    try {
      const google = require('@react-native-google-signin/google-signin') as typeof import('@react-native-google-signin/google-signin');
      cancelledCode = String(google.statusCodes.SIGN_IN_CANCELLED);
    } catch {
      // A missing native bridge is a capability miss, not a learner-facing
      // login error. Browser PKCE remains authoritative.
    }
    return code && code === cancelledCode
      ? {type: 'cancel'}
      : {type: 'fallback'};
  }
};
