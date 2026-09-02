import {useEffect, useRef, useState} from 'react';
import {Alert} from 'react-native';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  extractApiToken,
  getItem,
  removeItem,
  saveItem,
} from '../../constants/helpers';
import {
  cancelLearningReminders,
  enableSmartReminders,
  getSmartReminderHour,
  getSmartRemindersEnabled,
  REMINDER_ENABLED_KEY,
  setSmartReminderHour,
  setSmartRemindersEnabled,
} from '../../services/smartReminders';
import {
  clearWatchHistory,
  getProfile,
  updateNotificationStatus,
  updatePlaybackPreferences,
} from '../../services/roknApi';
import {
  clearLocalWatchHistory,
  WATCH_HISTORY_ENABLED_KEY,
} from '../../components/VideoPlayer/courseLearningApi';
import {
  registerPushDeviceIfEligible,
  unregisterPushDevice,
} from '../../services/pushNotifications';
import type {SettingsChoice} from '../../components/settings/SettingsChoiceModal';
import {
  MARKETING_NOTIFICATIONS_KEY,
  readPendingPrivacyPreferences,
  usePrivacyPreferenceSync,
} from './usePrivacyPreferenceSync';
import {PENDING_WATCH_HISTORY_CLEAR_KEY} from './settingsData';

const settingsScopeWriteTails = new Map<string, Promise<unknown>>();

const withSettingsScopeWrite = <T>(
  boundary: Awaited<ReturnType<typeof captureAccountSessionBoundary>>,
  write: () => Promise<T>,
) => {
  const previous =
    settingsScopeWriteTails.get(boundary.scope) ?? Promise.resolve();
  const result = previous.then(write, write);
  const tail = result.then(
    () => undefined,
    () => undefined,
  );
  settingsScopeWriteTails.set(boundary.scope, tail);
  void tail.finally(() => {
    if (settingsScopeWriteTails.get(boundary.scope) === tail) {
      settingsScopeWriteTails.delete(boundary.scope);
    }
  });
  return result;
};

const normalizeStoredQuality = (value: unknown) => {
  const legacyAliases: Record<string, string> = {
    تلقائي: 'auto',
    'توفير البيانات': 'data_saver',
  };
  const candidate =
    typeof value === 'string' ? legacyAliases[value] || value : '';
  return ['auto', 'data_saver', '1080p', '720p', '480p', '360p'].includes(
    candidate,
  )
    ? candidate
    : 'auto';
};

