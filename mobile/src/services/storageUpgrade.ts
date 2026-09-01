import AsyncStorage from '@react-native-async-storage/async-storage';
import {getCurrentAccountStorageScope} from '../constants/helpers';

const DEVICE_MIGRATION_KEY = '@rokn/storage-upgrade/device/v1';
const ACCOUNT_MIGRATION_KEY = '@rokn/storage-upgrade/account/v1';
const CURRENT_DEVICE_VERSION = 2;
const CURRENT_ACCOUNT_VERSION = 2;
let deviceUpgradeFlight: Promise<void> | null = null;
let settingsMigrationFlight: Promise<boolean> | null = null;
const accountUpgradeFlights = new Map<string, Promise<void>>();

type MigrationReceipt = {version: number; completedAt: string};

const readMigrationVersion = async (key: string) => {
  const raw = await AsyncStorage.getItem(key);
  if (!raw) return 0;
  try {
    const receipt = JSON.parse(raw) as Partial<MigrationReceipt>;
    return Number.isSafeInteger(receipt.version) && Number(receipt.version) > 0
      ? Number(receipt.version)
      : 0;
  } catch {
    return 0;
  }
};

const writeReceipt = (key: string, version: number) =>
  AsyncStorage.setItem(
    key,
    JSON.stringify({version, completedAt: new Date().toISOString()}),
  );

const parseJson = (raw: string | null): unknown => {
  if (raw === null) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return undefined;
  }
};

const asRecord = (value: unknown): Record<string, unknown> | null =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;

const mergeUniqueStrings = (left: unknown, right: unknown) =>
  Array.from(
    new Set(
      [
        ...(Array.isArray(left) ? left : []),
        ...(Array.isArray(right) ? right : []),
      ]
        .filter((value): value is string => typeof value === 'string')
        .map(value => value.trim())
        .filter(Boolean),
    ),
  );

const mergeArrayValues = (legacy: unknown, current: unknown) => {
  const values = [
    ...(Array.isArray(current) ? current : []),
    ...(Array.isArray(legacy) ? legacy : []),
  ];
  const seen = new Set<string>();
  return values.filter(value => {
    try {
      const identity = JSON.stringify(value);
      if (typeof identity !== 'string') return false;
      if (seen.has(identity)) return false;
      seen.add(identity);
      return true;
    } catch {
      return false;
    }
  });
};

const mergePlayerState = (legacy: unknown, current: unknown) => {
  const source = asRecord(legacy) || {};
  const target = asRecord(current) || {};
  const objectFields = ['positions', 'lastWatchedAt'] as const;
  const arrayFields = [
    'completedSections',
    'savedLessons',
    'passedProjects',
    'provisionalProjects',
    'activityDays',
  ] as const;
  const merged: Record<string, unknown> = {...source, ...target};
  objectFields.forEach(field => {
    merged[field] = {
      ...(asRecord(source[field]) || {}),
      ...(asRecord(target[field]) || {}),
    };
  });
  arrayFields.forEach(field => {
    merged[field] = mergeUniqueStrings(source[field], target[field]);
  });

  const sourceFolders = asRecord(source.savedFolderLessons) || {};
  const targetFolders = asRecord(target.savedFolderLessons) || {};
  merged.savedFolderLessons = Object.fromEntries(
    Array.from(
      new Set([...Object.keys(sourceFolders), ...Object.keys(targetFolders)]),
    ).map(folderId => [
      folderId,
      mergeUniqueStrings(sourceFolders[folderId], targetFolders[folderId]),
    ]),
  );
  return merged;
};

const moveJsonValue = async (
  sourceKey: string,
  targetKey: string,
  merge?: (source: unknown, target: unknown) => unknown,
) => {
  const [[, sourceRaw], [, targetRaw]] = await AsyncStorage.multiGet([
    sourceKey,
    targetKey,
  ]);
  if (sourceRaw === null) return true;
  const source = parseJson(sourceRaw);
  const target = parseJson(targetRaw);
  // A damaged value is retained verbatim for support/recovery. It must not be
  // marked migrated or replace a valid value written by a newer release.
  if (source === undefined || target === undefined) return false;
  const next = merge
    ? merge(source, target)
    : targetRaw === null
    ? source
    : target;
  await AsyncStorage.setItem(targetKey, JSON.stringify(next));
  await AsyncStorage.removeItem(sourceKey);
  return true;
};

