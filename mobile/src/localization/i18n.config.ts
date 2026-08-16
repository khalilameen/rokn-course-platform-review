import ar from './ar';
import en from './en';
import i18n from 'i18next';
import {initReactI18next} from 'react-i18next';
const language = 'ar';

i18n.use(initReactI18next).init({
  compatibilityJSON: 'v4',
  resources: {
    ar: {translation: ar},
    en: {translation: en},
  },
  lng: language,
  fallbackLng: language,
  interpolation: {
    escapeValue: false,
  },
});
export default i18n;
