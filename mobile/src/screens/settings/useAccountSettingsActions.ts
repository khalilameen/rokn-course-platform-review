import {useEffect, useRef, useState} from 'react';
import {Alert, Platform} from 'react-native';
import type {AppDispatch} from '../../store/store';
import {deleteAccount} from '../../store/actions/auth';
import {LogOut} from '../../store/reducers/auth';
import {
  AsyncKeys,
  clearAccountScopedStorage,
  clearLegacyUnscopedPersonalStorage,
  extractApiToken,
  extractUserProfile,
  getCurrentAccountStorageScope,
  removeItem,
  rotateGuestStorageScope,
} from '../../constants/helpers';
import {
  cancelLearningReminders,
  setSmartRemindersEnabled,
} from '../../services/smartReminders';
import {
  clearCurrentPushDeviceRegistration,
  getCurrentPushDeviceToken,
} from '../../services/pushNotifications';
import {clearCurrentAccountLearningFiles} from '../../components/VideoPlayer/courseLearningApi';
import {openSupportWhatsApp} from '../../services/supportWhatsApp';
import {
  accountDeletionCredential,
  socialProviderForSession,
} from '../../services/accountDeletionReauth';
import {signInWithSocialProvider} from '../../services/socialAuth';
import {toArabicDigits} from '../../constants/arabicFormatting';
import {revokeCurrentDeviceSession} from '../../services/deviceSessions';
import {
  getPublicAppSettings,
  safeDashboardUrl,
} from '../../services/publicAppSettings';
import type {PublicAppSettings} from '../../services/publicAppSettings';
import {accountDeletionUrl} from './settingsData';
import type {SettingsNavigation} from './types';
import {configuredAppStoreUrl} from '../../services/publicLinks';
import {clearTransientChatCache} from '../../utils/fileCache';
import {clearPendingLoginReturnTo} from '../../navigation/authReturn';
import {openExternalUrlOnce} from '../../services/systemActions';
import {revokeReauthenticationSession} from '../../services/accountDeletion';

