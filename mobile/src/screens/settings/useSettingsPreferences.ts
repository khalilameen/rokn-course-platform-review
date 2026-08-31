import {useEffect, useState} from 'react';
import {Alert} from 'react-native';
import {
  accountScopedStorageKey,
  extractApiToken,
  getItem,
  removeItem,
  saveItem,
} from '../../constants/helpers';
import {
  cancelLearningReminders,
  enableSmartReminders,
  getSmartRemindersEnabled,
  REMINDER_ENABLED_KEY,
  REMINDER_HOUR_KEY,
  scheduleNextLearningReminder,
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

  useEffect(() => {
    void Promise.all([
      getSmartRemindersEnabled(),
      getItem('VIDEO_QUALITY'),
      getItem(REMINDER_HOUR_KEY),
      accountScopedStorageKey(WATCH_HISTORY_ENABLED_KEY).then(getItem),
      accountScopedStorageKey(MARKETING_NOTIFICATIONS_KEY).then(getItem),
    ]).then(
      async ([
        savedNotifications,
        savedQuality,
        savedReminderHour,
        savedWatchHistory,
        savedMarketingNotifications,
      ]) => {
        if (typeof savedNotifications === 'boolean') {
          setNotifications(savedNotifications);
        }
        if (typeof savedQuality === 'string') setQuality(savedQuality);
        if (typeof savedWatchHistory === 'boolean') {
          setWatchHistory(savedWatchHistory);
        }
        if (typeof savedMarketingNotifications === 'boolean') {
          setMarketingNotifications(savedMarketingNotifications);
        }
        if ([10, 15, 20].includes(Number(savedReminderHour))) {
          setReminderHour(Number(savedReminderHour));
        }
        if (hasAuthenticatedAccount) {
          const pending = await readPendingPrivacyPreferences();
          if (typeof pending.watchHistoryEnabled === 'boolean') {
            privacyDirtyKeys.add(WATCH_HISTORY_ENABLED_KEY);
            setWatchHistory(pending.watchHistoryEnabled);
            await saveItem(
              WATCH_HISTORY_ENABLED_KEY,
              pending.watchHistoryEnabled,
            );
          }
          if (typeof pending.marketingNotificationsEnabled === 'boolean') {
            privacyDirtyKeys.add(MARKETING_NOTIFICATIONS_KEY);
            setMarketingNotifications(pending.marketingNotificationsEnabled);
            await saveItem(
              MARKETING_NOTIFICATIONS_KEY,
              pending.marketingNotificationsEnabled,
            );
          }
          if (Object.keys(pending).length) {
            await queuePrivacyPreferenceSync();
          }
          try {
            const profile = await getProfile();
            if (!privacyDirtyKeys.has(WATCH_HISTORY_ENABLED_KEY)) {
              setWatchHistory(profile.watchHistoryEnabled);
              await saveItem(
                await accountScopedStorageKey(WATCH_HISTORY_ENABLED_KEY),
                profile.watchHistoryEnabled,
              );
            }
            if (!privacyDirtyKeys.has(MARKETING_NOTIFICATIONS_KEY)) {
              setMarketingNotifications(profile.marketingNotificationsEnabled);
              await saveItem(
                await accountScopedStorageKey(MARKETING_NOTIFICATIONS_KEY),
                profile.marketingNotificationsEnabled,
              );
            }
            setQuality(profile.videoQualityPreference);
            await Promise.all([
              saveItem('VIDEO_QUALITY', profile.videoQualityPreference),
              saveItem('VIDEO_PLAYBACK_SPEED', profile.playbackSpeed),
            ]);
          } catch {
            // Settings remain readable without replacing server values.
          }
        }
      },
    );
  }, [hasAuthenticatedAccount, privacyDirtyKeys, queuePrivacyPreferenceSync]);

  useEffect(() => {
    if (!hasAuthenticatedAccount) return;
    void accountScopedStorageKey(PENDING_WATCH_HISTORY_CLEAR_KEY)
      .then(async key => {
        if (!(await getItem(key))) return;
        await clearWatchHistory();
        await removeItem(key);
      })
      .catch(() => undefined);
  }, [hasAuthenticatedAccount]);

  const updatePreference = async (key: string, value: boolean) => {
    if (
      key === WATCH_HISTORY_ENABLED_KEY ||
      key === MARKETING_NOTIFICATIONS_KEY
    ) {
      privacyDirtyKeys.add(key);
    }
    if (key === REMINDER_ENABLED_KEY) {
      await setSmartRemindersEnabled(value);
    } else if (
      key === WATCH_HISTORY_ENABLED_KEY ||
      key === MARKETING_NOTIFICATIONS_KEY
    ) {
      await saveItem(await accountScopedStorageKey(key), value);
    } else {
      await saveItem(key, value);
    }
    if (key === REMINDER_ENABLED_KEY) setNotifications(value);
    if (key === WATCH_HISTORY_ENABLED_KEY) setWatchHistory(value);
    if (key === MARKETING_NOTIFICATIONS_KEY) {
      setMarketingNotifications(value);
    }

    if (key === REMINDER_ENABLED_KEY && extractApiToken(userData)) {
      try {
        await updateNotificationStatus(value);
      } catch {
        // The local reminder remains active until the next server sync.
      }
    }
    if (hasAuthenticatedAccount && key === WATCH_HISTORY_ENABLED_KEY) {
      await queuePrivacyPreferenceSync({watchHistoryEnabled: value});
    }
    if (hasAuthenticatedAccount && key === MARKETING_NOTIFICATIONS_KEY) {
      await queuePrivacyPreferenceSync({
        marketingNotificationsEnabled: value,
      });
    }
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
    const granted = await enableSmartReminders();
    if (!granted) return false;
    await updatePreference(REMINDER_ENABLED_KEY, true);
    await registerPushDeviceIfEligible({requestPermission: false}).catch(
      () => false,
    );
    await scheduleNextLearningReminder({
      streakDays: 0,
      preferredHour: reminderHour,
    });
    return true;
  };

  const updateReminderHour = async (hour: number) => {
    setReminderHour(hour);
    await saveItem(REMINDER_HOUR_KEY, hour);
    if (notifications) {
      await scheduleNextLearningReminder({streakDays: 0, preferredHour: hour});
    }
    setChoiceModal(null);
  };

  const selectChoice = (key: string) => {
    if (choiceModal === 'reminderTime') {
      void updateReminderHour(Number(key));
      return;
    }
    setQuality(key);
    void saveItem('VIDEO_QUALITY', key);
    if (hasAuthenticatedAccount) {
      void updatePlaybackPreferences({videoQualityPreference: key}).catch(
        () => undefined,
      );
    }
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
            await clearLocalWatchHistory();
            let serverSynced = true;
            if (extractApiToken(userData)) {
              const pendingKey = await accountScopedStorageKey(
                PENDING_WATCH_HISTORY_CLEAR_KEY,
              );
              try {
                await clearWatchHistory();
                await removeItem(pendingKey);
              } catch {
                serverSynced = false;
                await saveItem(pendingKey, true);
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
