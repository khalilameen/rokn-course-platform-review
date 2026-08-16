export type BrowserSocialProvider = 'google' | 'tiktok' | 'facebook';

const expectedPathSuffix = (provider: BrowserSocialProvider) =>
  `/social-auth/${provider}/start`;

const normalizedPath = (pathname: string) =>
  pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname;

type ParsedUrl = URL & {
  protocol: string;
  origin: string;
  username: string;
  password: string;
  hash: string;
  pathname: string;
};

const parseUrl = (value: string, base?: URL) =>
  new URL(value, base) as unknown as ParsedUrl;

const trustedHttpsBase = (apiBaseUrl: string) => {
  const base = parseUrl(apiBaseUrl);
  if (
    base.protocol !== 'https:' ||
    base.username ||
    base.password ||
    base.hash
  ) {
    throw new Error('AUTH_ORIGIN_INVALID');
  }
  return base;
};

/**
 * Accept backend-provided OAuth starts only when they remain on the configured
 * API origin and name the endpoint for the provider the learner selected.
 * An invalid response falls back to our own deterministic API endpoint.
 */
export const resolveSocialAuthStartUrl = (
  configuredUrl: unknown,
  apiBaseUrl: string,
  provider: BrowserSocialProvider,
) => {
  const base = trustedHttpsBase(apiBaseUrl);
  const fallback = parseUrl(`social-auth/${provider}/start`, base).toString();
  if (typeof configuredUrl !== 'string' || !configuredUrl.trim()) {
    return fallback;
  }

  try {
    const candidate = parseUrl(configuredUrl.trim());
    const path = normalizedPath(candidate.pathname);
    if (
      candidate.protocol === 'https:' &&
      candidate.origin === base.origin &&
      !candidate.username &&
      !candidate.password &&
      !candidate.hash &&
      path.endsWith(expectedPathSuffix(provider))
    ) {
      return candidate.toString();
    }
  } catch {
    // A malformed backend value is untrusted and uses the known-safe fallback.
  }

  return fallback;
};
