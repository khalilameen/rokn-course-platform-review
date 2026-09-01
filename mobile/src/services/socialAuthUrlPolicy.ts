export type BrowserSocialProvider = 'google' | 'tiktok' | 'facebook';

type AbsoluteUrl = {
  origin: string;
  path: string;
  query: string;
  value: string;
};

const normalizedPath = (path: string) =>
  path.length > 1 ? path.replace(/\/+$/, '') : path;

// Avoid relying on URL in Hermes. The OAuth contract only permits an absolute
// HTTPS origin, a fixed path and an optional backend-authored query.
const absoluteHttpsUrl = (raw: string): AbsoluteUrl | null => {
  const value = raw.trim();
  const match = value.match(/^(https):\/\/([^/?#]+)(\/[^?#]*)(\?[^#]*)?$/i);
  if (!match || match[2].includes('@') || /\s/.test(value)) return null;
  return {
    origin: `https://${match[2].toLowerCase()}`,
    path: normalizedPath(match[3]),
    query: match[4] || '',
    value,
  };
};

const trustedApiBase = (value: unknown, errorCode: string) => {
  if (typeof value !== 'string') throw new Error(errorCode);
  const parsed = absoluteHttpsUrl(value);
  if (!parsed || parsed.query) throw new Error(errorCode);
  return parsed;
};

/**
 * The discovery response may advertise an OAuth API host different from the
 * APK's active API. It is trusted only as an explicit base declaration, then
 * the start URL must stay on that exact origin and fixed provider route.
 */
export const resolveSocialAuthStartUrl = (
  configuredUrl: unknown,
  activeApiBaseUrl: string,
  provider: BrowserSocialProvider,
  advertisedApiBaseUrl?: unknown,
): string => {
  const activeBase = trustedApiBase(activeApiBaseUrl, 'AUTH_ORIGIN_INVALID');
  const advertisedBase =
    typeof advertisedApiBaseUrl === 'string' && advertisedApiBaseUrl.trim()
      ? trustedApiBase(
          advertisedApiBaseUrl,
          'AUTH_DISCOVERY_ORIGIN_INVALID',
        )
      : activeBase;
  if (advertisedBase.path !== activeBase.path) {
    throw new Error('AUTH_DISCOVERY_PATH_INVALID');
  }
  if (typeof configuredUrl !== 'string' || !configuredUrl.trim()) return '';

  const candidate = absoluteHttpsUrl(configuredUrl);
  const expectedPath = `${advertisedBase.path}/social-auth/${provider}/start`;
  return candidate &&
    candidate.origin === advertisedBase.origin &&
    candidate.path === expectedPath
    ? candidate.value
    : '';
};
