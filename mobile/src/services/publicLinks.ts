import {roknApiUrl} from '../constants/apiBaseUrl';

const publicWebBaseUrl = roknApiUrl.replace(/api(?:\/v1)?\/?$/i, '');
const configuredPublicBase = String(
  process.env.EXPO_PUBLIC_PORTFOLIO_URL || 'https://rokn.app',
)
  .trim()
  .replace(/\/$/, '');
const portfolioBaseUrl = /^https:\/\/(?:www\.)?rokn\.app$/i.test(
  configuredPublicBase,
)
  ? configuredPublicBase
  : 'https://rokn.app';

export const accountDeletionUrl =
  process.env.EXPO_PUBLIC_ACCOUNT_DELETION_URL?.trim() ||
  `${publicWebBaseUrl}account-deletion`;

// Kept as a contract export for older callers and tests. The policy remains
// inside the unified legal pages and is intentionally not a settings row.
export const returnsPolicyUrl = `${publicWebBaseUrl}returns-policy`;

export const portfolioUrlFor = (username: string) =>
  `${portfolioBaseUrl}/@${encodeURIComponent(username)}`;

/** Portfolio links are server-issued unlisted capabilities, never usernames. */
export const trustedPortfolioShareUrl = (value: unknown) => {
  if (typeof value !== 'string' || !value.trim()) return null;
  try {
    const url = new URL(value.trim());
    const hostname = url.hostname.toLowerCase();
    const token = decodeURIComponent(url.pathname.slice(2));
    if (
      url.protocol !== 'https:' ||
      url.username ||
      url.password ||
      url.port ||
      url.search ||
      url.hash ||
      !['rokn.app', 'www.rokn.app'].includes(hostname) ||
      !url.pathname.startsWith('/@') ||
      !/^rokn-(?:[a-z0-9]{24}|[a-f0-9]{32})$/.test(token) ||
      url.pathname !== `/@${encodeURIComponent(token)}`
    ) {
      return null;
    }
    return url.toString();
  } catch {
    return null;
  }
};

export const certificateUrlFor = (_username: string, credential: string) =>
  `${portfolioBaseUrl}/c/${encodeURIComponent(credential)}`;

/** Certificate destinations are server data, not trusted navigation input. */
export const trustedCertificateVerificationUrl = (
  value: unknown,
  credential: string,
) => {
  if (typeof value !== 'string' || !value.trim() || !credential.trim()) {
    return null;
  }
  try {
    const url = new URL(value.trim());
    const hostname = url.hostname.toLowerCase();
    if (
      url.protocol !== 'https:' ||
      url.username ||
      url.password ||
      url.port ||
      !['rokn.app', 'www.rokn.app'].includes(hostname) ||
      url.pathname !== `/c/${encodeURIComponent(credential)}`
    ) {
      return null;
    }
    return url.toString();
  } catch {
    return null;
  }
};

export const configuredAppStoreUrl = () =>
  process.env.EXPO_PUBLIC_APP_STORE_URL?.trim() || '';