const normalizePersistedLanguage = (value: unknown): 'ar' | 'en' => {
  let decoded = value;
  if (typeof decoded === 'string') {
    const encoded = decoded;
    try {
      decoded = JSON.parse(encoded);
    } catch {
      decoded = encoded.trim();
    }
  }
  const candidate =
    typeof decoded === 'string'
      ? decoded
      : typeof asRecord(decoded)?.code === 'string'
      ? String(asRecord(decoded)?.code)
      : '';
  return candidate.toLowerCase() === 'en' ? 'en' : 'ar';
};

const validPersistedSettings = (raw: string | null) => {
  const root = asRecord(parseJson(raw));
  if (
    !root ||
    typeof root.language !== 'string' ||
    typeof root._persist !== 'string'
  ) {
    return false;
  }
  try {
    const language = JSON.parse(root.language);
    const persistence = asRecord(JSON.parse(root._persist));
    const value =
      typeof language === 'string'
        ? language
        : typeof asRecord(language)?.code === 'string'
        ? asRecord(language)?.code
        : '';
    return (
      ['ar', 'en'].includes(String(value).toLowerCase()) &&
      Boolean(persistence) &&
      Number.isFinite(Number(persistence?.version))
    );
  } catch {
    return false;
  }
};

const quarantineCorruptValue = async (key: string, raw: string) => {
  await AsyncStorage.setItem(`${key}:corrupt`, raw.slice(0, 8192));
  await AsyncStorage.removeItem(key);
};

const performLegacySettingsMigration = async () => {
  const sourceKey = 'persist:settings';
  const targetKey = 'persist:settings-v2';
  const [[, sourceRaw], [, targetRaw]] = await AsyncStorage.multiGet([
    sourceKey,
    targetKey,
  ]);
  if (validPersistedSettings(targetRaw)) {
    if (sourceRaw !== null) {
      let sourceLanguage: 'ar' | 'en';
      try {
        const source = JSON.parse(sourceRaw) as Record<string, unknown>;
        sourceLanguage = normalizePersistedLanguage(source.language);
      } catch {
        await quarantineCorruptValue(sourceKey, sourceRaw);
        return true;
      }
      // If the retired key reappears after a completed migration, an adjacent
      // downgraded build wrote it later. Copy before delete; a quota failure
      // leaves both the valid target and newer legacy source intact.
      await AsyncStorage.setItem(
        targetKey,
        JSON.stringify({
          language: JSON.stringify(sourceLanguage),
          _persist: JSON.stringify({version: 2, rehydrated: true}),
        }),
      );
      await AsyncStorage.removeItem(sourceKey);
    }
    return true;
  }
  if (targetRaw !== null) {
    // Redux Persist reads before the React tree mounts. Remove a partial root
    // record now so rehydration starts from reducer defaults instead of
    // repeatedly parsing the same damaged bytes on every update.
    await quarantineCorruptValue(targetKey, targetRaw);
  }
  if (!sourceRaw) return true;
  // redux-persist stores each field as a JSON string inside its JSON object.
  // Keep only language; retired account-derived settings must not cross users.
  try {
    const source = JSON.parse(sourceRaw) as Record<string, unknown>;
    const language = normalizePersistedLanguage(source.language);
    await AsyncStorage.setItem(
      targetKey,
      JSON.stringify({
        language: JSON.stringify(language),
        _persist: JSON.stringify({version: 2, rehydrated: true}),
      }),
    );
    await AsyncStorage.removeItem(sourceKey);
    return true;
  } catch {
    await quarantineCorruptValue(sourceKey, sourceRaw);
    return true;
  }
};

export const migrateLegacySettings = async () => {
  if (!settingsMigrationFlight) {
    const flight = performLegacySettingsMigration();
    settingsMigrationFlight = flight;
    void flight.then(
      () => {
        if (settingsMigrationFlight === flight) settingsMigrationFlight = null;
      },
      () => {
        if (settingsMigrationFlight === flight) settingsMigrationFlight = null;
      },
    );
  }
  return settingsMigrationFlight;
};

