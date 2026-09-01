import {Linking, Share} from 'react-native';

type SharePayload = Parameters<typeof Share.share>[0];
type ShareOptions = Parameters<typeof Share.share>[1];

const openFlights = new Map<string, Promise<void>>();
const shareFlights = new Map<string, Promise<void>>();
const recentOpens = new Map<string, number>();
const OPEN_DEDUPE_MS = 1_500;
const MAX_RECENT_OPENS = 24;

const normalizedUrl = (value: string) => value.trim();

const rememberOpen = (url: string) => {
  const now = Date.now();
  recentOpens.delete(url);
  recentOpens.set(url, now);
  recentOpens.forEach((openedAt, key) => {
    if (now - openedAt >= OPEN_DEDUPE_MS) recentOpens.delete(key);
  });
  while (recentOpens.size > MAX_RECENT_OPENS) {
    const oldest = recentOpens.keys().next().value;
    if (typeof oldest !== 'string') break;
    recentOpens.delete(oldest);
  }
};

/**
 * Hands a URL to the OS once. Resolution means the hand-off succeeded; it
 * deliberately says nothing about what the learner did in the other app.
 */
export const openExternalUrlOnce = async (
  value: string,
  fallbackUrl?: string,
): Promise<void> => {
  const url = normalizedUrl(value);
  if (!url) throw new Error('EXTERNAL_URL_UNAVAILABLE');

  const now = Date.now();
  if (now - (recentOpens.get(url) || 0) < OPEN_DEDUPE_MS) return;
  const existing = openFlights.get(url);
  if (existing) return existing;

  const flight = (async () => {
    try {
      if (!/^https?:/i.test(url) && !(await Linking.canOpenURL(url))) {
        throw new Error('EXTERNAL_APP_UNAVAILABLE');
      }
      await Linking.openURL(url);
      rememberOpen(url);
    } catch (error) {
      const fallback = normalizedUrl(fallbackUrl || '');
      if (!fallback || fallback === url) throw error;
      await Linking.openURL(fallback);
      rememberOpen(url);
    }
  })().finally(() => openFlights.delete(url));

  openFlights.set(url, flight);
  return flight;
};

/** Keeps a rapid double tap from opening two native share sheets. */
export const shareOnce = async (
  key: string,
  payload: SharePayload,
  options?: ShareOptions,
): Promise<void> => {
  const stableKey = key.trim();
  if (!stableKey) throw new Error('SHARE_DESTINATION_UNAVAILABLE');
  const existing = shareFlights.get(stableKey);
  if (existing) return existing;
  const flight = Share.share(payload, options)
    .then(() => undefined)
    .finally(() => shareFlights.delete(stableKey));
  shareFlights.set(stableKey, flight);
  return flight;
};
