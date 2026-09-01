import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import {NativeModules, Platform} from 'react-native';

const USER_DATA_KEY = 'USER_DATA';
const LEGACY_REDUX_AUTH_KEY = 'persist:auth';
const MIGRATION_KEY = '@rokn/auth-secure-migration-v1';
const MIGRATION_VERSION = '2';
const SECURE_TOKEN_KEY = 'rokn.auth.api-token.v2';
const LEGACY_SECURE_TOKEN_KEYS = ['rokn.auth.api-token.v1'] as const;
const SECURE_STORAGE_PROBE_KEY = 'rokn.auth.storage-probe.v1';
const PENDING_SOCIAL_AUTH_KEY = 'rokn.auth.pending-social.v1';
export const secureStoreOptionsForPlatform = (
  platform: string,
): SecureStore.SecureStoreOptions =>
  platform === 'ios'
    ? {keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY}
    : {};

// `keychainAccessible` is an iOS-only native constant. Reading and forwarding
// it on Android makes the native options record invalid in release builds.
const SECURE_OPTIONS = secureStoreOptionsForPlatform(Platform.OS);

type AndroidSecureSessionModule = {
  setItem: (key: string, value: string) => Promise<void>;
  getItem: (key: string) => Promise<string | null>;
  deleteItem: (key: string) => Promise<void>;
};

const androidSecureSession = () =>
  NativeModules.RoknSecureSession as AndroidSecureSessionModule | undefined;

const secureStorageAvailable = async () => {
  if (Platform.OS !== 'android') return SecureStore.isAvailableAsync();
  return Boolean(androidSecureSession());
};

const secureSetItem = (key: string, value: string) => {
  if (Platform.OS !== 'android') {
    return SecureStore.setItemAsync(key, value, SECURE_OPTIONS);
  }
  const module = androidSecureSession();
  if (!module?.setItem) throw storageFailure('MODULE');
  return module.setItem(key, value);
};

const secureGetItem = (key: string) => {
  if (Platform.OS !== 'android') {
    return SecureStore.getItemAsync(key, SECURE_OPTIONS);
  }
  const module = androidSecureSession();
  if (!module?.getItem) throw storageFailure('MODULE');
  return module.getItem(key);
};

const secureDeleteItem = (key: string) => {
  if (Platform.OS !== 'android') {
    return SecureStore.deleteItemAsync(key, SECURE_OPTIONS);
  }
  const module = androidSecureSession();
  if (!module?.deleteItem) throw storageFailure('MODULE');
  return module.deleteItem(key);
};

const readSecureToken = async (): Promise<string | null> => {
  const current = String((await secureGetItem(SECURE_TOKEN_KEY)) || '').trim();
  if (current) return current;
  for (const legacyKey of LEGACY_SECURE_TOKEN_KEYS) {
    const legacy = String((await secureGetItem(legacyKey)) || '').trim();
    if (!legacy) continue;
    // Copy before retiring a logical key. Keep the adjacent v1 copy during
    // this compatibility window so a deliberate downgrade can still restore
    // the same owner instead of appearing as an unexplained logout.
    await secureSetItem(SECURE_TOKEN_KEY, legacy);
    return legacy;
  }
  return null;
};

const writeSecureToken = async (token: string) => {
  const normalized = token.trim();
  if (!normalized) throw storageFailure('MISSING_TOKEN');
  await secureSetItem(SECURE_TOKEN_KEY, normalized);
  await Promise.all(
    LEGACY_SECURE_TOKEN_KEYS.map(key => secureSetItem(key, normalized)),
  );
};

const deleteSecureTokens = () =>
  Promise.all([
    secureDeleteItem(SECURE_TOKEN_KEY),
    ...LEGACY_SECURE_TOKEN_KEYS.map(key => secureDeleteItem(key)),
  ]);

let storageAvailabilityPromise: Promise<void> | null = null;