export const useAccountSettingsActions = ({
  dispatch,
  navigation,
  userData,
}: {
  dispatch: AppDispatch;
  navigation: SettingsNavigation;
  userData: unknown;
}) => {
  const [deletingAccount, setDeletingAccount] = useState(false);
  // React state is not synchronous: two alert callbacks can run before the
  // disabled state is painted. One boundary also prevents logout racing an
  // already-confirmed deletion and clearing its reauthentication state.
  const accountExitFlightRef = useRef<'delete' | 'logout' | null>(null);
  const [storeRatingAvailable, setStoreRatingAvailable] = useState(
    Boolean(configuredAppStoreUrl()),
  );

  useEffect(() => {
    let active = true;
    void getPublicAppSettings()
      .then(settings => {
        if (!active) return;
        const configuredUrl =
          Platform.OS === 'android'
            ? safeDashboardUrl(settings.android_app_url)
            : safeDashboardUrl(settings.ios_app_url) ||
              safeDashboardUrl(configuredAppStoreUrl());
        setStoreRatingAvailable(Boolean(configuredUrl));
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  const openWhatsAppSupport = async () => {
    try {
      await openSupportWhatsApp();
    } catch {
      navigation.navigate('Feedback', {sourceScreen: 'settings'});
    }
  };

  const openAccountDeletionPage = async () => {
    try {
      await openExternalUrlOnce(accountDeletionUrl);
    } catch {
      Alert.alert(
        'تعذّر فتح الصفحة',
        'اطلب حذف الحساب عبر الدعم',
      );
    }
  };

  const openStoreRating = async () => {
    try {
      const settings = await getPublicAppSettings().catch(
        (): PublicAppSettings => ({}),
      );
      if (Platform.OS === 'android') {
        const dashboardUrl = safeDashboardUrl(settings.android_app_url);
        await openExternalUrlOnce(
          dashboardUrl || 'market://details?id=com.rokn',
          'https://play.google.com/store/apps/details?id=com.rokn',
        );
        return;
      }
      const appStoreUrl =
        safeDashboardUrl(settings.ios_app_url) ||
        safeDashboardUrl(configuredAppStoreUrl());
      if (appStoreUrl) {
        await openExternalUrlOnce(appStoreUrl);
        return;
      }
      Alert.alert('تعذّر فتح التقييم', 'حاول مرة أخرى');
    } catch {
      if (Platform.OS === 'android') {
        try {
          await openExternalUrlOnce(
            'https://play.google.com/store/apps/details?id=com.rokn',
          );
          return;
        } catch {
          // Fall through to the same visible recovery as iOS.
        }
      }
      Alert.alert('تعذّر فتح التقييم', 'حاول مرة أخرى');
    }
  };

  const logout = () =>
    Alert.alert('تسجيل الخروج', 'سيخرج حسابك من هذا الجهاز فقط', [
      {text: 'إلغاء', style: 'cancel'},
      {
        text: 'تسجيل الخروج',
        style: 'destructive',
        onPress: async () => {
          if (accountExitFlightRef.current) return;
          accountExitFlightRef.current = 'logout';
          try {
            const accountScope = await getCurrentAccountStorageScope();
            cancelLearningReminders();
            await setSmartRemindersEnabled(false).catch(() => undefined);
            let serverSessionRevoked = !extractApiToken(userData);
            if (extractApiToken(userData)) {
              try {
                const deviceToken = await getCurrentPushDeviceToken();
                await revokeCurrentDeviceSession(deviceToken);
                serverSessionRevoked = true;
              } catch {
                // The local session still closes when the API is unavailable.
              }
            }
            const pushInvalidationDurable =
              await clearCurrentPushDeviceRegistration()
                .then(() => true)
                .catch(() => false);
            if (!serverSessionRevoked && !pushInvalidationDurable) {
              Alert.alert(
                'لم يكتمل تسجيل الخروج',
                'حاول مرة أخرى',
              );
              return;
            }
            // Secure credential deletion is the durable device-side logout
            // boundary. Do not reset the UI while a bearer or completed OAuth
            // receipt may still be recoverable on the next cold start.
            const secureSessionDeleted = await removeItem(AsyncKeys.USER_DATA);
            if (!secureSessionDeleted) {
              Alert.alert(
                'لم يكتمل تسجيل الخروج',
                'حاول مرة أخرى',
              );
              return;
            }
            await clearCurrentAccountLearningFiles(accountScope).catch(
              () => undefined,
            );
            await clearTransientChatCache({accountBoundary: true}).catch(
              () => undefined,
            );
            await clearLegacyUnscopedPersonalStorage().catch(() => undefined);
            await clearAccountScopedStorage(accountScope, {
              preserveFinancialRecovery: true,
            }).catch(() => undefined);
            await clearPendingLoginReturnTo().catch(() => undefined);
            await removeItem(AsyncKeys.IS_LOGIN);
            await rotateGuestStorageScope().catch(() => undefined);
            dispatch(LogOut());
            navigation.reset({index: 0, routes: [{name: 'Home'}]});
          } finally {
            accountExitFlightRef.current = null;
          }
        },
      },
    ]);

  const deleteCurrentAccount = async () => {
    if (deletingAccount || accountExitFlightRef.current) return;
    const token = extractApiToken(userData);
    if (!token) {
      Alert.alert('سجّل الدخول', 'أكد هويتك أولًا', [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'تسجيل الدخول',
          onPress: () =>
            navigation.navigate('Login', {
              returnTo: {name: 'Settings'},
            }),
        },
      ]);
      return;
    }

    accountExitFlightRef.current = 'delete';
    setDeletingAccount(true);
    let accountDeleted = false;
    let reauthenticationToken = '';
    try {
      const provider = socialProviderForSession(userData);
      if (!provider) throw new Error('ACCOUNT_REAUTH_PROVIDER_MISSING');
      const reauthenticatedSession = await signInWithSocialProvider(
        provider,
        undefined,
        {purpose: 'reauth'},
      );
      reauthenticationToken = reauthenticatedSession.api_token?.trim() || '';
      const reauthToken = accountDeletionCredential(
        userData,
        reauthenticatedSession,
      );
      const accountScope = await getCurrentAccountStorageScope();
      const deletion = await dispatch(deleteAccount({reauthToken})).unwrap();
      accountDeleted = true;
      reauthenticationToken = '';
      cancelLearningReminders();
      await setSmartRemindersEnabled(false).catch(() => undefined);
      // Once the server confirms deletion, no ancillary cache or notification
      // failure may leave the deleted identity active on this device.
      await clearCurrentPushDeviceRegistration().catch(() => undefined);
      const secureSessionDeleted = await removeItem(AsyncKeys.USER_DATA);
      if (!secureSessionDeleted) {
        throw new Error('LOCAL_SESSION_DELETE_FAILED');
      }
      await clearCurrentAccountLearningFiles(accountScope).catch(
        () => undefined,
      );
      await clearTransientChatCache({accountBoundary: true}).catch(
        () => undefined,
      );
      await clearLegacyUnscopedPersonalStorage().catch(() => undefined);
      await clearAccountScopedStorage(accountScope).catch(() => undefined);
      await clearPendingLoginReturnTo().catch(() => undefined);
      await removeItem(AsyncKeys.IS_LOGIN);
      await rotateGuestStorageScope().catch(() => undefined);
      dispatch(LogOut());
      navigation.reset({index: 0, routes: [{name: 'Home'}]});
      Alert.alert(
        deletion.cleanupPending ? 'تم إغلاق الحساب' : 'تم حذف الحساب',
        deletion.cleanupPending
          ? 'أغلقنا حسابك\nلا تحتاج إلى إجراء آخر'
          : 'حذفنا حسابك وبيانات ملفك',
      );
    } catch (error) {
      if (error instanceof Error && error.message === 'LOGIN_CANCELLED') return;
      if (reauthenticationToken) {
        await revokeReauthenticationSession(reauthenticationToken).catch(
          () => undefined,
        );
        reauthenticationToken = '';
      }
      if (accountDeleted) {
        dispatch(LogOut());
        navigation.reset({index: 0, routes: [{name: 'Home'}]});
        Alert.alert(
          'تم حذف الحساب',
          'أغلق التطبيق وافتحه من جديد',
        );
      } else {
        const mismatch =
          error instanceof Error &&
          error.message === 'ACCOUNT_REAUTH_IDENTITY_MISMATCH';
        Alert.alert(
          'تعذّر حذف الحساب',
          mismatch
            ? 'اختر حساب ركن نفسه\nثم حاول مرة أخرى'
            : 'أكد هويتك من جديد\nأو استخدم صفحة الحذف',
          [
            {text: 'إلغاء', style: 'cancel'},
            {text: 'صفحة الحذف', onPress: openAccountDeletionPage},
          ],
        );
      }
    } finally {
      accountExitFlightRef.current = null;
      setDeletingAccount(false);
    }
  };

  const confirmDelete = () =>
    Alert.alert(
      'حذف الحساب',
      (() => {
        const paidCoins = Math.max(
          0,
          Number(extractUserProfile(userData)?.wallet_purchased_coins || 0),
        );
        const balanceWarning =
          paidCoins > 0
            ? `\n\nلديك ${toArabicDigits(
                paidCoins,
              )} من الرصيد المدفوع\nراجع الدعم قبل الحذف إذا أردت استعادته`
            : '';
        return `سيُحذف ملفك وتقدمك ومحفوظاتك\nوستفقد الكورسات والعملات${balanceWarning}`;
      })(),
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'حذف الحساب',
          style: 'destructive',
          onPress: deleteCurrentAccount,
        },
      ],
    );

  return {
    confirmDelete,
    deletingAccount,
    logout,
    openAccountDeletionPage,
    openStoreRating,
    openWhatsAppSupport,
    storeRatingAvailable,
  };
};
