import AsyncStorage from '@react-native-async-storage/async-storage';
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
import {accountDeletionUrl, returnsPolicyUrl} from './settingsData';
import type {SettingsNavigation} from './types';

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
        'لم نتمكن من تحميل رقم الدعم. تأكد من الاتصال ثم حاول مرة أخرى.',
      );
    }
  };

  const openAccountDeletionPage = async () => {
    try {
      await Linking.openURL(accountDeletionUrl);
    } catch {
      Alert.alert(
        'تعذّر فتح الصفحة',
        'تواصل معنا عبر واتساب وسنساعدك في طلب حذف الحساب أو البيانات.',
      );
    }
  };

  const openReturnsPolicy = () => {
    void Linking.openURL(returnsPolicyUrl);
  };

  const openStoreRating = async () => {
    try {
      if (Platform.OS === 'android') {
        await Linking.openURL('market://details?id=com.rokn');
        return;
      }
      const appStoreUrl = process.env.EXPO_PUBLIC_APP_STORE_URL?.trim();
      if (appStoreUrl) {
        await Linking.openURL(appStoreUrl);
        return;
      }
      Alert.alert('تعذّر فتح التقييم', 'جرّب مرة أخرى بعد قليل.');
    } catch {
      if (Platform.OS === 'android') {
        await Linking.openURL(
          'https://play.google.com/store/apps/details?id=com.rokn',
        );
      }
    }
  };

  const logout = () =>
    Alert.alert('تسجيل الخروج', 'هل تريد تسجيل الخروج من هذا الجهاز؟', [
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
      Alert.alert('سجّل دخولك أولًا', 'نحتاج نتأكد أن الحساب لك قبل حذفه.', [
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
      const keys = await AsyncStorage.getAllKeys();
      const accountKeys = keys.filter(
        key =>
          key === AsyncKeys.IS_LOGIN ||
          key === 'persist:auth' ||
          key.endsWith(`:${accountScope}`) ||
          key.includes(`:${accountScope}:`),
      );
      if (accountKeys.length) await AsyncStorage.multiRemove(accountKeys);
      dispatch(LogOut());
      navigation.reset({index: 0, routes: [{name: 'Home'}]});
      Alert.alert(
        deletion.cleanupPending ? 'تم إغلاق الحساب' : 'تم حذف الحساب',
        deletion.message ||
          'تم إغلاق الحساب وإزالة بياناته الشخصية. قد تبقى سجلات محدودة يفرض القانون الاحتفاظ بها، مثل مراجع المعاملات.',
      );
    } catch (error) {
      if (error instanceof Error && error.message === 'LOGIN_CANCELLED') return;
      if (accountDeleted) {
        dispatch(LogOut());
        navigation.reset({index: 0, routes: [{name: 'Home'}]});
        Alert.alert(
          'تم حذف الحساب',
          'حُذف الحساب من ركن، لكن تعذر تنظيف بعض البيانات المحلية. ستختفي بعد حذف بيانات التطبيق من إعدادات الهاتف.',
        );
      } else {
        const mismatch =
          error instanceof Error &&
          error.message === 'ACCOUNT_REAUTH_IDENTITY_MISMATCH';
        Alert.alert(
          'تعذر حذف الحساب',
          mismatch
            ? 'الحساب الذي اخترته مختلف عن حساب ركن الحالي. أعد المحاولة بنفس طريقة وحساب تسجيل الدخول.'
            : 'لم يُحذف الحساب. أكد هويتك بنفس حساب تسجيل الدخول ثم حاول مرة أخرى، أو استخدم صفحة طلب الحذف.',
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
              )} من رصيدك المدفوع. تواصل معنا قبل الحذف إذا أردت مراجعته.`
            : '';
        return `سيُغلق حسابك وتُحذف بيانات ملفك والمحفوظات والبورتفوليو. ستفقد الوصول إلى الكورسات والعملات، وقد نحتفظ فقط بسجلات المعاملات أو مكافحة الاحتيال التي يفرضها القانون.${balanceWarning}`;
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
    openReturnsPolicy,
    openStoreRating,
    openWhatsAppSupport,
  };
};