const storageFailure = (stage: string, error?: unknown) => {
  const nativeCode =
    typeof error === 'object' &&
    error !== null &&
    'code' in error &&
    typeof error.code === 'string'
      ? error.code
          .toUpperCase()
          .replace(/[^A-Z0-9]+/g, '_')
          .replace(/^_+|_+$/g, '')
          .slice(0, 28)
      : '';
  return new Error(
    `SESSION_STORAGE_UNAVAILABLE_${stage}${nativeCode ? `_${nativeCode}` : ''}`,
  );
};

const performStorageAvailabilityCheck = async () => {
  if (!(await secureStorageAvailable())) {
    throw storageFailure('MODULE');
  }
  const probe = `rokn-${Date.now()}`;
  try {
    await secureSetItem(SECURE_STORAGE_PROBE_KEY, probe);
  } catch (error) {
    throw storageFailure('WRITE', error);
  }

  try {
    const restored = await secureGetItem(SECURE_STORAGE_PROBE_KEY);
    if (restored !== probe) throw storageFailure('ROUNDTRIP');
  } catch (error) {
    if (
      error instanceof Error &&
      error.message.startsWith('SESSION_STORAGE_UNAVAILABLE_')
    ) {
      throw error;
    }
    throw storageFailure('READ', error);
  }

  try {
    await secureDeleteItem(SECURE_STORAGE_PROBE_KEY);
  } catch (error) {
    throw storageFailure('DELETE', error);
  }
};

/** Fail before opening OAuth if this release cannot persist the returned session. */
export const assertSecureSessionStorageAvailable = async () => {
  if (!storageAvailabilityPromise) {
    storageAvailabilityPromise = performStorageAvailabilityCheck().catch(
      error => {
        storageAvailabilityPromise = null;
        throw error;
      },
    );
  }
  await storageAvailabilityPromise;
};

export type PendingSocialAuthAttempt = {
  provider: 'google' | 'tiktok' | 'facebook';
  verifier: string;
  startedAt: string;
  callbackUrl?: string;
  purpose?: 'login' | 'reauth';
  completedSession?: unknown;
};

export const savePendingSocialAuthAttempt = async (
  attempt: PendingSocialAuthAttempt,
) => {
  await secureSetItem(PENDING_SOCIAL_AUTH_KEY, JSON.stringify(attempt));
};

export const loadPendingSocialAuthAttempt = async () => {
  const value = await secureGetItem(PENDING_SOCIAL_AUTH_KEY);
  if (!value) return null;
  try {
    const attempt = JSON.parse(value) as Partial<PendingSocialAuthAttempt>;
    if (
      !['google', 'tiktok', 'facebook'].includes(String(attempt.provider)) ||
      typeof attempt.verifier !== 'string' ||
      !/^[A-Za-z0-9._~-]{43,128}$/.test(attempt.verifier) ||
      typeof attempt.startedAt !== 'string' ||
      (attempt.purpose !== undefined &&
        !['login', 'reauth'].includes(attempt.purpose))
    ) {
      await secureDeleteItem(PENDING_SOCIAL_AUTH_KEY);
      return null;
    }
    return attempt as PendingSocialAuthAttempt;
  } catch {
    await secureDeleteItem(PENDING_SOCIAL_AUTH_KEY);
    return null;
  }
};

