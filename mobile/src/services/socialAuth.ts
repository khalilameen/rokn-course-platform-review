import {Platform} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as AppleAuthentication from 'expo-apple-authentication';
import * as Crypto from 'expo-crypto';
import * as WebBrowser from 'expo-web-browser';
import {publicRequest, type RoknRequestConfig} from '../constants/api';
import {sha256Base64Url, sha256Hex} from '../utils/sha256';
import {serverNow, serverNowMs} from '../utils/serverClock';
import {openAndroidAuthSession} from './androidAuthSession';
import {getInstallationId} from './installationIdentity';
import {savePendingWelcomeBonus} from './pendingWelcomeBonus';
import {
  hasNativeSocialCapability,
  signInWithNativeSocialProvider,
  type NativeSocialProvider,
} from './nativeSocialAuth';
import {roknApiUrl} from '../constants/apiBaseUrl';
import {
  resolveSocialAuthStartUrl,
  type BrowserSocialProvider,
} from './socialAuthUrlPolicy';
import {
  deletePendingSocialAuthAttempt,
  extractApiToken,
  loadSecureSession,
  loadPendingSocialAuthAttempt,
  replacePendingSocialAuthAttempt,
  saveSecureSession,
  savePendingSocialAuthAttempt,
} from './secureSession';
import type {PendingSocialAuthAttempt} from './secureSession';

export type SocialProvider = 'google' | 'tiktok' | 'facebook' | 'apple';

export type SocialAuthMethods = {
  providers: SocialProvider[];
  authorizationUrls: Partial<Record<SocialProvider, string>>;
  authorizationApiUrl: string;
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

const SOCIAL_AUTH_METHODS_CACHE_KEY = '@rokn/social-auth-methods/v1';
const SOCIAL_AUTH_METHODS_CACHE_TTL_MS = 24 * 60 * 60 * 1000;

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

const safeAuthorizationUrl = (
  provider: SocialProvider,
  value: unknown,
  advertisedApiUrl?: unknown,
) =>
  provider === 'apple'
    ? ''
    : resolveSocialAuthStartUrl(
        value,
        roknApiUrl,
        provider as BrowserSocialProvider,
        advertisedApiUrl,
      );

const authorizationUrls = (
  value: unknown,
  advertisedApiUrl?: unknown,
): Partial<Record<SocialProvider, string>> => {
  const record = asRecord(value);
  if (!record) return {};
  return Object.fromEntries(
    Object.entries(record).flatMap(([provider, url]) => {
      if (
        !isSocialProvider(provider) ||
        provider === 'apple' ||
        typeof url !== 'string'
      ) {
        return [];
      }
      let resolved = '';
      try {
        resolved = safeAuthorizationUrl(provider, url, advertisedApiUrl);
      } catch {
        return [];
      }
      return resolved ? [[provider, resolved]] : [];
    }),
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

const createNativeAttempt = async (
  provider: NativeSocialProvider,
  purpose: SocialAuthPurpose,
) => {
  const verifier = `${Crypto.randomUUID()}${Crypto.randomUUID()}`.replace(
    /-/g,
    '',
  );
  const attempt = {
    provider,
    verifier,
    flow: 'native' as const,
    startedAt: serverNow().toISOString(),
    purpose,
  };
  await savePendingSocialAuthAttempt(attempt);
  return attempt;
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

const completeSocialAttempt = async (code: string, verifier: string) => {
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
        skipAuthorization: true,
      } as RoknRequestConfig);
    } catch (error) {
      const status = responseStatus(error);
      const retryable =
        status === 0 ||
        status === 408 ||
        status === 429 ||
        status >= 500 ||
        (status === 409 && responseCode(error) === 'SOCIAL_LOGIN_IN_PROGRESS');
      if (!retryable || retryDelay === retryDelays[retryDelays.length - 1]) {
        throw error;
      }
    }
  }
  throw new Error('LOGIN_UNAVAILABLE');
};

