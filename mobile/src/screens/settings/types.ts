export type SettingsNavigation = {
  navigate: {
    (
      screen:
        | 'AboutUs'
        | 'DeviceSessions'
        | 'EditAccount'
        | 'PrivacyPolicy'
        | 'Profile'
        | 'TermsOfUse',
    ): void;
    (screen: 'Login', params: {returnTo: {name: 'Settings'}}): void;
    (screen: 'Feedback', params: {sourceScreen: 'settings'}): void;
  };
  reset: (state: {index: number; routes: Array<{name: 'Home'}>}) => void;
};
