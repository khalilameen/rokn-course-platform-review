import {useCallback, useRef, useState} from 'react';
import {getItem, saveItem} from '../../constants/helpers';
import {
  enableSmartReminders,
  getSmartRemindersEnabled,
  scheduleNextLearningReminder,
  setSmartRemindersEnabled,
} from '../../services/smartReminders';
import {hasSession, updateNotificationStatus} from '../../services/roknApi';

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
    void Promise.all([
      getSmartRemindersEnabled(),
      getItem('@rokn/reminders/nudge-seen/v1'),
    ]).then(([enabled, seen]) => {
      if (enabled !== true && !seen) setReminderNudgeVisible(true);
    });
  }, []);

  const closeReminderNudge = useCallback(() => {
    setReminderNudgeVisible(false);
    void saveItem('@rokn/reminders/nudge-seen/v1', true);
  }, []);

  const enableRemindersFromNudge = useCallback(async () => {
    if (enablingReminders) return false;
    setEnablingReminders(true);
    try {
      const granted = await enableSmartReminders();
      if (!granted) return false;
      await setSmartRemindersEnabled(true);
      if (await hasSession()) {
        await updateNotificationStatus(true).catch(() => undefined);
      }
      await scheduleNextLearningReminder({courseId, courseTitle});
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
