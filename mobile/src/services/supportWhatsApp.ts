import {getPublicAppSettings} from './publicAppSettings';
import {openExternalUrlOnce} from './systemActions';

const safeWhatsAppUrl = (value: unknown) => {
  const raw = String(value ?? '').trim();
  if (/^https:\/\/(wa\.me|api\.whatsapp\.com)\//i.test(raw)) return raw;
  const digits = raw.replace(/\D/g, '');
  return digits.length >= 8 && digits.length <= 15
    ? `https://wa.me/${digits}`
    : '';
};

export const getSupportWhatsAppUrl = async () => {
  const settings = await getPublicAppSettings();
  const managedUrl = safeWhatsAppUrl(
    settings?.support_whatsapp_url ??
      settings?.social_media?.whatsapp,
  );
  if (managedUrl) return managedUrl;

  const environmentUrl = safeWhatsAppUrl(
    process.env.EXPO_PUBLIC_SUPPORT_WHATSAPP_URL,
  );
  if (!environmentUrl) throw new Error('SUPPORT_WHATSAPP_UNAVAILABLE');
  return environmentUrl;
};

export const openSupportWhatsApp = async (
  message = 'مرحبًا فريق ركن\nأحتاج مساعدة في التطبيق',
) => {
  const supportUrl = await getSupportWhatsAppUrl();
  const separator = supportUrl.includes('?') ? '&' : '?';
  await openExternalUrlOnce(
    `${supportUrl}${separator}text=${encodeURIComponent(message)}`,
  );
};
