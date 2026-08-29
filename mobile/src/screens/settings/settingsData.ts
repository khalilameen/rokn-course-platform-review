import type {ComponentType} from 'react';
import type {SvgProps} from 'react-native-svg';
import {
  FullNameIcon,
  MoreBellIcon,
  MoreDeleteAccountIcon,
  MoreEditAccountIcon,
  MoreInfoIcon,
  MoreLanguageIcon,
  MoreLogoutIcon,
  MoreRateAppIcon,
  SettingsAutoplayIcon,
  SettingsClearHistoryIcon,
  SettingsDataRequestIcon,
  SettingsDevicesIcon,
  SettingsDisplayIcon,
  SettingsFeedbackIcon,
  SettingsHistoryIcon,
  SettingsLicensesIcon,
  SettingsMarketingIcon,
  SettingsPortfolioIcon,
  SettingsPrivacyIcon,
  SettingsQualityIcon,
  SettingsRefundIcon,
  SettingsReminderIcon,
  SettingsTermsIcon,
  SettingsVisibilityIcon,
  SupportWhatsAppIcon,
} from '../../assets/SVG';
import {toArabicDigits} from '../../constants/arabicFormatting';
import {mainUrl} from '../../constants/api';
import appConfig from '../../../app.json';

export const VIDEO_FIT_MODE_KEY = 'VIDEO_FIT_MODE';
export const PENDING_WATCH_HISTORY_CLEAR_KEY =
  '@rokn/pending-watch-history-clear/v1';
const publicWebBaseUrl = mainUrl.replace(/api(?:\/v1)?\/?$/i, '');
export const accountDeletionUrl =
  process.env.EXPO_PUBLIC_ACCOUNT_DELETION_URL?.trim() ||
  `${publicWebBaseUrl}account-deletion`;
export const returnsPolicyUrl = `${publicWebBaseUrl}returns-policy`;

export const settingsAppVersion = toArabicDigits(appConfig.expo.version);

export const qualityLabel = (value: string) => {
  if (value === 'auto' || value === 'تلقائي') return 'تلقائي';
  if (value === 'data_saver' || value === 'توفير البيانات') {
    return 'توفير البيانات';
  }
  return toArabicDigits(value);
};

export const fitLabel = (value: string) =>
  value === 'contain' ? 'الفيديو كامل' : 'ملء الشاشة';

export const reminderTimeLabel = (hour: number) => {
  if (hour === 10) return 'صباحًا · ١٠:٠٠';
  if (hour === 15) return 'بعد الظهر · ٣:٠٠';
  return 'مساءً · ٨:٠٠';
};

export type SettingRowModel = {
  id: string;
  title: string;
  subtitle?: string;
  value?: string;
  onPress?: () => void;
  destructive?: boolean;
  toggle?: {value: boolean; onChange: (value: boolean) => void};
  isLast?: boolean;
  icon: ComponentType<SvgProps>;
};

export type SettingsSectionModel = {
  id: 'account' | 'learning' | 'privacy' | 'about';
  title: string;
  rows: SettingRowModel[];
};

export type SettingsSectionsProps = {
  authenticated: boolean;
  autoplay: boolean;
  deletingAccount: boolean;
  marketingNotifications: boolean;
  notifications: boolean;
  portfolioPublic: boolean;
  quality: string;
  reminderHour: number;
  videoFit: string;
  watchHistory: boolean;
  onAbout: () => void;
  onClearWatchHistory: () => void;
  onDeleteAccount: () => void;
  onDevices: () => void;
  onEditAccount: () => void;
  onFeedback: () => void;
  onLogin: () => void;
  onOpenSourceLicenses: () => void;
  onLogout: () => void;
  onOpenAccountDeletion: () => void;
  onOpenFit: () => void;
  onOpenQuality: () => void;
  onOpenReminderTime: () => void;
  onPortfolio: () => void;
  onPrivacyPolicy: () => void;
  onRateApp: () => void;
  onReturnsPolicy: () => void;
  onSupport: () => void;
  onTermsOfUse: () => void;
  onToggleAutoplay: (value: boolean) => void;
  onToggleMarketing: (value: boolean) => void;
  onToggleNotifications: (value: boolean) => void;
  onTogglePortfolio: (value: boolean) => void;
  onToggleWatchHistory: (value: boolean) => void;
};

