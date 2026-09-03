import fs from 'fs';
import path from 'path';

const readSource = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('first-launch experience', () => {
  it('opens the guest home without an onboarding or marketing gate', () => {
    const navigation = readSource('src/navigation/Navigation.tsx');
    const languageBootstrap = readSource('src/screens/LanguageSelect.tsx');

    expect(
      fs.existsSync(path.resolve(__dirname, '../src/screens/Onboarding.tsx')),
    ).toBe(false);
    expect(navigation).toContain(
      "const needsArabicBootstrap = languageCode !== 'en' && !I18nManager.isRTL",
    );
    expect(navigation).not.toMatch(/Onboarding|ابدأ الآن|مزايا/);
    expect(languageBootstrap).toContain("routes: [{name: 'Home'}]");
  });

  it('keeps the loading screen limited to the Rokn wordmark and one slogan', () => {
    const splash = readSource('src/screens/Splash.tsx');
    const appConfig = JSON.parse(readSource('app.json')) as {
      expo: {
        splash: {image: string; backgroundColor: string};
        android: {splash: {image: string; backgroundColor: string}};
      };
    };

    expect(splash).toContain("require('../assets/images/logo.png')");
    expect(splash).toContain('دقيقة بدقيقة');
    expect(splash).not.toMatch(/تعلّم بمقاطع|مشروعات|Rokn AI|ابدأ الآن/);
    expect(appConfig.expo.splash).toEqual({
      image: './src/assets/images/logo.png',
      resizeMode: 'contain',
      backgroundColor: '#080B12',
    });
    expect(appConfig.expo.android.splash).toEqual(appConfig.expo.splash);
  });

  it('keeps a pending payment recoverable while the app stays foregrounded', () => {
    const initializer = readSource('src/screens/AppInitializer.tsx');
    const wallet = readSource('src/screens/Wallet.tsx');

    expect(initializer).toContain(
      'const retryDelays = [4_000, 10_000, 20_000, 40_000, 60_000]',
    );
    expect(initializer).toContain(
      'storeReconcileAttempt >= retryDelays.length',
    );
    expect(initializer).toContain("AppState.currentState !== 'active'");
    expect(initializer).toContain('clearStoreReconcileTimer();');
    expect(wallet).toContain('subscribeCoinCheckoutCredits(() =>');
  });

  it('adopts an Android OAuth callback even when the Custom Tab returns first', () => {
    const initializer = readSource('src/screens/AppInitializer.tsx');
    const androidSession = readSource('src/services/androidAuthSession.ts');

    expect(initializer).toContain("Linking.addEventListener('url', ({url}) =>");
    expect(initializer).toContain('androidAuthSessionOwnsCallback(url)');
    expect(initializer).toContain('resumePendingSocialAuth(url)');
    expect(initializer).toContain(
      'const initialUrlFlight = Linking.getInitialURL()',
    );
    expect(initializer).toContain('void initialUrlFlight');
    expect(androidSession).toContain('recoverable: true');
    expect(androidSession).toContain("queryValue(candidate, 'attempt')");
  });

  it('serializes mutable settings so the last learner choice wins', () => {
    const settings = readSource(
      'src/screens/settings/useSettingsPreferences.ts',
    );

    expect(settings).toContain('settingsScopeWriteTails');
    expect(settings).toContain('withSettingsScopeWrite');
    expect(settings).toContain('preferenceRevisionRef');
    expect(settings).toContain("isUnchanged('VIDEO_QUALITY')");
    expect(settings).toContain('enqueuePreferenceWrite');
    expect(settings).toContain(
      'const boundaryFlight = captureAccountSessionBoundary()',
    );
  });
});
