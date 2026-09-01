import React, {useEffect} from 'react';
import './src/localization/i18n.config';
import AppInitializer from './src/screens/AppInitializer';
import i18n from './src/localization/i18n.config';
import {useSelector} from 'react-redux';
import {
  flushProductEvents,
  trackProductEvent,
} from './src/services/productAnalytics';
import {bootstrapOperationalDiagnostics} from './src/services/operationalTelemetry';
import {bootstrapProductFeatures} from './src/services/productFeatures';

const App = () => {
  const {language} = useSelector((state: any) => state.settings);
  useEffect(() => {
    const languageCode =
      typeof language === 'string' ? language || 'ar' : language?.code ?? 'ar';
    const supportedLanguage = languageCode === 'en' ? 'en' : 'ar';
    void Promise.resolve(i18n.changeLanguage(supportedLanguage)).catch(
      () => undefined,
    );
  }, [language]);
  useEffect(() => {
    void trackProductEvent({event_name: 'app_opened', screen_key: 'app'}).catch(
      () => undefined,
    );
    void flushProductEvents().catch(() => undefined);
    void bootstrapOperationalDiagnostics().catch(() => undefined);
    void bootstrapProductFeatures().catch(() => undefined);
  }, []);
  return <AppInitializer />;
};
export default App;
