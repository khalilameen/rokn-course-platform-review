import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';

const USER_DATA_KEY = 'USER_DATA';
const LEGACY_REDUX_AUTH_KEY = 'persist:auth';
const MIGRATION_KEY = '@rokn/auth-secure-migration-v1';
const MIGRATION_VERSION = '2';
const SECURE_TOKEN_KEY = 'rokn.auth.api-token.v1';
const SECURE_OPTIONS: SecureStore.SecureStoreOptions = {
  keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
};

const SENSITIVE_SESSION_KEYS = new Set([
  'api_token',
  'access_token',
  'refresh_token',
  'id_token',
  'auth_token',
  'bearer_token',
  'authorization',
  'password',
  'secret',
]);

const isSensitiveSessionKey = (key: string) => {
  const lowerKey = key.toLowerCase();
  if (SENSITIVE_SESSION_KEYS.has(lowerKey)) return true;

  // OAuth providers and backend versions do not all use the same casing or
  // envelope names (for example `providerToken`, `oauth_token`, `jwt` or
  // `client_secret`). Keep profile data compatible, but never let an unknown
  // credential-shaped field fall back to plaintext AsyncStorage.
  const canonicalKey = lowerKey.replace(/[^a-z0-9]/g, '');
  return (
    canonicalKey === 'token' ||
    canonicalKey === 'jwt' ||
    canonicalKey.endsWith('token') ||
    canonicalKey.endsWith('secret') ||
    canonicalKey.includes('password') ||
    canonicalKey.endsWith('credential') ||
    canonicalKey.endsWith('credentials')
  );
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const parseJson = (value: string | null): unknown => {
  if (value === null) return null;
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
};

const nestedValue = (value: unknown, path: string[]): unknown => {
  let current = value;
  for (const key of path) {
    if (!isRecord(current)) return undefined;
    current = current[key];
  }
  return current;
};

export type SessionProfile = {
  [key: string]: unknown;
  id?: string | number;
  user_id?: string | number;
  social_id?: string | number;
  email?: string;
  social_provider?: string;
  review?: boolean;
  name?: string;
  job_title?: string;
  portfolio_slug?: string;
  username?: string;
  avatar?: string;
  profile_image?: string;
  wallet_purchased_coins?: string | number;
};

/**
 * Authentication responses have existed in a few compatible envelopes over
 * the lifetime of the app. Keep these public contracts stable while changing
 * only where the credential is persisted.
 */
export const extractApiToken = (value: unknown): string | null => {
  const candidates = [
    nestedValue(value, ['api_token']),
    nestedValue(value, ['data', 'api_token']),
    nestedValue(value, ['data', 'data', 'api_token']),
    nestedValue(value, ['user', 'api_token']),
    nestedValue(value, ['data', 'user', 'api_token']),
  ];
  const token = candidates.find(
    (candidate): candidate is string =>
      typeof candidate === 'string' && candidate.trim().length > 0,
  );
  return token ? token.trim() : null;
};

export const extractUserProfile = (value: unknown): SessionProfile => {
  const candidates = [
    nestedValue(value, ['user']),
    nestedValue(value, ['data', 'user']),
    nestedValue(value, ['data', 'data', 'user']),
    nestedValue(value, ['data']),
    value,
  ];
  return (candidates.find(isRecord) as SessionProfile | undefined) ?? {};
};

/** Remove credentials recursively without changing the compatible envelope. */
export const sanitizeSessionForStorage = (value: unknown): unknown => {
  if (Array.isArray(value)) {
    return value.map(sanitizeSessionForStorage);
  }
  if (!isRecord(value)) return value;

  return Object.fromEntries(
    Object.entries(value)
      .filter(([key]) => !isSensitiveSessionKey(key))
      .map(([key, childValue]) => [key, sanitizeSessionForStorage(childValue)]),
  );
};

const persistedReduxSession = (rawPersistedAuth: string | null) => {
  const persistedAuth = parseJson(rawPersistedAuth);
  const encodedUser = isRecord(persistedAuth)
    ? persistedAuth.userData
    : undefined;
  if (typeof encodedUser === 'string') return parseJson(encodedUser);
  return encodedUser ?? null;
};

const attachTokenInMemory = (
  storedProfile: unknown,
  apiToken: string | null,
) => {
  if (!apiToken) return storedProfile;
  if (!isRecord(storedProfile)) {
    return {api_token: apiToken};
  }
  return {...storedProfile, api_token: apiToken};
};

let migrationPromise: Promise<void> | null = null;
let cachedSession: unknown = null;
let sessionCacheReady = false;
let sessionLoadPromise: Promise<unknown> | null = null;
let sessionCacheEpoch = 0;

const performLegacyMigration = async () => {
  const [rawUserData, rawPersistedAuth, migrationVersion, secureToken] =
    await Promise.all([
      AsyncStorage.getItem(USER_DATA_KEY),
      AsyncStorage.getItem(LEGACY_REDUX_AUTH_KEY),
      AsyncStorage.getItem(MIGRATION_KEY),
      SecureStore.getItemAsync(SECURE_TOKEN_KEY, SECURE_OPTIONS),
    ]);

  const asyncSession = parseJson(rawUserData);
  const reduxSession = persistedReduxSession(rawPersistedAuth);
  const legacyToken =
    extractApiToken(asyncSession) ?? extractApiToken(reduxSession);
  const tokenToKeep = legacyToken ?? secureToken;
  const sessionToKeep = rawUserData !== null ? asyncSession : reduxSession;
  const hasLegacyCredential = Boolean(legacyToken);
  const hasReduxDuplicate = rawPersistedAuth !== null;
  const needsMigration =
    migrationVersion !== MIGRATION_VERSION ||
    hasLegacyCredential ||
    hasReduxDuplicate;

  if (!needsMigration) return;

  // Never erase a plaintext credential until its secure copy has succeeded.
  if (tokenToKeep && tokenToKeep !== secureToken) {
    await SecureStore.setItemAsync(
      SECURE_TOKEN_KEY,
      tokenToKeep,
      SECURE_OPTIONS,
    );
  }

  if (sessionToKeep !== null) {
    await AsyncStorage.setItem(
      USER_DATA_KEY,
      JSON.stringify(sanitizeSessionForStorage(sessionToKeep)),
    );
  }
  await AsyncStorage.removeItem(LEGACY_REDUX_AUTH_KEY);
  await AsyncStorage.setItem(MIGRATION_KEY, MIGRATION_VERSION);
};

/**
 * One-time, retry-safe migration from both old plaintext session copies.
 * A failed secure write leaves the plaintext source untouched for the next
 * launch, so an OS/keychain hiccup cannot silently sign the learner out.
 */
export const migrateLegacySession = async () => {
  if (!migrationPromise) {
    migrationPromise = performLegacyMigration().catch(error => {
      migrationPromise = null;
      throw error;
    });
  }
  await migrationPromise;
};

export const saveSecureSession = async (session: unknown) => {
  const apiToken = extractApiToken(session);
  const sanitized = sanitizeSessionForStorage(session);
  const previousToken = await SecureStore.getItemAsync(
    SECURE_TOKEN_KEY,
    SECURE_OPTIONS,
  );
  const tokenChanged = Boolean(apiToken && apiToken !== previousToken);

  if (tokenChanged && apiToken) {
    await SecureStore.setItemAsync(SECURE_TOKEN_KEY, apiToken, SECURE_OPTIONS);
  }

  try {
    await AsyncStorage.setItem(USER_DATA_KEY, JSON.stringify(sanitized));
    await AsyncStorage.removeItem(LEGACY_REDUX_AUTH_KEY);
    await AsyncStorage.setItem(MIGRATION_KEY, MIGRATION_VERSION);
    migrationPromise = Promise.resolve();
    sessionCacheEpoch += 1;
    cachedSession = attachTokenInMemory(sanitized, apiToken);
    sessionCacheReady = true;
    sessionLoadPromise = null;
  } catch (error) {
    // Avoid pairing a newly written token with another account's old profile.
    if (tokenChanged) {
      if (previousToken) {
        await SecureStore.setItemAsync(
          SECURE_TOKEN_KEY,
          previousToken,
          SECURE_OPTIONS,
        );
      } else {
        await SecureStore.deleteItemAsync(SECURE_TOKEN_KEY, SECURE_OPTIONS);
      }
    }
    throw error;
  }
};

export const loadSecureSession = async () => {
  if (sessionCacheReady) {
    return cachedSession;
  }
  if (sessionLoadPromise) {
    return sessionLoadPromise;
  }

  const loadEpoch = sessionCacheEpoch;
  sessionLoadPromise = (async () => {
    await migrateLegacySession();
    const [rawProfile, apiToken] = await Promise.all([
      AsyncStorage.getItem(USER_DATA_KEY),
      SecureStore.getItemAsync(SECURE_TOKEN_KEY, SECURE_OPTIONS),
    ]);

    let restoredSession: unknown = null;
    if (rawProfile === null) {
      // A secure token without its profile can only be a partially completed
      // logout. Remove it instead of resurrecting an ownerless session.
      if (apiToken) {
        await SecureStore.deleteItemAsync(SECURE_TOKEN_KEY, SECURE_OPTIONS);
      }
    } else {
      restoredSession = attachTokenInMemory(parseJson(rawProfile), apiToken);
    }

    // A logout or account switch may finish while the keychain read is in
    // flight. Never let that older read resurrect the previous account.
    if (loadEpoch !== sessionCacheEpoch) {
      return sessionCacheReady ? cachedSession : null;
    }
    cachedSession = restoredSession;
    sessionCacheReady = true;
    return restoredSession;
  })().finally(() => {
    sessionLoadPromise = null;
  });

  return sessionLoadPromise;
};

/** Single source of truth for cold-start Redux hydration. */
export const restoreSecureAuthState = async () => {
  const session = await loadSecureSession();
  return {
    session,
    isAuthenticated: Boolean(extractApiToken(session)),
  };
};

export const deleteSecureSession = async () => {
  sessionCacheEpoch += 1;
  cachedSession = null;
  sessionCacheReady = true;
  sessionLoadPromise = null;
  const results = await Promise.allSettled([
    SecureStore.deleteItemAsync(SECURE_TOKEN_KEY, SECURE_OPTIONS),
    AsyncStorage.removeItem(USER_DATA_KEY),
    AsyncStorage.removeItem(LEGACY_REDUX_AUTH_KEY),
  ]);
  migrationPromise = null;
  return results.every(result => result.status === 'fulfilled');
};

export const clearSecureSessionStorage = async () => {
  sessionCacheEpoch += 1;
  cachedSession = null;
  sessionCacheReady = true;
  sessionLoadPromise = null;
  await SecureStore.deleteItemAsync(SECURE_TOKEN_KEY, SECURE_OPTIONS);
  await AsyncStorage.clear();
  migrationPromise = null;
};

/** Test isolation for the module-level migration de-duplication promise. */
export const resetSecureSessionMigrationForTests = () => {
  migrationPromise = null;
  cachedSession = null;
  sessionCacheReady = false;
  sessionLoadPromise = null;
  sessionCacheEpoch = 0;
};