const exchangeNativeSocialToken = async (
  provider: NativeSocialProvider,
  token: string,
  pending: Awaited<ReturnType<typeof createNativeAttempt>>,
  options: SocialAuthOptions,
) => {
  const installationId = await getInstallationId();
  const response = await publicRequest.post(
    'social-login',
    {
      provider,
      token,
      device_os: Platform.OS,
      device_type: Platform.OS,
      ...(installationId ? {device_id: installationId} : {}),
    },
    {skipAuthorization: true} as RoknRequestConfig,
  );
  const session = normalizeSocialSession(response?.data, provider);
  if ((options.purpose ?? 'login') === 'login') {
    if (!(await persistCompletedLogin(pending, session))) {
      throw new Error('LOGIN_SESSION_INVALID');
    }
  } else {
    await deletePendingSocialAuthAttempt(pending);
  }
  return session;
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
  if (!stillOwnsAttempt) {
    // AppState foreground recovery and the Login screen can observe the same
    // Android callback. If the sibling path already committed this exact
    // replayable backend session, treat it as the same successful login rather
    // than reporting that storage failed after the account is already active.
    const committed = await loadSecureSession().catch(() => null);
    return extractApiToken(committed) === session.api_token;
  }
  // The provider code is one-time but the hand-off to local secure storage is
  // not atomic. Persist the completed response in the encrypted attempt first;
  // a process death after backend completion can then finish locally instead
  // of replaying a consumed code or reporting a false login failure.
  const staged = await replacePendingSocialAuthAttempt(current!, {
    ...current!,
    completedSession: session,
  });
  if (!staged) {
    const committed = await loadSecureSession().catch(() => null);
    return extractApiToken(committed) === session.api_token;
  }
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
    await deletePendingSocialAuthAttempt(afterWrite).catch(() => undefined);
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

  if (pending.completedSession) {
    const completedAt = Date.parse(pending.startedAt);
    const completedAge = serverNowMs() - completedAt;
    if (
      !Number.isFinite(completedAt) ||
      completedAge < -60_000 ||
      completedAge > 24 * 60 * 60 * 1000
    ) {
      await deletePendingSocialAuthAttempt(pending);
      return null;
    }
    const completed = normalizeSocialSession(
      pending.completedSession,
      pending.provider,
    );
    if (purpose === 'login') {
      if (!(await persistCompletedLogin(pending, completed))) return null;
    } else {
      await deletePendingSocialAuthAttempt(pending);
    }
    return completed;
  }

  const startedAt = Date.parse(pending.startedAt);
  const elapsed = serverNowMs() - startedAt;
  if (
    !Number.isFinite(startedAt) ||
    elapsed < -60_000 ||
    elapsed > 10 * 60 * 1000
  ) {
    await deletePendingSocialAuthAttempt(pending);
    return null;
  }

  const returnedUrl =
    callbackUrl && isAuthCallbackUrl(callbackUrl)
      ? callbackUrl
      : pending.callbackUrl;
  if (!returnedUrl || !isAuthCallbackUrl(returnedUrl)) return null;

  const returnedAttempt = queryValue(returnedUrl, 'attempt');
  if (
    pending.flow === 'native' ||
    (pending.flow === 'browser' &&
      (!pending.challenge || returnedAttempt !== pending.challenge)) ||
    (pending.flow === undefined &&
      pending.challenge &&
      returnedAttempt !== pending.challenge)
  ) {
    // Android can deliver the callback from an older browser attempt after a
    // retry has already installed a new verifier. PKCE rejects that old code,
    // but it must not delete the newer attempt which still owns this device.
    return null;
  }

  if (pending.callbackUrl !== returnedUrl) {
    const updated = await replacePendingSocialAuthAttempt(pending, {
      ...pending,
      callbackUrl: returnedUrl,
    });
    if (!updated) return null;
  }
  const returnedError = queryValue(returnedUrl, 'error');
  if (returnedError) {
    if (!(await deletePendingSocialAuthAttempt(pending))) return null;
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
    if (!(await deletePendingSocialAuthAttempt(pending))) return null;
    throw new Error('LOGIN_CODE_MISSING');
  }

  let response;
  try {
    response = await completeSocialAttempt(code, pending.verifier);
  } catch (error) {
    if (completionIsTerminal(error)) {
      await deletePendingSocialAuthAttempt(pending);
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
    await deletePendingSocialAuthAttempt(pending);
  }
  return session;
};

const normalizeSocialAuthMethods = (value: unknown): SocialAuthMethods => {
  const envelope = asRecord(value) ?? {};
  const methods = asRecord(envelope.data) ?? envelope;
  const authorizationApiUrl =
    nonEmptyString(
      methods.authorization_api_url ?? methods.authorizationApiUrl,
    ) || roknApiUrl;
  const urls = authorizationUrls(
    methods.authorization_urls ?? methods.authorizationUrls,
    authorizationApiUrl,
  );
  const declaredProviders = Array.isArray(methods.providers)
    ? Array.from(
        new Set(methods.providers.map(String).filter(isSocialProvider)),
      )
    : ([] as SocialProvider[]);
  const configuredProviders = declaredProviders.filter(
    provider => provider === 'apple' || Boolean(urls[provider]),
  );
  const requestedRecommendation = nonEmptyString(
    methods.recommended_provider ?? methods.recommendedProvider,
  );
  const recommendedProvider =
    isSocialProvider(requestedRecommendation) &&
    configuredProviders.includes(requestedRecommendation)
      ? requestedRecommendation
      : configuredProviders[0] ?? null;
  const recommendationText = nonEmptyString(
    methods.recommendation_badge ??
      methods.recommendation_text ??
      methods.recommendationText,
  );
  const welcomeBonus = Number(
    methods.welcome_bonus_coins ?? methods.welcomeBonus,
  );
  return {
    providers: configuredProviders,
    authorizationUrls: urls,
    authorizationApiUrl,
    welcomeBonus:
      Number.isSafeInteger(welcomeBonus) && welcomeBonus > 0
        ? welcomeBonus
        : null,
    recommendedProvider,
    recommendationText: recommendationText || null,
  };
};

const declaredSocialProviders = (value: unknown): SocialProvider[] => {
  const envelope = asRecord(value) ?? {};
  const methods = asRecord(envelope.data) ?? envelope;
  return Array.isArray(methods.providers)
    ? Array.from(
        new Set(methods.providers.map(String).filter(isSocialProvider)),
      )
    : [];
};

const recoverIncompleteSocialAuthMethods = async (
  value: unknown,
  normalized: SocialAuthMethods,
) => {
  const envelope = asRecord(value) ?? {};
  const methods = asRecord(envelope.data) ?? envelope;
  const declared = declaredSocialProviders(value);
  const rawUrls = asRecord(
    methods.authorization_urls ?? methods.authorizationUrls,
  );
  const missing = declared.filter(
    provider => provider !== 'apple' && !nonEmptyString(rawUrls?.[provider]),
  );
  const explicitlyUnsafe = declared.some(provider => {
    if (provider === 'apple') return false;
    const rawUrl = nonEmptyString(rawUrls?.[provider]);
    return Boolean(rawUrl && !normalized.authorizationUrls[provider]);
  });

  // An explicit empty provider list is authoritative. An explicit but unsafe
  // URL is also never replaced from storage: doing so could conceal a bad
  // deployment or a host-injection attempt. Only a structurally incomplete
  // 200 response may borrow the missing entries from the last known-good
  // discovery contract.
  if (!missing.length || explicitlyUnsafe) return normalized;
  const cached = await readCachedSocialAuthMethods();
  if (!cached) return normalized;

  const recoveredAuthorizationUrls = {...normalized.authorizationUrls};
  missing.forEach(provider => {
    const cachedUrl = cached.authorizationUrls[provider];
    if (cached.providers.includes(provider) && cachedUrl) {
      recoveredAuthorizationUrls[provider] = cachedUrl;
    }
  });
  const providers = declared.filter(
    provider =>
      provider === 'apple' || Boolean(recoveredAuthorizationUrls[provider]),
  );
  const recommendedProvider =
    normalized.recommendedProvider &&
    providers.includes(normalized.recommendedProvider)
      ? normalized.recommendedProvider
      : providers[0] ?? null;

  return {
    ...normalized,
    providers,
    authorizationUrls: recoveredAuthorizationUrls,
    recommendedProvider,
  };
};

const readCachedSocialAuthMethods = async () => {
  try {
    const raw = await AsyncStorage.getItem(SOCIAL_AUTH_METHODS_CACHE_KEY);
    const cached = raw ? asRecord(JSON.parse(raw)) : null;
    const savedAt = Number(cached?.savedAt);
    const age = Date.now() - savedAt;
    if (
      !Number.isFinite(savedAt) ||
      age < -5 * 60 * 1000 ||
      age > SOCIAL_AUTH_METHODS_CACHE_TTL_MS
    ) {
      return null;
    }
    const methods = normalizeSocialAuthMethods(cached?.methods);
    return methods.providers.length ? methods : null;
  } catch {
    return null;
  }
};

export const getSocialAuthMethods = async (): Promise<SocialAuthMethods> => {
  try {
    const methodsResponse = await publicRequest.get<unknown>('auth-methods', {
      skipAuthorization: true,
    } as RoknRequestConfig);
    const methods = await recoverIncompleteSocialAuthMethods(
      methodsResponse.data,
      normalizeSocialAuthMethods(methodsResponse.data),
    );
    if (methods.providers.length) {
      void AsyncStorage.setItem(
        SOCIAL_AUTH_METHODS_CACHE_KEY,
        JSON.stringify({savedAt: Date.now(), methods}),
      ).catch(() => undefined);
    }
    return methods;
  } catch (error) {
    const cached = await readCachedSocialAuthMethods();
    if (cached) return cached;
    throw error;
  }
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

    let ownedAppleAttempt: PendingSocialAuthAttempt | null = null;
    try {
      // expo-apple-authentication forwards this value unchanged to Apple's
      // native request. Apple signs the digest into the ID token; the server
      // receives the unpredictable preimage and verifies that binding.
      const nonce = await createAppleNonce();
      const appleAttempt: PendingSocialAuthAttempt = {
        provider: 'apple' as const,
        verifier: nonce.raw,
        flow: 'native' as const,
        startedAt: new Date(serverNowMs()).toISOString(),
        purpose: options.purpose ?? 'login',
      };
      ownedAppleAttempt = appleAttempt;
      await savePendingSocialAuthAttempt(appleAttempt);
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
      const response = await publicRequest.post(
        'social-login',
        {
          provider,
          token: credential.identityToken,
          nonce: nonce.raw,
          provider_name: providerName || undefined,
          device_os: Platform.OS,
          device_type: Platform.OS,
          ...(installationId ? {device_id: installationId} : {}),
        },
        {skipAuthorization: true} as RoknRequestConfig,
      );
      const session = normalizeSocialSession(response?.data, provider);
      if ((options.purpose ?? 'login') === 'login') {
        if (!(await persistCompletedLogin(appleAttempt, session))) {
          throw new Error('LOGIN_SESSION_INVALID');
        }
      } else {
        await deletePendingSocialAuthAttempt(appleAttempt);
      }
      return session;
    } catch (error: unknown) {
      if (asRecord(error)?.code === 'ERR_REQUEST_CANCELED') {
        if (ownedAppleAttempt) {
          await deletePendingSocialAuthAttempt(ownedAppleAttempt).catch(
            () => undefined,
          );
        }
        throw new Error('LOGIN_CANCELLED');
      }
      throw error;
    }
  }

  if (
    (provider === 'google' || provider === 'facebook') &&
    hasNativeSocialCapability(provider)
  ) {
    const nativeAttempt = await createNativeAttempt(
      provider,
      options.purpose ?? 'login',
    );
    const nativeResult = await signInWithNativeSocialProvider(provider);
    if (nativeResult.type === 'cancel') {
      await deletePendingSocialAuthAttempt(nativeAttempt);
      throw new Error('LOGIN_CANCELLED');
    }
    if (nativeResult.type === 'success') {
      try {
        return await exchangeNativeSocialToken(
          provider,
          nativeResult.token,
          nativeAttempt,
          options,
        );
      } catch (error) {
        const code = responseCode(error);
        if (
          responseStatus(error) !== 422 ||
          code !== 'SOCIAL_IDENTITY_VERIFICATION_FAILED'
        ) {
          throw error;
        }
      }
    }
    // Native configuration drift, an unavailable bridge, or an audience
    // mismatch must not strand the learner. Retire that durable attempt before
    // the normal PKCE path creates its own independently recoverable intent.
    await deletePendingSocialAuthAttempt(nativeAttempt).catch(() => undefined);
  }

  // The backend owns the canonical provider start route for the active
  // environment. Using its advertised URL avoids mixing a production methods
  // response with a stale API base bundled into an older APK.
  const startUrl = methods.authorizationUrls[provider];
  const resolvedStartUrl = startUrl
    ? safeAuthorizationUrl(provider, startUrl, methods.authorizationApiUrl)
    : '';
  if (!resolvedStartUrl) {
    throw new Error('PROVIDER_NOT_CONFIGURED');
  }
  const returnUrl = 'rokn://auth';
  let pkce: Awaited<ReturnType<typeof createPkcePair>>;
  try {
    pkce = await createPkcePair();
  } catch {
    throw new Error('LOGIN_SECURE_FLOW_UNAVAILABLE');
  }
  const separator = resolvedStartUrl.includes('?') ? '&' : '?';
  const authorizationUrl = `${resolvedStartUrl}${separator}${encodeQuery({
    return_to: returnUrl,
    code_challenge: pkce.challenge,
    code_challenge_method: 'S256',
  })}`;
  const browserAttempt = {
    provider,
    verifier: pkce.verifier,
    challenge: pkce.challenge,
    flow: 'browser',
    startedAt: serverNow().toISOString(),
    purpose: options.purpose ?? 'login',
  } as const;
  await savePendingSocialAuthAttempt(browserAttempt);
  const result =
    Platform.OS === 'android'
      ? await openAndroidAuthSession(
          authorizationUrl,
          returnUrl,
          pkce.challenge,
        )
      : await WebBrowser.openAuthSessionAsync(authorizationUrl, returnUrl);

  if (result.type === 'cancel' || result.type === 'dismiss') {
    const recoverableAndroidReturn =
      result.type === 'cancel' &&
      'recoverable' in result &&
      result.recoverable === true;
    if (!recoverableAndroidReturn) {
      await deletePendingSocialAuthAttempt(browserAttempt);
    }
    throw new Error('LOGIN_CANCELLED');
  }
  if (result.type !== 'success') {
    await deletePendingSocialAuthAttempt(browserAttempt);
    throw new Error('LOGIN_UNAVAILABLE');
  }
  return resumePendingSocialAuth(result.url, options).then(session => {
    if (!session) throw new Error('LOGIN_SESSION_INVALID');
    return session;
  });
};
