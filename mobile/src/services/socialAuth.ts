import {Platform} from 'react-native';
import * as AppleAuthentication from 'expo-apple-authentication';
import * as Crypto from 'expo-crypto';
import * as WebBrowser from 'expo-web-browser';
import {mainUrl, publicRequest} from '../constants/api';
import {resolveSocialAuthStartUrl} from './socialAuthUrlPolicy';

export type SocialProvider = 'google' | 'tiktok' | 'facebook' | 'apple';

export type SocialAuthMethods = {
  providers: SocialProvider[];
  authorizationUrls: Partial<Record<SocialProvider, string>>;
  welcomeBonus: number | null;
  recommendedProvider: SocialProvider | null;
  recommendationText: string | null;
};

export type SocialAuthSession = Record<string, unknown> & {
  api_token: string;
  user: Record<string, unknown> & {
    name: string;
    email: string | null;
    social_provider: SocialProvider;
  };
};

WebBrowser.maybeCompleteAuthSession();

const queryValue = (url: string, key: string) => {
  const query = url.split('?')[1]?.split('#')[0] || '';
  for (const part of query.split('&')) {
    const [rawKey, ...rawValue] = part.split('=');
    if (decodeURIComponent(rawKey || '') === key) {
      return decodeURIComponent(rawValue.join('=') || '');
    }
  }
  return '';
};

const isSocialProvider = (value: string): value is SocialProvider =>
  ['google', 'tiktok', 'facebook', 'apple'].includes(value);

const nonEmptyString = (value: unknown) =>
  typeof value === 'string' && value.trim() ? value.trim() : '';

const asRecord = (value: unknown): Record<string, unknown> | null =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;

const authorizationUrls = (
  value: unknown,
): Partial<Record<SocialProvider, string>> => {
  const record = asRecord(value);
  if (!record) return {};
  return Object.fromEntries(
    Object.entries(record).filter(
      ([provider, url]) =>
        isSocialProvider(provider) && typeof url === 'string' && url.trim(),
    ),
  ) as Partial<Record<SocialProvider, string>>;
};

const createPkcePair = async () => {
  const verifier = `${Crypto.randomUUID()}${Crypto.randomUUID()}`.replace(
    /-/g,
    '',
  );
  const digest = await Crypto.digestStringAsync(
    Crypto.CryptoDigestAlgorithm.SHA256,
    verifier,
    {encoding: Crypto.CryptoEncoding.BASE64},
  );
  return {
    verifier,
    challenge: digest
      .replace(/\+/g, '-')
      .split('/')
      .join('_')
      .split('=')
      .join(''),
  };
};

const createAppleNonce = async () => {
  const bytes = await Crypto.getRandomBytesAsync(32);
  const raw = Array.from(bytes, byte =>
    byte.toString(16).padStart(2, '0'),
  ).join('');
  const digest = (
    await Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, raw, {
      encoding: Crypto.CryptoEncoding.HEX,
    })
  ).toLowerCase();

  if (!/^[a-f0-9]{64}$/.test(raw) || !/^[a-f0-9]{64}$/.test(digest)) {
    throw new Error('APPLE_NONCE_GENERATION_FAILED');
  }

  return {raw, digest};
};

const normalizeSocialSession = (
  payload: unknown,
  provider: SocialProvider,
): SocialAuthSession => {
  const envelope = asRecord(payload) ?? {};
  const data = asRecord(envelope.data);
  const session = asRecord(data?.data) ?? data ?? envelope;
  const profile = asRecord(session.user) ?? asRecord(session.profile);
  const apiToken = nonEmptyString(
    session.api_token ?? session.access_token ?? profile?.api_token,
  );
  const name = nonEmptyString(
    profile?.name ?? profile?.display_name ?? profile?.username,
  );

  if (!session || !profile || !apiToken || !name) {
    throw new Error('LOGIN_SESSION_INVALID');
  }
  const sessionProvider = nonEmptyString(profile.social_provider);

  return {
    ...session,
    api_token: apiToken,
    user: {
      ...profile,
      name,
      email: nonEmptyString(profile?.email) || null,
      social_provider: isSocialProvider(sessionProvider)
        ? sessionProvider
        : provider,
    },
  };
};

