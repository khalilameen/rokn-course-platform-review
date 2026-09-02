import {useCallback, useRef, useState} from 'react';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getItem,
  saveItem,
} from '../../constants/helpers';
import {
  enableSmartReminders,
  getSmartRemindersEnabled,
  scheduleNextLearningReminder,
  setSmartRemindersEnabled,
} from '../../services/smartReminders';
import {hasSession, updateNotificationStatus} from '../../services/roknApi';
import {registerPushDeviceIfEligible} from '../../services/pushNotifications';

export const useReminderNudge = ({
  courseId,
  courseTitle,
}: {
  courseId?: string;
  courseTitle?: string;
}) => {
  const reminderNudgeShownRef = useRef(false);
  const [reminderNudgeVisible, setReminderNudgeVisible] = useState(false);
  const [enablingReminders, setEnablingReminders] = useState(false);

  const maybeOfferReminders = useCallback(() => {
    if (reminderNudgeShownRef.current) return;
    reminderNudgeShownRef.current = true;
    void captureAccountSessionBoundary()
      .then(async boundary => {
        const [enabled, seen] = await Promise.all([
          getSmartRemindersEnabled(),
          accountScopedStorageKey(
            '@rokn/reminders/nudge-seen/v1',
            boundary,
          ).then(getItem),
        ]);
        assertAccountSessionBoundary(boundary);
        if (enabled !== true && !seen) setReminderNudgeVisible(true);
      })
      .catch(() => undefined);
  }, []);

  const closeReminderNudge = useCallback(() => {
    setReminderNudgeVisible(false);
    void captureAccountSessionBoundary()
      .then(async boundary => {
        await saveItem(
          await accountScopedStorageKey(
            '@rokn/reminders/nudge-seen/v1',
            boundary,
          ),
          true,
        );
        assertAccountSessionBoundary(boundary);
      })
      .catch(() => undefined);
  }, []);

  const enableRemindersFromNudge = useCallback(async () => {
    if (enablingReminders) return false;
    const boundary = await captureAccountSessionBoundary();
    setEnablingReminders(true);
    try {
      const granted = await enableSmartReminders();
      assertAccountSessionBoundary(boundary);
      if (!granted) return false;
      await setSmartRemindersEnabled(true);
      assertAccountSessionBoundary(boundary);
      if (await hasSession()) {
        assertAccountSessionBoundary(boundary);
        await updateNotificationStatus(true).catch(() => undefined);
        assertAccountSessionBoundary(boundary);
        await registerPushDeviceIfEligible({requestPermission: false}).catch(
          () => false,
        );
        assertAccountSessionBoundary(boundary);
      }
      await scheduleNextLearningReminder({courseId, courseTitle});
      assertAccountSessionBoundary(boundary);
      return true;
    } finally {
      setEnablingReminders(false);
    }
  }, [courseId, courseTitle, enablingReminders]);

  return {
    closeReminderNudge,
    enableRemindersFromNudge,
    maybeOfferReminders,
    reminderNudgeVisible,
  };
};
