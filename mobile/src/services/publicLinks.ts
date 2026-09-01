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

export const certificateUrlFor = (_username: string, credential: string) =>
  `${portfolioBaseUrl}/c/${encodeURIComponent(credential)}`;

export const configuredAppStoreUrl = () =>
  process.env.EXPO_PUBLIC_APP_STORE_URL?.trim() || '';
