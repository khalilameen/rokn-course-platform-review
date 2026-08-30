import {publicRequest} from '../constants/api';

export type PublicAppSettings = Record<string, unknown> & {
  android_app_url?: unknown;
  ios_app_url?: unknown;
  support_whatsapp_url?: unknown;
  whatsapp?: unknown;
  social_media?: {whatsapp?: unknown};
};

let cachedSettings: PublicAppSettings | null = null;
let cachedAt = 0;
let pendingRequest: Promise<PublicAppSettings> | null = null;

const CACHE_TTL_MS = 5 * 60 * 1000;

export const getPublicAppSettings = async (): Promise<PublicAppSettings> => {
  if (cachedSettings && Date.now() - cachedAt < CACHE_TTL_MS) {
    return cachedSettings;
  }
  if (pendingRequest) return pendingRequest;

  const request: Promise<PublicAppSettings> = publicRequest
    .get('settings')
    .then(response => {
      const payload = response?.data?.data ?? response?.data;
      const settings = Array.isArray(payload) ? payload[0] : payload;
      const normalized: PublicAppSettings =
        settings && typeof settings === 'object' ? settings : {};
      cachedSettings = normalized;
      cachedAt = Date.now();
      return normalized;
    })
    .finally(() => {
      pendingRequest = null;
    });

  pendingRequest = request;
  return request;
};

export const safeDashboardUrl = (value: unknown) => {
  const raw = String(value ?? '').trim();
  return /^https:\/\//i.test(raw) ? raw : '';
};
