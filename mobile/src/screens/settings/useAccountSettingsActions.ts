import {useState} from 'react';
import {Alert, Linking, Platform} from 'react-native';
import type {AppDispatch} from '../../store/store';
import {deleteAccount} from '../../store/actions/auth';
import {LogOut} from '../../store/reducers/auth';
import {
  AsyncKeys,
  clearAccountScopedStorage,
  extractApiToken,
  extractUserProfile,
  getCurrentAccountStorageScope,
  removeItem,
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

  const openWhatsAppSupport = async () => {
    try {
      await openSupportWhatsApp();
    } catch {
      Alert.alert(
        'تعذّر فتح واتساب',
        'تحقق من الاتصال\nأو أرسل بلاغًا من داخل ركن',
      );
    }
  };

  const openAccountDeletionPage = async () => {
    try {
      await Linking.openURL(accountDeletionUrl);
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
        await Linking.openURL(
          dashboardUrl || 'market://details?id=com.rokn',
        );
        return;
      }
      const appStoreUrl =
        safeDashboardUrl(settings.ios_app_url) ||
        safeDashboardUrl(configuredAppStoreUrl());
      if (appStoreUrl) {
        await Linking.openURL(appStoreUrl);
        return;
      }
      Alert.alert('تعذّر فتح التقييم', 'حاول مرة أخرى');
    } catch {
      if (Platform.OS === 'android') {
        await Linking.openURL(
          'https://play.google.com/store/apps/details?id=com.rokn',
        );
      }
    }
  };

  const logout = () =>
    Alert.alert('تسجيل الخروج', 'سيخرج حسابك من هذا الجهاز فقط', [
      {text: 'إلغاء', style: 'cancel'},
      {
        text: 'تسجيل الخروج',
        style: 'destructive',
        onPress: async () => {
          const accountScope = await getCurrentAccountStorageScope();
          cancelLearningReminders();
          await setSmartRemindersEnabled(false);
          if (extractApiToken(userData)) {
            try {
              const deviceToken = await getCurrentPushDeviceToken();
              await revokeCurrentDeviceSession(deviceToken);
            } catch {
              // The local session still closes when the API is unavailable.
            }
          }
          await clearCurrentPushDeviceRegistration().catch(() => undefined);
          await clearCurrentAccountLearningFiles(accountScope).catch(
            () => undefined,
          );
          await clearAccountScopedStorage(accountScope).catch(() => undefined);
          await removeItem(AsyncKeys.IS_LOGIN);
          await removeItem(AsyncKeys.USER_DATA);
          dispatch(LogOut());
          navigation.reset({index: 0, routes: [{name: 'Home'}]});
        },
      },
    ]);

  const deleteCurrentAccount = async () => {
    if (deletingAccount) return;
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

    setDeletingAccount(true);
    let accountDeleted = false;
    try {
      const provider = socialProviderForSession(userData);
      if (!provider) throw new Error('ACCOUNT_REAUTH_PROVIDER_MISSING');
      const reauthenticatedSession = await signInWithSocialProvider(provider);
      const reauthToken = accountDeletionCredential(
        userData,
        reauthenticatedSession,
      );
      const accountScope = await getCurrentAccountStorageScope();
      const deletion = await dispatch(deleteAccount({reauthToken})).unwrap();
      accountDeleted = true;
      cancelLearningReminders();
      await setSmartRemindersEnabled(false);
      await removeItem(AsyncKeys.USER_DATA);
      await clearAccountScopedStorage(accountScope);
      await removeItem(AsyncKeys.IS_LOGIN);
      dispatch(LogOut());
      navigation.reset({index: 0, routes: [{name: 'Home'}]});
      Alert.alert(
        deletion.cleanupPending ? 'تم إغلاق الحساب' : 'تم حذف الحساب',
        deletion.cleanupPending
          ? 'أغلقنا حسابك\nسيكتمل حذف النسخ الاحتياطية لاحقًا'
          : 'حذفنا حسابك وبيانات ملفك',
      );
    } catch (error) {
      if (error instanceof Error && error.message === 'LOGIN_CANCELLED') return;
      if (accountDeleted) {
        dispatch(LogOut());
        navigation.reset({index: 0, routes: [{name: 'Home'}]});
        Alert.alert(
          'تم حذف الحساب',
          'امسح بيانات التطبيق لإزالة النسخة المحلية',
        );
      } else {
        const mismatch =
          error instanceof Error &&
          error.message === 'ACCOUNT_REAUTH_IDENTITY_MISMATCH';
        Alert.alert(
          'تعذر حذف الحساب',
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
  };
};
