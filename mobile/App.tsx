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
    i18n.changeLanguage(languageCode);
  }, [language]);
  useEffect(() => {
    void trackProductEvent({event_name: 'app_opened', screen_key: 'app'});
    void flushProductEvents();
    void bootstrapOperationalDiagnostics();
    void bootstrapProductFeatures();
  }, []);
  return <AppInitializer />;
};
export default App;
