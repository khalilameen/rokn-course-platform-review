import fs from 'fs';
import path from 'path';

const readSource = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('first-launch experience', () => {
  it('opens the guest home without an onboarding or marketing gate', () => {
    const navigation = readSource('src/navigation/Navigation.tsx');
    const languageBootstrap = readSource('src/screens/LanguageSelect.tsx');

    expect(fs.existsSync(path.resolve(__dirname, '../src/screens/Onboarding.tsx'))).toBe(
      false,
    );
    expect(navigation).toContain(
      "const needsArabicBootstrap = languageCode === 'en' && !I18nManager.isRTL",
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
});