/** Device-only upgrade work. Every step is copy-before-delete and retry-safe. */
export const runDeviceStorageUpgrade = async () => {
  if (deviceUpgradeFlight) return deviceUpgradeFlight;
  const flight = (async () => {
    const completedVersion = await readMigrationVersion(DEVICE_MIGRATION_KEY);
    // A downgrade can recreate retired keys after this receipt was written.
    // Discovery therefore remains idempotent on every launch; the version
    // gates only one-time transforms introduced by a newer build.
    if (await migrateLegacySettings()) {
      if (completedVersion < CURRENT_DEVICE_VERSION) {
        await writeReceipt(DEVICE_MIGRATION_KEY, CURRENT_DEVICE_VERSION);
      }
    }
  })();
  deviceUpgradeFlight = flight;
  try {
    await flight;
  } finally {
    if (deviceUpgradeFlight === flight) deviceUpgradeFlight = null;
  }
};

const migrateLegacyDynamicKeys = async (accountScope: string) => {
  const keys = await AsyncStorage.getAllKeys();
  const mappings = [
    {
      prefix: '@rokn/project-submission/v2:',
      isLegacySuffix: (suffix: string) => !suffix.includes(':'),
    },
    {
      prefix: '@rokn/watch-evidence/v1:',
      isLegacySuffix: (suffix: string) => !suffix.includes(':'),
    },
    {
      prefix: '@rokn/section-completion/v1:',
      isLegacySuffix: (suffix: string) => suffix.split(':').length === 2,
    },
  ] as const;
  let complete = true;
  for (const mapping of mappings) {
    for (const sourceKey of keys) {
      if (!sourceKey.startsWith(mapping.prefix)) continue;
      const suffix = sourceKey.slice(mapping.prefix.length);
      if (!suffix || !mapping.isLegacySuffix(suffix)) continue;
      complete =
        (await moveJsonValue(
          sourceKey,
          `${mapping.prefix}${accountScope}:${suffix}`,
        )) && complete;
    }
  }
  return complete;
};

/**
 * Attach data written by pre-account-scope builds to the restored owner.
 * This runs only after a valid secure session is available; guest startup and
 * a partial keychain restore never claim another learner's old private data.
 */
export const runAuthenticatedStorageUpgrade = async () => {
  const accountScope = await getCurrentAccountStorageScope();
  if (!accountScope.startsWith('user-')) return;
  const existingFlight = accountUpgradeFlights.get(accountScope);
  if (existingFlight) return existingFlight;
  const flight = (async () => {
    const receiptKey = `${ACCOUNT_MIGRATION_KEY}:${accountScope}`;
    const completedVersion = await readMigrationVersion(receiptKey);

    const exactMappings: Array<
      [string, string, ((source: unknown, target: unknown) => unknown)?]
    > = [
      [
        '@rokn/notifications/read/v1',
        '@rokn/notifications/read/v2',
        mergeArrayValues,
      ],
      [
        '@rokn/portfolio/custom-projects/v1',
        '@rokn/portfolio/custom-projects/v2',
        mergeArrayValues,
      ],
      ['@rokn/search-history/v1', '@rokn/search-history/v1', mergeArrayValues],
      ['VIDEO_QUALITY', 'VIDEO_QUALITY'],
      ['VIDEO_PLAYBACK_SPEED', 'VIDEO_PLAYBACK_SPEED'],
      ['PREF_WATCH_HISTORY', 'PREF_WATCH_HISTORY'],
      ['PREF_NOTIFICATIONS', 'PREF_NOTIFICATIONS'],
      ['PREF_MARKETING_NOTIFICATIONS', 'PREF_MARKETING_NOTIFICATIONS'],
      ['PREF_REMINDER_HOUR', 'PREF_REMINDER_HOUR'],
    ];
    let complete = true;
    for (const [sourceKey, targetBase, merge] of exactMappings) {
      complete =
        (await moveJsonValue(
          sourceKey,
          `${targetBase}:${accountScope}`,
          merge,
        )) && complete;
    }
    complete =
      (await moveJsonValue(
        '@rokn/course-player/v3',
        `@rokn/course-player/v3:${accountScope}`,
        mergePlayerState,
      )) && complete;
    complete = (await migrateLegacyDynamicKeys(accountScope)) && complete;
    if (complete && completedVersion < CURRENT_ACCOUNT_VERSION) {
      await writeReceipt(receiptKey, CURRENT_ACCOUNT_VERSION);
    }
  })();
  accountUpgradeFlights.set(accountScope, flight);
  try {
    await flight;
  } finally {
    if (accountUpgradeFlights.get(accountScope) === flight) {
      accountUpgradeFlights.delete(accountScope);
    }
  }
};
