import {
  buildSettingsSections,
  type SettingsSectionsProps,
} from '../src/screens/settings/settingsData';

const callback = jest.fn;

const createProps = (
  overrides: Partial<SettingsSectionsProps> = {},
): SettingsSectionsProps => ({
  authenticated: true,
  autoplay: true,
  deletingAccount: false,
  marketingNotifications: false,
  notifications: true,
  portfolioPublic: false,
  quality: 'auto',
  reminderHour: 20,
  videoFit: 'cover',
  watchHistory: true,
  onAbout: callback(),
  onClearWatchHistory: callback(),
  onDeleteAccount: callback(),
  onDevices: callback(),
  onEditAccount: callback(),
  onFeedback: callback(),
  onLogin: callback(),
  onLogout: callback(),
  onOpenSourceLicenses: callback(),
  onOpenAccountDeletion: callback(),
  onOpenFit: callback(),
  onOpenQuality: callback(),
  onOpenReminderTime: callback(),
  onPortfolio: callback(),
  onPrivacyPolicy: callback(),
  onRateApp: callback(),
  onReturnsPolicy: callback(),
  onSupport: callback(),
  onTermsOfUse: callback(),
  onToggleAutoplay: callback(),
  onToggleMarketing: callback(),
  onToggleNotifications: callback(),
  onTogglePortfolio: callback(),
  onToggleWatchHistory: callback(),
  ...overrides,
});

const authenticatedRows = [
  'account.edit',
  'account.portfolio',
  'account.devices',
  'account.visibility',
  'account.logout',
  'account.delete',
  'learning.notifications',
  'learning.reminder-time',
  'learning.autoplay',
  'learning.quality',
  'learning.display',
  'learning.history',
  'learning.clear-history',
  'privacy.marketing',
  'privacy.policy',
  'privacy.terms',
  'privacy.refunds',
  'privacy.data-request',
  'privacy.feedback',
  'privacy.support',
  'about.language',
  'about.rate',
  'about.open-source',
  'about.info',
];

const guestRows = [
  'account.login',
  'learning.notifications',
  'learning.autoplay',
  'learning.quality',
  'learning.display',
  'learning.history',
  'learning.clear-history',
  'privacy.marketing',
  'privacy.policy',
  'privacy.terms',
  'privacy.refunds',
  'privacy.data-request',
  'privacy.feedback',
  'privacy.support',
  'about.language',
  'about.rate',
  'about.open-source',
  'about.info',
];

const flattenRows = (props: SettingsSectionsProps) =>
  buildSettingsSections(props).flatMap(section => section.rows);

describe('settings screen contract', () => {
  it('keeps every authenticated setting in its established order', () => {
    const rows = flattenRows(createProps());
    expect(rows.map(row => row.id)).toEqual(authenticatedRows);
    expect(new Set(rows.map(row => row.id)).size).toBe(rows.length);
  });

  it('keeps the guest settings and only hides the disabled reminder row', () => {
    const rows = flattenRows(
      createProps({authenticated: false, notifications: false}),
    );
    expect(rows.map(row => row.id)).toEqual(guestRows);
    expect(new Set(rows.map(row => row.id)).size).toBe(rows.length);
  });

  it('assigns a distinct icon and an interaction contract to all 25 rows', () => {
    const authenticated = flattenRows(createProps());
    const guest = flattenRows(
      createProps({authenticated: false, notifications: false}),
    );
    const rowsById = new Map(
      [...authenticated, ...guest].map(row => [row.id, row]),
    );

    expect(rowsById.size).toBe(25);
    expect(new Set([...rowsById.values()].map(row => row.icon)).size).toBe(25);

    for (const row of rowsById.values()) {
      if (row.id === 'about.language') {
        expect(row.value).toBe('العربية');
      } else {
        expect(Boolean(row.onPress || row.toggle)).toBe(true);
      }
    }
  });
});