export const getSocialAuthMethods = async (): Promise<SocialAuthMethods> => {
  const methodsResponse = await publicRequest.get<unknown>('auth-methods');
  const envelope = asRecord(methodsResponse.data) ?? {};
  const methods = asRecord(envelope.data) ?? envelope;
  const configuredProviders = Array.isArray(methods.providers)
    ? methods.providers.map(String).filter(isSocialProvider)
    : ([] as SocialProvider[]);
  const requestedRecommendation = nonEmptyString(
    methods.recommended_provider ?? methods.recommendedProvider,
  );
  const recommendedProvider =
    isSocialProvider(requestedRecommendation) &&
    configuredProviders.includes(requestedRecommendation)
      ? requestedRecommendation
      : configuredProviders[0] ?? null;
  const recommendationText = nonEmptyString(
    methods.recommendation_badge ?? methods.recommendation_text,
  );
  return {
    providers: configuredProviders,
    authorizationUrls: authorizationUrls(methods.authorization_urls),
    welcomeBonus:
      Number.isFinite(Number(methods.welcome_bonus_coins)) &&
      Number(methods.welcome_bonus_coins) > 0
        ? Number(methods.welcome_bonus_coins)
        : null,
    recommendedProvider,
    recommendationText: recommendationText || null,
  };
};

export const signInWithSocialProvider = async (
  provider: SocialProvider,
  preloadedMethods?: SocialAuthMethods,
) => {
  const methods = preloadedMethods ?? (await getSocialAuthMethods());
  if (!methods.providers.includes(provider)) {
    throw new Error('PROVIDER_NOT_CONFIGURED');
  }

  if (provider === 'apple') {
    if (
      Platform.OS !== 'ios' ||
      !(await AppleAuthentication.isAvailableAsync())
    ) {
      throw new Error('PROVIDER_NOT_CONFIGURED');
    }

    try {
      // expo-apple-authentication forwards this value unchanged to Apple's
      // native request. Apple signs the digest into the ID token; the server
      // receives the unpredictable preimage and verifies that binding.
      const nonce = await createAppleNonce();
      const credential = await AppleAuthentication.signInAsync({
        requestedScopes: [
          AppleAuthentication.AppleAuthenticationScope.FULL_NAME,
          AppleAuthentication.AppleAuthenticationScope.EMAIL,
        ],
        nonce: nonce.digest,
      });
      if (!credential.identityToken) {
        throw new Error('LOGIN_SESSION_INVALID');
      }
      const providerName = [
        credential.fullName?.givenName,
        credential.fullName?.familyName,
      ]
        .filter(Boolean)
        .join(' ')
        .trim();
      const response = await publicRequest.post('social-login', {
        provider,
        token: credential.identityToken,
        nonce: nonce.raw,
        provider_name: providerName || undefined,
        device_os: Platform.OS,
        device_type: Platform.OS,
      });
      return normalizeSocialSession(response?.data, provider);
    } catch (error: unknown) {
      if (asRecord(error)?.code === 'ERR_REQUEST_CANCELED') {
        throw new Error('LOGIN_CANCELLED');
      }
      throw error;
    }
  }

  const startUrl = resolveSocialAuthStartUrl(
    methods.authorizationUrls?.[provider],
    mainUrl,
    provider,
  );
  const returnUrl = 'rokn://auth';
  const pkce = await createPkcePair();
  const separator = startUrl.includes('?') ? '&' : '?';
  const result = await WebBrowser.openAuthSessionAsync(
    `${startUrl}${separator}${new URLSearchParams({
      return_to: returnUrl,
      code_challenge: pkce.challenge,
      code_challenge_method: 'S256',
    }).toString()}`,
    returnUrl,
    {showInRecents: true},
  );

  if (result.type === 'cancel' || result.type === 'dismiss') {
    throw new Error('LOGIN_CANCELLED');
  }
  if (result.type !== 'success') {
    throw new Error('LOGIN_UNAVAILABLE');
  }
  const returnedError = queryValue(result.url, 'error');
  if (returnedError) {
    throw new Error(returnedError.toUpperCase());
  }
  const code = queryValue(result.url, 'code');
  if (!code) {
    throw new Error('LOGIN_CODE_MISSING');
  }

  const response = await publicRequest.post('social-auth/complete', {
    code,
    code_verifier: pkce.verifier,
    // The backend stores this in an android|ios enum. Keep the OS version out
    // of that field so an otherwise successful OAuth exchange cannot fail.
    device_os: Platform.OS,
    device_type: Platform.OS,
  });
  return normalizeSocialSession(response?.data, provider);
};
