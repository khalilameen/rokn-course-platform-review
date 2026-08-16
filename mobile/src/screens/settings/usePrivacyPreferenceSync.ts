import {useCallback, useRef} from 'react';
import {WATCH_HISTORY_ENABLED_KEY} from '../../components/VideoPlayer/courseLearningApi';
import {
  AsyncKeys,
  accountScopedStorageKey,
  extractApiToken,
  getItem,
  removeItem,
  saveItem,
} from '../../constants/helpers';
import {updatePrivacyPreferences} from '../../services/roknApi';

export const MARKETING_NOTIFICATIONS_KEY = 'PREF_MARKETING_NOTIFICATIONS';

const PENDING_PRIVACY_PREFERENCES_KEY = '@rokn/pending-privacy-preferences/v1';

export type PendingPrivacyPreferences = {
  watchHistoryEnabled?: boolean;
  marketingNotificationsEnabled?: boolean;
};

const pendingPreferencesKey = () =>
  accountScopedStorageKey(PENDING_PRIVACY_PREFERENCES_KEY);

export const readPendingPrivacyPreferences = async (storageKey?: string) =>
  (await getItem<PendingPrivacyPreferences>(
    storageKey || (await pendingPreferencesKey()),
  )) || {};

export const usePrivacyPreferenceSync = () => {
  const dirtyKeysRef = useRef(new Set<string>());
  const queueRef = useRef<Promise<void>>(Promise.resolve());

  const queue = useCallback((patch: PendingPrivacyPreferences = {}) => {
    const targetKey = pendingPreferencesKey();
    const targetSessionToken = getItem(AsyncKeys.USER_DATA).then(
      extractApiToken,
    );

    queueRef.current = queueRef.current
      .catch(() => undefined)
      .then(async () => {
        const key = await targetKey;
        const pending = {
          ...(await readPendingPrivacyPreferences(key)),
          ...patch,
        };
        if (!Object.keys(pending).length) return;

        await saveItem(key, pending);
        const [expectedToken, currentToken] = await Promise.all([
          targetSessionToken,
          getItem(AsyncKeys.USER_DATA).then(extractApiToken),
        ]);
        if (!expectedToken || expectedToken !== currentToken) return;

        try {
          await updatePrivacyPreferences(pending);
          await removeItem(key);
          if (typeof pending.watchHistoryEnabled === 'boolean') {
            dirtyKeysRef.current.delete(WATCH_HISTORY_ENABLED_KEY);
          }
          if (typeof pending.marketingNotificationsEnabled === 'boolean') {
            dirtyKeysRef.current.delete(MARKETING_NOTIFICATIONS_KEY);
          }
        } catch {
          // Keep pending changes until sync succeeds.
        }
      });

    return queueRef.current;
  }, []);

  return {dirtyKeys: dirtyKeysRef.current, queue};
};
