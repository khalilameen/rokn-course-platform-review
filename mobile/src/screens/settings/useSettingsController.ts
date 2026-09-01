import {useNavigation} from '@react-navigation/native';
import {useDispatch, useSelector} from 'react-redux';
import {extractApiToken} from '../../constants/helpers';
import type {AppDispatch, RootState} from '../../store/store';
import type {SettingsSectionsProps} from './settingsData';
import type {SettingsNavigation} from './types';
import {useAccountSettingsActions} from './useAccountSettingsActions';
import {useSettingsPreferences} from './useSettingsPreferences';

export const useSettingsController = () => {
  const navigation = useNavigation<SettingsNavigation>();
  const dispatch = useDispatch<AppDispatch>();
  const userData = useSelector((state: RootState) => state.auth.userData);
  const authenticated = Boolean(extractApiToken(userData));
  const preferences = useSettingsPreferences({
    hasAuthenticatedAccount: authenticated,
    userData,
  });
  const account = useAccountSettingsActions({dispatch, navigation, userData});

  const sectionsProps: SettingsSectionsProps = {
    authenticated,
    canRateApp: account.storeRatingAvailable,
    deletingAccount: account.deletingAccount,
    marketingNotifications: preferences.marketingNotifications,
    notifications: preferences.notifications,
    quality: preferences.quality,
    reminderHour: preferences.reminderHour,
    watchHistory: preferences.watchHistory,
    onAbout: () => navigation.navigate('AboutUs'),
    onClearWatchHistory: preferences.confirmClearWatchHistory,
    onDeleteAccount: account.confirmDelete,
    onDevices: () => navigation.navigate('DeviceSessions'),
    onEditAccount: () => navigation.navigate('EditAccount'),
    onFeedback: () =>
      navigation.navigate('Feedback', {sourceScreen: 'settings'}),
    onLogin: () => navigation.navigate('Login', {returnTo: {name: 'Settings'}}),
    onLogout: account.logout,
    onOpenQuality: preferences.openQualityChoice,
    onOpenReminderTime: preferences.openReminderChoice,
    onPortfolio: () => navigation.navigate('Profile'),
    onPrivacyPolicy: () => navigation.navigate('PrivacyPolicy'),
    onRateApp: account.openStoreRating,
    onSupport: account.openWhatsAppSupport,
    onTermsOfUse: () => navigation.navigate('TermsOfUse'),
    onToggleMarketing: preferences.toggleMarketing,
    onToggleNotifications: preferences.toggleNotifications,
    onToggleWatchHistory: preferences.toggleWatchHistory,
  };

  return {
    choiceModal: preferences.choiceModal,
    closeChoiceModal: preferences.closeChoiceModal,
    closeNotificationPrimer: preferences.closeNotificationPrimer,
    confirmNotifications: preferences.confirmNotifications,
    notificationPrimer: preferences.notificationPrimer,
    quality: preferences.quality,
    reminderHour: preferences.reminderHour,
    sectionsProps,
    selectChoice: preferences.selectChoice,
  };
};