export const buildSettingsSections = (
  props: SettingsSectionsProps,
): SettingsSectionModel[] => {
  const accountRows: SettingRowModel[] = props.authenticated
    ? [
        {
          id: 'account.edit',
          icon: MoreEditAccountIcon,
          onPress: props.onEditAccount,
          title: 'بيانات الحساب',
        },
        {
          id: 'account.portfolio',
          icon: SettingsPortfolioIcon,
          onPress: props.onPortfolio,
          title: 'البورتفوليو',
        },
        {
          id: 'account.devices',
          icon: SettingsDevicesIcon,
          onPress: props.onDevices,
          subtitle: 'راجع الأجهزة وأنهِ أي جلسة لا تعرفها',
          title: 'الأجهزة المسجّل عليها',
        },
        {
          id: 'account.visibility',
          icon: SettingsVisibilityIcon,
          subtitle: 'لن يظهر للناس إلا باختيارك',
          title: 'إظهار البورتفوليو للعامة',
          toggle: {
            value: props.portfolioPublic,
            onChange: props.onTogglePortfolio,
          },
        },
        {
          id: 'account.logout',
          icon: MoreLogoutIcon,
          onPress: props.onLogout,
          title: 'تسجيل الخروج',
        },
        {
          id: 'account.delete',
          destructive: true,
          icon: MoreDeleteAccountIcon,
          isLast: true,
          onPress: props.onDeleteAccount,
          subtitle: 'حذف نهائي وليس تسجيل خروج',
          title: props.deletingAccount ? 'جارٍ حذف الحساب…' : 'حذف الحساب',
        },
      ]
    : [
        {
          id: 'account.login',
          icon: FullNameIcon,
          isLast: true,
          onPress: props.onLogin,
          subtitle: 'لحفظ تقدمك ومحفوظاتك والرجوع إليها في أي وقت',
          title: 'تسجيل الدخول',
        },
      ];

  const learningRows: SettingRowModel[] = [
    {
      id: 'learning.notifications',
      icon: MoreBellIcon,
      title: 'إشعارات التعلّم',
      toggle: {
        value: props.notifications,
        onChange: props.onToggleNotifications,
      },
    },
    ...(props.notifications
      ? [
          {
            id: 'learning.reminder-time',
            icon: SettingsReminderIcon,
            onPress: props.onOpenReminderTime,
            title: 'وقت التذكير',
            value: reminderTimeLabel(props.reminderHour),
          } satisfies SettingRowModel,
        ]
      : []),
    {
      id: 'learning.autoplay',
      icon: SettingsAutoplayIcon,
      title: 'تشغيل الخطوة التالية تلقائيًا',
      toggle: {value: props.autoplay, onChange: props.onToggleAutoplay},
    },
    {
      id: 'learning.quality',
      icon: SettingsQualityIcon,
      onPress: props.onOpenQuality,
      title: 'جودة الفيديو',
      value: qualityLabel(props.quality),
    },
    {
      id: 'learning.display',
      icon: SettingsDisplayIcon,
      onPress: props.onOpenFit,
      title: 'عرض الفيديو',
      value: fitLabel(props.videoFit),
    },
    {
      id: 'learning.history',
      icon: SettingsHistoryIcon,
      subtitle: 'يحفظ آخر موضع شاهدته لتكمل من مكانك',
      title: 'سجل المشاهدة',
      toggle: {value: props.watchHistory, onChange: props.onToggleWatchHistory},
    },
    {
      id: 'learning.clear-history',
      destructive: true,
      icon: SettingsClearHistoryIcon,
      isLast: true,
      onPress: props.onClearWatchHistory,
      subtitle: 'لا يمس تقدم الكورسات أو الشهادات',
      title: 'مسح سجل المشاهدة',
    },
  ];

  const privacyRows: SettingRowModel[] = [
    {
      id: 'privacy.marketing',
      icon: SettingsMarketingIcon,
      subtitle: 'اختياري ويمكنك إيقافه في أي وقت',
      title: 'العروض والأخبار',
      toggle: {
        value: props.marketingNotifications,
        onChange: props.onToggleMarketing,
      },
    },
    {
      id: 'privacy.policy',
      icon: SettingsPrivacyIcon,
      onPress: props.onPrivacyPolicy,
      title: 'سياسة الخصوصية',
    },
    {
      id: 'privacy.terms',
      icon: SettingsTermsIcon,
      onPress: props.onTermsOfUse,
      title: 'شروط الاستخدام',
    },
    {
      id: 'privacy.refunds',
      icon: SettingsRefundIcon,
      onPress: props.onReturnsPolicy,
      title: 'سياسة الاسترداد',
    },
    {
      id: 'privacy.data-request',
      icon: SettingsDataRequestIcon,
      onPress: props.onOpenAccountDeletion,
      subtitle: 'متاحة حتى لو لم تستطع تسجيل الدخول',
      title: 'طلب حذف الحساب أو البيانات',
    },
    {
      id: 'privacy.feedback',
      icon: SettingsFeedbackIcon,
      onPress: props.onFeedback,
      title: 'بلّغنا عن مشكلة أو اقترح',
    },
    {
      id: 'privacy.support',
      icon: SupportWhatsAppIcon,
      onPress: props.onSupport,
      title: 'تواصل معنا',
    },
  ];

  const aboutRows: SettingRowModel[] = [
    {
      id: 'about.language',
      icon: MoreLanguageIcon,
      title: 'اللغة',
      value: 'العربية',
    },
    {
      id: 'about.rate',
      icon: MoreRateAppIcon,
      onPress: props.onRateApp,
      title: 'قيّم ركن',
    },
    {
      id: 'about.open-source',
      icon: SettingsLicensesIcon,
      onPress: props.onOpenSourceLicenses,
      title: 'المكتبات مفتوحة المصدر',
    },
    {
      id: 'about.info',
      icon: MoreInfoIcon,
      isLast: true,
      onPress: props.onAbout,
      title: 'عن ركن',
      value: settingsAppVersion,
    },
  ];

  return [
    {id: 'account', title: 'الحساب', rows: accountRows},
    {id: 'learning', title: 'التعلّم والمشاهدة', rows: learningRows},
    {id: 'privacy', title: 'الخصوصية والتواصل', rows: privacyRows},
    {id: 'about', title: 'عن التطبيق', rows: aboutRows},
  ];
};
