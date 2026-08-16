import React from 'react';
import ReactTestRenderer from 'react-test-renderer';
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
} from '../src/assets/SVG';

const settingsIcons = {
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
};

describe('settings icon language', () => {
  it('gives every setting action a distinct visual shape', async () => {
    const rendered = new Map<string, string>();

    for (const [name, Icon] of Object.entries(settingsIcons)) {
      let renderer: ReactTestRenderer.ReactTestRenderer;
      await ReactTestRenderer.act(() => {
        renderer = ReactTestRenderer.create(<Icon />);
      });
      rendered.set(name, JSON.stringify(renderer!.toJSON()));
      await ReactTestRenderer.act(() => {
        renderer!.unmount();
      });
    }

    expect(new Set(rendered.values()).size).toBe(rendered.size);
  });
});