export const useSettingsPreferences = ({
  hasAuthenticatedAccount,
  userData,
}: {
  hasAuthenticatedAccount: boolean;
  userData: unknown;
}) => {
  const [choiceModal, setChoiceModal] = useState<SettingsChoice>(null);
  const [notificationPrimer, setNotificationPrimer] = useState(false);
  const [quality, setQuality] = useState('auto');
  const [notifications, setNotifications] = useState(false);
  const [marketingNotifications, setMarketingNotifications] = useState(false);
  const [watchHistory, setWatchHistory] = useState(true);
  const [reminderHour, setReminderHour] = useState(20);
  const {dirtyKeys: privacyDirtyKeys, queue: queuePrivacyPreferenceSync} =
    usePrivacyPreferenceSync();
  const preferenceRevisionRef = useRef<Record<string, number>>({});

  const markPreferenceMutation = (key: string) => {
    preferenceRevisionRef.current[key] =
      (preferenceRevisionRef.current[key] || 0) + 1;
  };

  const enqueuePreferenceWrite = <T>(
    write: (
      boundary: Awaited<ReturnType<typeof captureAccountSessionBoundary>>,
    ) => Promise<T>,
  ) => {
    // Capture the account at tap time, not when an older write finishes. This
    // keeps rapid changes ordered and prevents a queued write crossing logout.
    const boundaryFlight = captureAccountSessionBoundary();
    return boundaryFlight.then(boundary =>
      withSettingsScopeWrite(boundary, async () => {
        assertAccountSessionBoundary(boundary);
        const value = await write(boundary);
        assertAccountSessionBoundary(boundary);
        return value;
      }),
    );
  };

  useEffect(() => {
    let active = true;
    void (async () => {
      const initialRevisions = {
        [REMINDER_ENABLED_KEY]:
          preferenceRevisionRef.current[REMINDER_ENABLED_KEY] || 0,
        VIDEO_QUALITY: preferenceRevisionRef.current.VIDEO_QUALITY || 0,
        REMINDER_HOUR: preferenceRevisionRef.current.REMINDER_HOUR || 0,
        [WATCH_HISTORY_ENABLED_KEY]:
          preferenceRevisionRef.current[WATCH_HISTORY_ENABLED_KEY] || 0,
        [MARKETING_NOTIFICATIONS_KEY]:
          preferenceRevisionRef.current[MARKETING_NOTIFICATIONS_KEY] || 0,
      };
      const isUnchanged = (key: keyof typeof initialRevisions) =>
        (preferenceRevisionRef.current[key] || 0) === initialRevisions[key];
      const boundary = await captureAccountSessionBoundary();
      const scopedKey = (key: string) => accountScopedStorageKey(key, boundary);
      const [
        savedNotifications,
        savedQuality,
        savedReminderHour,
        savedWatchHistory,
        savedMarketingNotifications,
      ] = await Promise.all([
        getSmartRemindersEnabled(),
        scopedKey('VIDEO_QUALITY').then(getItem),
        getSmartReminderHour(),
        scopedKey(WATCH_HISTORY_ENABLED_KEY).then(getItem),
        scopedKey(MARKETING_NOTIFICATIONS_KEY).then(getItem),
      ]);
      assertAccountSessionBoundary(boundary);
      if (!active) return;
      if (
        typeof savedNotifications === 'boolean' &&
        isUnchanged(REMINDER_ENABLED_KEY)
      ) {
        setNotifications(savedNotifications);
      }
      if (isUnchanged('VIDEO_QUALITY')) {
        setQuality(normalizeStoredQuality(savedQuality));
      }
      if (
        typeof savedWatchHistory === 'boolean' &&
        isUnchanged(WATCH_HISTORY_ENABLED_KEY)
      ) {
        setWatchHistory(savedWatchHistory);
      }
      if (
        typeof savedMarketingNotifications === 'boolean' &&
        isUnchanged(MARKETING_NOTIFICATIONS_KEY)
      ) {
        setMarketingNotifications(savedMarketingNotifications);
      }
      if (
        [10, 15, 20].includes(Number(savedReminderHour)) &&
        isUnchanged('REMINDER_HOUR')
      ) {
        setReminderHour(Number(savedReminderHour));
      }
      if (hasAuthenticatedAccount) {
        const pending = await readPendingPrivacyPreferences();
        if (!active) return;
        await withSettingsScopeWrite(boundary, async () => {
          assertAccountSessionBoundary(boundary);
          if (!active) return;
          if (
            typeof pending.watchHistoryEnabled === 'boolean' &&
            isUnchanged(WATCH_HISTORY_ENABLED_KEY)
          ) {
            privacyDirtyKeys.add(WATCH_HISTORY_ENABLED_KEY);
            setWatchHistory(pending.watchHistoryEnabled);
            await saveItem(
              await scopedKey(WATCH_HISTORY_ENABLED_KEY),
              pending.watchHistoryEnabled,
            );
            assertAccountSessionBoundary(boundary);
          }
          if (
            typeof pending.marketingNotificationsEnabled === 'boolean' &&
            isUnchanged(MARKETING_NOTIFICATIONS_KEY)
          ) {
            privacyDirtyKeys.add(MARKETING_NOTIFICATIONS_KEY);
            setMarketingNotifications(pending.marketingNotificationsEnabled);
            await saveItem(
              await scopedKey(MARKETING_NOTIFICATIONS_KEY),
              pending.marketingNotificationsEnabled,
            );
            assertAccountSessionBoundary(boundary);
          }
        });
        if (Object.keys(pending).length) {
          assertAccountSessionBoundary(boundary);
          await queuePrivacyPreferenceSync();
          assertAccountSessionBoundary(boundary);
          if (!active) return;
        }
        try {
          const profile = await getProfile();
          assertAccountSessionBoundary(boundary);
          if (!active) return;
          const profileQuality = normalizeStoredQuality(
            profile.videoQualityPreference,
          );
          await withSettingsScopeWrite(boundary, async () => {
            assertAccountSessionBoundary(boundary);
            if (!active) return;
            if (
              !privacyDirtyKeys.has(WATCH_HISTORY_ENABLED_KEY) &&
              isUnchanged(WATCH_HISTORY_ENABLED_KEY)
            ) {
              setWatchHistory(profile.watchHistoryEnabled);
              await saveItem(
                await scopedKey(WATCH_HISTORY_ENABLED_KEY),
                profile.watchHistoryEnabled,
              );
              assertAccountSessionBoundary(boundary);
            }
            if (
              !privacyDirtyKeys.has(MARKETING_NOTIFICATIONS_KEY) &&
              isUnchanged(MARKETING_NOTIFICATIONS_KEY)
            ) {
              setMarketingNotifications(profile.marketingNotificationsEnabled);
              await saveItem(
                await scopedKey(MARKETING_NOTIFICATIONS_KEY),
                profile.marketingNotificationsEnabled,
              );
              assertAccountSessionBoundary(boundary);
            }
            if (isUnchanged('VIDEO_QUALITY')) {
              setQuality(profileQuality);
              await scopedKey('VIDEO_QUALITY').then(key =>
                saveItem(key, profileQuality),
              );
            }
            await scopedKey('VIDEO_PLAYBACK_SPEED').then(key =>
              saveItem(key, profile.playbackSpeed),
            );
            assertAccountSessionBoundary(boundary);
          });
        } catch {
          // Settings remain readable without replacing server values.
        }
      }
    })().catch(() => undefined);
    return () => {
      active = false;
    };
  }, [hasAuthenticatedAccount, privacyDirtyKeys, queuePrivacyPreferenceSync]);

  useEffect(() => {
    if (!hasAuthenticatedAccount) return;
    void captureAccountSessionBoundary()
      .then(async boundary => {
        const key = await accountScopedStorageKey(
          PENDING_WATCH_HISTORY_CLEAR_KEY,
          boundary,
        );
        if (!(await getItem(key))) return;
        assertAccountSessionBoundary(boundary);
        await clearWatchHistory();
        assertAccountSessionBoundary(boundary);
        await removeItem(key);
        assertAccountSessionBoundary(boundary);
      })
      .catch(() => undefined);
  }, [hasAuthenticatedAccount]);

  const updatePreference = (key: string, value: boolean) => {
    markPreferenceMutation(key);
    if (
      key === WATCH_HISTORY_ENABLED_KEY ||
      key === MARKETING_NOTIFICATIONS_KEY
    ) {
      privacyDirtyKeys.add(key);
    }
    if (key === REMINDER_ENABLED_KEY) setNotifications(value);
    if (key === WATCH_HISTORY_ENABLED_KEY) setWatchHistory(value);
    if (key === MARKETING_NOTIFICATIONS_KEY) {
      setMarketingNotifications(value);
    }

    return enqueuePreferenceWrite(async boundary => {
      if (key === REMINDER_ENABLED_KEY) {
        await setSmartRemindersEnabled(value);
      } else if (
        key === WATCH_HISTORY_ENABLED_KEY ||
        key === MARKETING_NOTIFICATIONS_KEY
      ) {
        const stored = await saveItem(
          await accountScopedStorageKey(key, boundary),
          value,
        );
        assertAccountSessionBoundary(boundary);
        if (!stored) {
          throw new Error('SETTINGS_STORAGE_WRITE_FAILED');
        }
      } else {
        await saveItem(key, value);
      }
      assertAccountSessionBoundary(boundary);
      if (key === REMINDER_ENABLED_KEY && extractApiToken(userData)) {
        try {
          await updateNotificationStatus(value);
        } catch {
          // The local reminder remains active until the next server sync.
        }
        assertAccountSessionBoundary(boundary);
      }
      if (hasAuthenticatedAccount && key === WATCH_HISTORY_ENABLED_KEY) {
        await queuePrivacyPreferenceSync({watchHistoryEnabled: value});
        assertAccountSessionBoundary(boundary);
      }
      if (hasAuthenticatedAccount && key === MARKETING_NOTIFICATIONS_KEY) {
        await queuePrivacyPreferenceSync({
          marketingNotificationsEnabled: value,
        });
        assertAccountSessionBoundary(boundary);
      }
    }).catch(error => {
      if (
        error instanceof Error &&
        error.message === 'SETTINGS_STORAGE_WRITE_FAILED'
      ) {
        Alert.alert(
          'لم يُحفظ التغيير',
          'تعذّر حفظ الإعداد على الجهاز\nحاول مرة أخرى',
        );
      }
    });
  };

  const updateNotifications = async (value: boolean) => {
    if (value) {
      setNotificationPrimer(true);
    } else {
      await updatePreference(REMINDER_ENABLED_KEY, false);
      cancelLearningReminders();
      await unregisterPushDevice().catch(() => undefined);
    }
  };

  const confirmNotifications = async () => {
    const boundary = await captureAccountSessionBoundary();
    const granted = await enableSmartReminders();
    assertAccountSessionBoundary(boundary);
    if (!granted) return false;
    await updatePreference(REMINDER_ENABLED_KEY, true);
    assertAccountSessionBoundary(boundary);
    await registerPushDeviceIfEligible({requestPermission: false}).catch(
      () => false,
    );
    assertAccountSessionBoundary(boundary);
    return true;
  };

  const updateReminderHour = (hour: number) => {
    markPreferenceMutation('REMINDER_HOUR');
    setReminderHour(hour);
    setChoiceModal(null);
    return enqueuePreferenceWrite(() => setSmartReminderHour(hour)).catch(
      () => undefined,
    );
  };

  const selectChoice = (key: string) => {
    if (choiceModal === 'reminderTime') {
      void updateReminderHour(Number(key));
      return;
    }
    const normalizedQuality = normalizeStoredQuality(key);
    markPreferenceMutation('VIDEO_QUALITY');
    setQuality(normalizedQuality);
    void enqueuePreferenceWrite(async boundary => {
      await saveItem(
        await accountScopedStorageKey('VIDEO_QUALITY', boundary),
        normalizedQuality,
      );
      assertAccountSessionBoundary(boundary);
      if (hasAuthenticatedAccount) {
        await updatePlaybackPreferences({
          videoQualityPreference: normalizedQuality,
        });
        assertAccountSessionBoundary(boundary);
      }
    }).catch(() => undefined);
    setChoiceModal(null);
  };

  const confirmClearWatchHistory = () =>
    Alert.alert(
      'مسح سجل المشاهدة',
      'سنمسح آخر ما شاهدته فقط\nويبقى تقدمك وشهاداتك محفوظة',
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'مسح السجل',
          style: 'destructive',
          onPress: async () => {
            const boundary = await captureAccountSessionBoundary();
            await clearLocalWatchHistory();
            assertAccountSessionBoundary(boundary);
            let serverSynced = true;
            if (extractApiToken(userData)) {
              const pendingKey = await accountScopedStorageKey(
                PENDING_WATCH_HISTORY_CLEAR_KEY,
                boundary,
              );
              try {
                await clearWatchHistory();
                assertAccountSessionBoundary(boundary);
                await removeItem(pendingKey);
                assertAccountSessionBoundary(boundary);
              } catch {
                assertAccountSessionBoundary(boundary);
                serverSynced = false;
                const queued = await saveItem(pendingKey, true);
                assertAccountSessionBoundary(boundary);
                if (!queued) {
                  Alert.alert(
                    'لم يكتمل المسح',
                    'تعذّر حفظ طلب المسح على الجهاز\nحاول مرة أخرى عند عودة الاتصال',
                  );
                  return;
                }
              }
            }
            Alert.alert(
              'تم مسح السجل',
              serverSynced
                ? 'بقي تقدمك في الكورسات محفوظًا'
                : 'مسحناه من هذا الجهاز\nوسيكتمل من حسابك عند عودة الاتصال',
            );
          },
        },
      ],
    );

  return {
    choiceModal,
    closeChoiceModal: () => setChoiceModal(null),
    closeNotificationPrimer: () => setNotificationPrimer(false),
    confirmClearWatchHistory,
    confirmNotifications,
    marketingNotifications,
    notificationPrimer,
    notifications,
    openQualityChoice: () => setChoiceModal('quality'),
    openReminderChoice: () => setChoiceModal('reminderTime'),
    quality,
    reminderHour,
    selectChoice,
    toggleMarketing: (value: boolean) =>
      updatePreference(MARKETING_NOTIFICATIONS_KEY, value),
    toggleNotifications: updateNotifications,
    toggleWatchHistory: (value: boolean) =>
      updatePreference(WATCH_HISTORY_ENABLED_KEY, value),
    watchHistory,
  };
};
