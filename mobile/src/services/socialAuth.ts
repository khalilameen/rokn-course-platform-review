import {Platform} from 'react-native';
import * as AppleAuthentication from 'expo-apple-authentication';
import * as Crypto from 'expo-crypto';
import * as WebBrowser from 'expo-web-browser';
import {publicRequest} from '../constants/api';
import {sha256Base64Url, sha256Hex} from '../utils/sha256';
import {serverNow, serverNowMs} from '../utils/serverClock';
import {openAndroidAuthSession} from './androidAuthSession';
import {getInstallationId} from './installationIdentity';
import {savePendingWelcomeBonus} from './pendingWelcomeBonus';
import {roknApiUrl} from '../constants/apiBaseUrl';
import {
  deletePendingSocialAuthAttempt,
  loadPendingSocialAuthAttempt,
  saveSecureSession,
  savePendingSocialAuthAttempt,
} from './secureSession';

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
    try {
      if (decodeURIComponent(rawKey || '') === key) {
        return decodeURIComponent(rawValue.join('=') || '');
      }
    } catch {
      return '';
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

const absoluteHttpUrl = (value: string) => {
  const match = value
    .trim()
    .match(/^(https?):\/\/([^/?#]+)(\/[^?#]*)$/i);
  if (!match || match[2].includes('@')) return null;
  return {
    protocol: match[1].toLowerCase(),
    authority: match[2].toLowerCase(),
    origin: `${match[1].toLowerCase()}://${match[2].toLowerCase()}`,
    path: match[3].replace(/\/$/, ''),
  };
};

const safeAuthorizationUrl = (provider: SocialProvider, value: string) => {
  // Do not depend on the browser URL implementation here. Some Hermes builds
  // expose an incomplete constructor and used to hide every valid provider at
  // runtime even though auth-methods had loaded correctly.
  const activeApi = absoluteHttpUrl(roknApiUrl);
  const candidate = absoluteHttpUrl(value);
  if (!activeApi || !candidate) return false;
  const trustedProductionAuthority = [
    'rokn.app',
    'www.rokn.app',
    'rokn-course-platform-review-production-b7gpy1.laravel.cloud',
  ].includes(candidate.authority);
  const sameActiveOrigin = candidate.origin === activeApi.origin;
  const expectedPath = `${activeApi.path}/social-auth/${provider}/start`;
  const versionedPath = `/api/v1/social-auth/${provider}/start`;
  return (
    (sameActiveOrigin ||
      (candidate.protocol === 'https' && trustedProductionAuthority)) &&
    [expectedPath, versionedPath].includes(candidate.path)
  );
};

const authorizationUrls = (
  value: unknown,
): Partial<Record<SocialProvider, string>> => {
  const record = asRecord(value);
  if (!record) return {};
  return Object.fromEntries(
    Object.entries(record).filter(
      ([provider, url]) => {
        if (!isSocialProvider(provider) || typeof url !== 'string') {
          return false;
        }
        return safeAuthorizationUrl(provider, url);
      },
    ),
  ) as Partial<Record<SocialProvider, string>>;
};

const createPkcePair = async () => {
  const verifier = `${Crypto.randomUUID()}${Crypto.randomUUID()}`.replace(
    /-/g,
    '',
  );
  return {
    verifier,
    challenge: sha256Base64Url(verifier),
  };
};

const encodeQuery = (values: Record<string, string>) =>
  Object.entries(values)
    .map(
      ([key, value]) =>
        `${encodeURIComponent(key)}=${encodeURIComponent(value)}`,
    )
    .join('&');

const createAppleNonce = async () => {
  const bytes = await Crypto.getRandomBytesAsync(32);
  const raw = Array.from(bytes, byte =>
    byte.toString(16).padStart(2, '0'),
  ).join('');
  const digest = sha256Hex(raw);

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
  const accountId = profile?.id ?? profile?.user_id;

  if (
    !session ||
    !profile ||
    !apiToken ||
    !name ||
    accountId === null ||
    accountId === undefined ||
    String(accountId).trim() === ''
  ) {
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

const isAuthCallbackUrl = (value: string) =>
  value === 'rokn://auth' || value.startsWith('rokn://auth?');

type SocialAuthPurpose = 'login' | 'reauth';

type SocialAuthOptions = {
  purpose?: SocialAuthPurpose;
};

const responseStatus = (error: unknown) => {
  const root = asRecord(error);
  const response = asRecord(root?.response) ?? root;
  return Number(response?.status || 0);
};

const responseCode = (error: unknown) => {
  const root = asRecord(error);
  const response = asRecord(root?.response) ?? root;
  const data = asRecord(response?.data);
  return nonEmptyString(data?.code).toUpperCase();
};

const completionIsTerminal = (error: unknown) => {
  const status = responseStatus(error);
  return (
    status >= 400 &&
    status < 500 &&
    status !== 429 &&
    !(status === 409 && responseCode(error) === 'SOCIAL_LOGIN_IN_PROGRESS')
  );
};

const completeSocialAttempt = async (
  code: string,
  verifier: string,
) => {
  const installationId = await getInstallationId();
  const body = {
    code,
    code_verifier: verifier,
    device_os: Platform.OS,
    device_type: Platform.OS,
    ...(installationId ? {device_id: installationId} : {}),
  };
  const retryDelays = [0, 700, 1500, 3000];
  for (const retryDelay of retryDelays) {
    if (retryDelay) {
      await new Promise(resolve => setTimeout(resolve, retryDelay));
    }
    try {
      return await publicRequest.post('social-auth/complete', body, {
        // Login completion is a small idempotent exchange. Bound each attempt
        // independently so one dead socket cannot hold the branded launch
        // screen for the global request timeout across every retry.
        timeout: 10_000,
      });
    } catch (error) {
      const status = responseStatus(error);
      const retryable =
        status === 0 ||
        status === 408 ||
        status === 429 ||
        status >= 500 ||
        (status === 409 && responseCode(error) === 'SOCIAL_LOGIN_IN_PROGRESS');
      if (
        !retryable ||
        retryDelay === retryDelays[retryDelays.length - 1]
      ) {
        throw error;
      }
    }
  }
  throw new Error('LOGIN_UNAVAILABLE');
};

const persistCompletedLogin = async (
  pending: Awaited<ReturnType<typeof loadPendingSocialAuthAttempt>>,
  session: SocialAuthSession,
) => {
  if (!pending) throw new Error('LOGIN_SESSION_INVALID');
  const current = await loadPendingSocialAuthAttempt();
  const stillOwnsAttempt = Boolean(
    current &&
      current.provider === pending.provider &&
      current.verifier === pending.verifier &&
      current.startedAt === pending.startedAt &&
      (current.purpose ?? 'login') === (pending.purpose ?? 'login'),
  );
  if (!stillOwnsAttempt) return false;
  // The provider code is one-time but the hand-off to local secure storage is
  // not atomic. Persist the completed response in the encrypted attempt first;
  // a process death after backend completion can then finish locally instead
  // of replaying a consumed code or reporting a false login failure.
  await savePendingSocialAuthAttempt({
    ...current!,
    completedSession: session,
  });
  await saveSecureSession(session);
  await savePendingWelcomeBonus(session.welcome_bonus_granted);
  // Keep the encrypted recovery copy until the normal token/profile pair and
  // welcome receipt are durable, then retire only this exact attempt.
  const afterWrite = await loadPendingSocialAuthAttempt().catch(() => null);
  if (
    afterWrite &&
    afterWrite.provider === pending.provider &&
    afterWrite.verifier === pending.verifier &&
    afterWrite.startedAt === pending.startedAt &&
    (afterWrite.purpose ?? 'login') === (pending.purpose ?? 'login')
  ) {
    await deletePendingSocialAuthAttempt().catch(() => undefined);
  }
  return true;
};

export const resumePendingSocialAuth = async (
  callbackUrl?: string | null,
  options: SocialAuthOptions = {},
): Promise<SocialAuthSession | null> => {
  const pending = await loadPendingSocialAuthAttempt();
  if (!pending) return null;

  const purpose = options.purpose ?? 'login';
  const pendingPurpose = pending.purpose ?? 'login';
  if (purpose !== pendingPurpose) {
    // A destructive reauthentication is meaningful only to the live action
    // that requested it. A foreground bootstrap must neither turn it into an
    // account switch nor delete it while that live action is still resuming.
    return null;
  }

  const startedAt = Date.parse(pending.startedAt);
  const elapsed = serverNowMs() - startedAt;
  if (!Number.isFinite(startedAt) || elapsed < -60_000 || elapsed > 10 * 60 * 1000) {
    await deletePendingSocialAuthAttempt();
    return null;
  }

  if (pending.completedSession) {
    const completed = normalizeSocialSession(
      pending.completedSession,
      pending.provider,
    );
    if (purpose === 'login') {
      if (!(await persistCompletedLogin(pending, completed))) return null;
    } else {
      await deletePendingSocialAuthAttempt();
    }
    return completed;
  }

  const returnedUrl =
    callbackUrl && isAuthCallbackUrl(callbackUrl)
      ? callbackUrl
      : pending.callbackUrl;
  if (!returnedUrl || !isAuthCallbackUrl(returnedUrl)) return null;

  if (pending.callbackUrl !== returnedUrl) {
    await savePendingSocialAuthAttempt({...pending, callbackUrl: returnedUrl});
  }
  const returnedError = queryValue(returnedUrl, 'error');
  if (returnedError) {
    await deletePendingSocialAuthAttempt();
    if (
      [
        'access_denied',
        'user_cancelled',
        'login_cancelled',
        'cancelled',
      ].includes(returnedError)
    ) {
      throw new Error('LOGIN_CANCELLED');
    }
    if (returnedError === 'provider_unavailable') {
      throw new Error('LOGIN_UNAVAILABLE');
    }
    throw new Error('LOGIN_FAILED');
  }
  const code = queryValue(returnedUrl, 'code');
  if (!code) {
    await deletePendingSocialAuthAttempt();
    throw new Error('LOGIN_CODE_MISSING');
  }

  let response;
  try {
    response = await completeSocialAttempt(code, pending.verifier);
  } catch (error) {
    if (completionIsTerminal(error)) {
      await deletePendingSocialAuthAttempt();
    }
    throw error;
  }
  const session = normalizeSocialSession(response?.data, pending.provider);
  if (purpose === 'login') {
    // Stage the complete response in encrypted storage before moving it to the
    // normal session keys. A process death between the two writes can then
    // finish locally without replaying a server code that was already used.
    if (
      !(await persistCompletedLogin(
        {...pending, callbackUrl: returnedUrl},
        session,
      ))
    ) {
      return null;
    }
  } else {
    await deletePendingSocialAuthAttempt();
  }
  return session;
};

export const getSocialAuthMethods = async (): Promise<SocialAuthMethods> => {
  const methodsResponse = await publicRequest.get<unknown>('auth-methods');
  const envelope = asRecord(methodsResponse.data) ?? {};
  const methods = asRecord(envelope.data) ?? envelope;
  const urls = authorizationUrls(methods.authorization_urls);
  const configuredProviders = Array.isArray(methods.providers)
    ? Array.from(
        new Set(
          methods.providers
            .map(String)
            .filter(isSocialProvider)
            .filter(provider => provider === 'apple' || Boolean(urls[provider])),
        ),
      )
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
    authorizationUrls: urls,
    welcomeBonus:
      Number.isSafeInteger(Number(methods.welcome_bonus_coins)) &&
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
  options: SocialAuthOptions = {},
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
      const installationId = await getInstallationId();
      const response = await publicRequest.post('social-login', {
        provider,
        token: credential.identityToken,
        nonce: nonce.raw,
        provider_name: providerName || undefined,
        device_os: Platform.OS,
        device_type: Platform.OS,
        ...(installationId ? {device_id: installationId} : {}),
      });
      const session = normalizeSocialSession(response?.data, provider);
      if ((options.purpose ?? 'login') === 'login') {
        await saveSecureSession(session);
        await savePendingWelcomeBonus(session.welcome_bonus_granted);
      }
      return session;
    } catch (error: unknown) {
      if (asRecord(error)?.code === 'ERR_REQUEST_CANCELED') {
        throw new Error('LOGIN_CANCELLED');
      }
      throw error;
    }
  }

  // The backend owns the canonical provider start route for the active
  // environment. Using its advertised URL avoids mixing a production methods
  // response with a stale API base bundled into an older APK.
  const startUrl = methods.authorizationUrls[provider];
  if (!startUrl || !safeAuthorizationUrl(provider, startUrl)) {
    throw new Error('PROVIDER_NOT_CONFIGURED');
  }
  const returnUrl = 'rokn://auth';
  let pkce: Awaited<ReturnType<typeof createPkcePair>>;
  try {
    pkce = await createPkcePair();
  } catch {
    throw new Error('LOGIN_SECURE_FLOW_UNAVAILABLE');
  }
  const separator = startUrl.includes('?') ? '&' : '?';
  const authorizationUrl = `${startUrl}${separator}${encodeQuery({
    return_to: returnUrl,
    code_challenge: pkce.challenge,
    code_challenge_method: 'S256',
  })}`;
  await savePendingSocialAuthAttempt({
    provider,
    verifier: pkce.verifier,
    startedAt: serverNow().toISOString(),
    purpose: options.purpose ?? 'login',
  });
  const result =
    Platform.OS === 'android'
      ? await openAndroidAuthSession(authorizationUrl, returnUrl)
      : await WebBrowser.openAuthSessionAsync(authorizationUrl, returnUrl);

  if (result.type === 'cancel' || result.type === 'dismiss') {
    await deletePendingSocialAuthAttempt();
    throw new Error('LOGIN_CANCELLED');
  }
  if (result.type !== 'success') {
    await deletePendingSocialAuthAttempt();
    throw new Error('LOGIN_UNAVAILABLE');
  }
  return resumePendingSocialAuth(result.url, options).then(session => {
    if (!session) throw new Error('LOGIN_SESSION_INVALID');
    return session;
  });
};
