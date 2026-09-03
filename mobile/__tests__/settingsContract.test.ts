import {
  accountDeletionUrl,
  buildSettingsSections,
  type SettingsSectionsProps,
} from '../src/screens/settings/settingsData';
import {mainUrl} from '../src/constants/api';
import {returnsPolicyUrl} from '../src/services/publicLinks';

const callback = jest.fn;

const createProps = (
  overrides: Partial<SettingsSectionsProps> = {},
): SettingsSectionsProps => ({
  authenticated: true,
  deletingAccount: false,
  marketingNotifications: false,
  notifications: true,
  quality: 'auto',
  reminderHour: 20,
  watchHistory: true,
  onAbout: callback(),
  onClearWatchHistory: callback(),
  onDeleteAccount: callback(),
  onDevices: callback(),
  onEditAccount: callback(),
  onFeedback: callback(),
  onLogin: callback(),
  onLogout: callback(),
  onOpenQuality: callback(),
  onOpenReminderTime: callback(),
  onPortfolio: callback(),
  onPrivacyPolicy: callback(),
  onRateApp: callback(),
  onTermsOfUse: callback(),
  onToggleMarketing: callback(),
  onToggleNotifications: callback(),
  onToggleWatchHistory: callback(),
  ...overrides,
});

const authenticatedRows = [
  'account.edit',
  'account.portfolio',
  'account.devices',
  'account.logout',
  'account.delete',
  'learning.notifications',
  'learning.reminder-time',
  'learning.quality',
  'learning.history',
  'learning.clear-history',
  'privacy.marketing',
  'privacy.policy',
  'privacy.terms',
  'privacy.support',
  'about.rate',
  'about.info',
];

const guestRows = [
  'account.login',
  'learning.notifications',
  'learning.quality',
  'learning.history',
  'learning.clear-history',
  'privacy.policy',
  'privacy.terms',
  'privacy.support',
  'about.rate',
  'about.info',
];

const flattenRows = (props: SettingsSectionsProps) =>
  buildSettingsSections(props).flatMap(section => section.rows);

describe('settings screen contract', () => {
  it('opens public legal pages outside both legacy and versioned API prefixes', () => {
    const expectedOrigin = new URL(mainUrl).origin;
    const accountDeletion = new URL(accountDeletionUrl);
    const returnsPolicy = new URL(returnsPolicyUrl);
    expect(accountDeletion.origin).toBe(expectedOrigin);
    expect(accountDeletion.pathname).toBe('/account-deletion');
    expect(returnsPolicy.origin).toBe(expectedOrigin);
    expect(returnsPolicy.pathname).toBe('/returns-policy');
    expect(accountDeletion.search).toBe('');
    expect(accountDeletion.hash).toBe('');
    expect(returnsPolicy.search).toBe('');
    expect(returnsPolicy.hash).toBe('');
  });

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

  it('assigns a distinct icon and an interaction contract to all visible rows', () => {
    const authenticated = flattenRows(createProps());
    const guest = flattenRows(
      createProps({authenticated: false, notifications: false}),
    );
    const rowsById = new Map(
      [...authenticated, ...guest].map(row => [row.id, row]),
    );

    expect(rowsById.size).toBe(17);
    expect(new Set([...rowsById.values()].map(row => row.icon)).size).toBe(17);
    expect(rowsById.has('learning.display')).toBe(false);
    expect(rowsById.has('about.open-source')).toBe(false);
    expect(rowsById.has('privacy.refunds')).toBe(false);
    expect(rowsById.has('learning.autoplay')).toBe(false);

    for (const row of rowsById.values()) {
      expect(Boolean(row.onPress || row.toggle)).toBe(true);
    }
  });

  it('uses one contact entry for problems and suggestions', () => {
    const onFeedback = jest.fn();
    const rows = flattenRows(createProps({onFeedback}));
    const contact = rows.filter(row => row.id === 'privacy.support');

    expect(contact).toHaveLength(1);
    expect(rows.some(row => row.id === 'privacy.feedback')).toBe(false);
    expect(contact[0].title).toBe('تواصل معنا');
    contact[0].onPress?.();
    expect(onFeedback).toHaveBeenCalledTimes(1);
  });
});
