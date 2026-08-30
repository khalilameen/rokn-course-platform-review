import {Linking} from 'react-native';
import {getPublicAppSettings} from './publicAppSettings';

let cachedUrl = '';

const safeWhatsAppUrl = (value: unknown) => {
  const raw = String(value ?? '').trim();
  if (/^https:\/\/(wa\.me|api\.whatsapp\.com)\//i.test(raw)) return raw;
  const digits = raw.replace(/\D/g, '');
  return digits.length >= 8 && digits.length <= 15
    ? `https://wa.me/${digits}`
    : '';
};

export const getSupportWhatsAppUrl = async () => {
  if (cachedUrl) return cachedUrl;
  const environmentUrl = safeWhatsAppUrl(
    process.env.EXPO_PUBLIC_SUPPORT_WHATSAPP_URL,
  );
  if (environmentUrl) {
    cachedUrl = environmentUrl;
    return cachedUrl;
  }

  const settings = await getPublicAppSettings();
  cachedUrl = safeWhatsAppUrl(
    settings?.support_whatsapp_url ??
      settings?.social_media?.whatsapp ??
      settings?.whatsapp,
  );
  if (!cachedUrl) throw new Error('SUPPORT_WHATSAPP_UNAVAILABLE');
  return cachedUrl;
};

export const openSupportWhatsApp = async (
  message = 'مرحبًا فريق ركن، أحتاج مساعدة في التطبيق.',
) => {
  const supportUrl = await getSupportWhatsAppUrl();
  const separator = supportUrl.includes('?') ? '&' : '?';
  await Linking.openURL(
    `${supportUrl}${separator}text=${encodeURIComponent(message)}`,
  );
};