export const deletePendingSocialAuthAttempt = () =>
  secureDeleteItem(PENDING_SOCIAL_AUTH_KEY);

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
  image?: string;
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
    nestedValue(value, ['apiToken']),
    nestedValue(value, ['token']),
    nestedValue(value, ['access_token']),
    nestedValue(value, ['data', 'api_token']),
    nestedValue(value, ['data', 'apiToken']),
    nestedValue(value, ['data', 'token']),
    nestedValue(value, ['data', 'access_token']),
    nestedValue(value, ['data', 'data', 'api_token']),
    nestedValue(value, ['data', 'data', 'token']),
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
    nestedValue(value, ['profile']),
    nestedValue(value, ['data', 'profile']),
    nestedValue(value, ['student']),
    nestedValue(value, ['data', 'student']),
    nestedValue(value, ['data']),
    value,
  ];
  const profile = candidates.find(isRecord) as SessionProfile | undefined;
  if (!profile) return {};
  const clean = {...profile};
  if (clean.id === undefined && clean.user_id === undefined) {
    const legacyOwner =
      clean.userId ?? clean.student_id ?? clean.studentId ?? clean.social_id;
    if (legacyOwner !== undefined && legacyOwner !== null) {
      clean.user_id = legacyOwner as string | number;
    }
  }
  for (const key of ['avatar', 'profile_image', 'image'] as const) {
    const uri = clean[key];
    if (
      typeof uri === 'string' &&
      /\/images\/service\.jpg(?:\?|#|$)/i.test(uri.trim())
    ) {
      delete clean[key];
    }
  }
  return clean;
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

const sessionOwnerKey = (value: unknown): string => {
  const profile = extractUserProfile(value);
  const id = profile.id ?? profile.user_id;
  return id === undefined || id === null ? '' : String(id).trim();
};

/**
 * Login is normally preceded by logout, but deep links and shared devices can
 * replace one valid session directly. Quiesce the old account while its token
 * and profile are still current, then remove only its scoped local state.
 */
const clearPreviousAccountBeforeReplacement = async () => {
  const helpers = await import('../constants/helpers');
  const accountScope = await helpers.getCurrentAccountStorageScope();
  const [reminders, push, deviceSessions, learning, chat] = await Promise.all([
    import('./smartReminders'),
    import('./pushNotifications'),
    import('./deviceSessions'),
    import('../components/VideoPlayer/courseLearningApi'),
    import('../utils/fileCache'),
  ]);

  reminders.cancelLearningReminders();
  await reminders.setSmartRemindersEnabled(false).catch(() => undefined);
  const previousPushToken = await push
    .getCurrentPushDeviceToken()
    .catch(() => null);
  // A direct account switch is also a logout from this installation. Close
  // the old bearer while it is still the active secure session; otherwise a
  // discarded token remains listed as a live device for up to its full TTL.
  await deviceSessions
    .revokeCurrentDeviceSession(previousPushToken)
    .catch(() => undefined);
  await push.clearCurrentPushDeviceRegistration();
  await learning.clearCurrentAccountLearningFiles(accountScope);
  await chat.clearTransientChatCache({accountBoundary: true});
  await helpers.clearLegacyUnscopedPersonalStorage();
  await helpers.clearAccountScopedStorage(accountScope, {
    preserveFinancialRecovery: true,
  });
};

const revokeReplacedBearerForSameAccount = async () => {
  const [push, deviceSessions] = await Promise.all([
    import('./pushNotifications'),
    import('./deviceSessions'),
  ]);
  const pushToken = await push.getCurrentPushDeviceToken().catch(() => null);
  await deviceSessions
    .revokeCurrentDeviceSession(pushToken)
    .catch(() => undefined);
};

let migrationPromise: Promise<void> | null = null;
let cachedSession: unknown = null;
let sessionCacheReady = false;
let sessionLoadPromise: Promise<unknown> | null = null;
let sessionCacheEpoch = 0;
let sessionMutationTail: Promise<unknown> = Promise.resolve();

const serializeSessionMutation = <T>(operation: () => Promise<T>) => {
  const result = sessionMutationTail.then(operation, operation);
  sessionMutationTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

const performLegacyMigration = async () => {
  const [rawUserData, rawPersistedAuth, migrationVersion, secureToken] =
    await Promise.all([
      AsyncStorage.getItem(USER_DATA_KEY),
      AsyncStorage.getItem(LEGACY_REDUX_AUTH_KEY),
      AsyncStorage.getItem(MIGRATION_KEY),
      readSecureToken(),
    ]);

  const asyncSession = parseJson(rawUserData);
  const reduxSession = persistedReduxSession(rawPersistedAuth);
  const legacyToken =
    extractApiToken(asyncSession) ?? extractApiToken(reduxSession);
  const tokenToKeep = legacyToken ?? secureToken;
  // A partially written USER_DATA value must not shadow the older Redux copy.
  // Prefer the new record only when it is structurally usable; this is the
  // common recovery path after the process dies between the two old writes.
  const sessionToKeep = isRecord(asyncSession)
    ? asyncSession
    : isRecord(reduxSession)
    ? reduxSession
    : null;
  const hasLegacyCredential = Boolean(legacyToken);
  const hasReduxDuplicate = rawPersistedAuth !== null;
  const needsMigration =
    migrationVersion !== MIGRATION_VERSION ||
    hasLegacyCredential ||
    hasReduxDuplicate;

  if (!needsMigration) return;

  // Never erase a plaintext credential until its secure copy has succeeded.
  if (tokenToKeep && sessionToKeep && tokenToKeep !== secureToken) {
    await writeSecureToken(tokenToKeep);
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

const persistSecureSession = async (session: unknown) => {
  const apiToken = extractApiToken(session);
  if (!apiToken) {
    throw new Error('SESSION_STORAGE_UNAVAILABLE_MISSING_TOKEN');
  }
  const profile = extractUserProfile(session);
  if (profile.id === undefined && profile.user_id === undefined) {
    throw new Error('SESSION_STORAGE_UNAVAILABLE_INVALID_PROFILE');
  }
  const rawPreviousSession = parseJson(
    await AsyncStorage.getItem(USER_DATA_KEY),
  );
  const previousSession =
    sessionCacheReady && cachedSession ? cachedSession : rawPreviousSession;
  const previousOwner = sessionOwnerKey(previousSession);
  const nextOwner = sessionOwnerKey(session);
  if (previousOwner && nextOwner && previousOwner !== nextOwner) {
    await clearPreviousAccountBeforeReplacement();
  }
  const sanitized = sanitizeSessionForStorage(session);
  const previousToken = await readSecureToken();
  const tokenChanged = Boolean(apiToken && apiToken !== previousToken);

  if (
    tokenChanged &&
    previousToken &&
    previousOwner &&
    previousOwner === nextOwner
  ) {
    // Re-signing into the same account replaces this installation's bearer.
    // Revoke the superseded session instead of leaving an invisible duplicate
    // in the device list until server expiry.
    await revokeReplacedBearerForSameAccount();
  }

  if (tokenChanged && apiToken) {
    await writeSecureToken(apiToken);
  }

  try {
    await AsyncStorage.setItem(USER_DATA_KEY, JSON.stringify(sanitized));
  } catch (error) {
    // Avoid pairing a newly written token with another account's old profile.
    if (tokenChanged) {
      if (previousToken) {
        await writeSecureToken(previousToken);
      } else {
        await deleteSecureTokens();
      }
    }
    throw error;
  }

  // The credential and matching profile are now durable. Migration markers
  // and removal of an obsolete Redux duplicate are housekeeping; a failure
  // there must not report that the completed social login was lost.
  sessionCacheEpoch += 1;
  cachedSession = attachTokenInMemory(sanitized, apiToken);
  sessionCacheReady = true;
  sessionLoadPromise = null;
  migrationPromise = Promise.resolve();
  await Promise.allSettled([
    AsyncStorage.removeItem(LEGACY_REDUX_AUTH_KEY),
    AsyncStorage.setItem(MIGRATION_KEY, MIGRATION_VERSION),
  ]);
};

export const saveSecureSession = (session: unknown) =>
  serializeSessionMutation(() => persistSecureSession(session));

/** Apply a profile mutation only while the same account still owns the session. */
export const updateSecureSessionForOwner = (
  expectedOwner: string,
  update: (session: unknown) => unknown,
) =>
  serializeSessionMutation(async () => {
    const normalizedOwner = expectedOwner.trim();
    const current = await loadSecureSession();
    if (!normalizedOwner || sessionOwnerKey(current) !== normalizedOwner) {
      throw new Error('ACCOUNT_CHANGED_DURING_SESSION_UPDATE');
    }
    const next = update(current);
    if (sessionOwnerKey(next) !== normalizedOwner) {
      throw new Error('SESSION_UPDATE_OWNER_MISMATCH');
    }
    await persistSecureSession(next);
    return next;
  });

export const loadSecureSession = async () => {
  if (sessionCacheReady) {
    return cachedSession;
  }
  if (sessionLoadPromise) {
    return sessionLoadPromise;
  }

  const loadEpoch = sessionCacheEpoch;
  const load = (async () => {
    await migrateLegacySession();
    const [rawProfile, apiToken] = await Promise.all([
      AsyncStorage.getItem(USER_DATA_KEY),
      readSecureToken(),
    ]);
    const storedProfile = parseJson(rawProfile);

    let restoredSession: unknown = null;
    if (rawProfile === null || !isRecord(storedProfile)) {
      // A secure token without its profile can only be a partially completed
      // logout. Remove it instead of resurrecting an ownerless session.
      if (apiToken) {
        await deleteSecureTokens();
      }
      if (rawProfile !== null) {
        await AsyncStorage.removeItem(USER_DATA_KEY);
      }
    } else if (!apiToken) {
      // A profile is not a session. Android backup/key rotation or an
      // interrupted logout can leave the non-secret half behind; keeping it
      // would make guest caches use the previous learner's account scope.
      await AsyncStorage.removeItem(USER_DATA_KEY);
    } else {
      restoredSession = attachTokenInMemory(storedProfile, apiToken);
      const profile = extractUserProfile(restoredSession);
      if (profile.id === undefined && profile.user_id === undefined) {
        await Promise.all([
          deleteSecureTokens(),
          AsyncStorage.removeItem(USER_DATA_KEY),
        ]);
        restoredSession = null;
      }
    }

    // A logout or account switch may finish while the keychain read is in
    // flight. Never let that older read resurrect the previous account.
    if (loadEpoch !== sessionCacheEpoch) {
      return sessionCacheReady ? cachedSession : null;
    }
    cachedSession = restoredSession;
    sessionCacheReady = true;
    return restoredSession;
  });
  let trackedLoad: Promise<unknown>;
  trackedLoad = load().finally(() => {
    if (sessionLoadPromise === trackedLoad) sessionLoadPromise = null;
  });
  sessionLoadPromise = trackedLoad;
  return trackedLoad;
};

/**
 * Let a foreground retry replace a native keychain read that never settled.
 * The epoch prevents the abandoned read from restoring an older owner if the
 * OS eventually calls it back after a logout, login or replacement attempt.
 */
export const abandonPendingSecureSessionRestore = () => {
  if (sessionCacheReady || !sessionLoadPromise) return false;
  sessionCacheEpoch += 1;
  sessionLoadPromise = null;
  migrationPromise = null;
  return true;
};

/** Single source of truth for cold-start Redux hydration. */
export const restoreSecureAuthState = async () => {
  const session = await loadSecureSession();
  return {
    session,
    isAuthenticated: Boolean(extractApiToken(session)),
  };
};

const performDeleteSecureSession = async () => {
  sessionCacheEpoch += 1;
  cachedSession = null;
  sessionCacheReady = true;
  sessionLoadPromise = null;
  const results = await Promise.allSettled([
    deleteSecureTokens(),
    secureDeleteItem(PENDING_SOCIAL_AUTH_KEY),
    AsyncStorage.removeItem(USER_DATA_KEY),
    AsyncStorage.removeItem(LEGACY_REDUX_AUTH_KEY),
  ]);
  migrationPromise = null;
  return results.every(result => result.status === 'fulfilled');
};

export const deleteSecureSession = () =>
  serializeSessionMutation(performDeleteSecureSession);

const performClearSecureSessionStorage = async () => {
  sessionCacheEpoch += 1;
  cachedSession = null;
  sessionCacheReady = true;
  sessionLoadPromise = null;
  await Promise.all([
    deleteSecureTokens(),
    secureDeleteItem(PENDING_SOCIAL_AUTH_KEY),
  ]);
  await AsyncStorage.clear();
  migrationPromise = null;
};

export const clearSecureSessionStorage = () =>
  serializeSessionMutation(performClearSecureSessionStorage);

/** Test isolation for the module-level migration de-duplication promise. */
export const resetSecureSessionMigrationForTests = () => {
  migrationPromise = null;
  cachedSession = null;
  sessionCacheReady = false;
  sessionLoadPromise = null;
  sessionCacheEpoch = 0;
  storageAvailabilityPromise = null;
  sessionMutationTail = Promise.resolve();
};
