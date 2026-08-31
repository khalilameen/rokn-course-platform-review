import {roknApiUrl} from '../constants/apiBaseUrl';

const publicWebBaseUrl = roknApiUrl.replace(/api(?:\/v1)?\/?$/i, '');
const portfolioBaseUrl = (
  process.env.EXPO_PUBLIC_PORTFOLIO_URL || 'https://rokn.app'
).replace(/\/$/, '');

export const accountDeletionUrl =
  process.env.EXPO_PUBLIC_ACCOUNT_DELETION_URL?.trim() ||
  `${publicWebBaseUrl}account-deletion`;

export const returnsPolicyUrl = `${publicWebBaseUrl}returns-policy`;

export const portfolioUrlFor = (username: string) =>
  `${portfolioBaseUrl}/@${encodeURIComponent(username)}`;

export const certificateUrlFor = (username: string, credential: string) =>
  `${portfolioUrlFor(username)}?certificate=${encodeURIComponent(credential)}`;

export const configuredAppStoreUrl = () =>
  process.env.EXPO_PUBLIC_APP_STORE_URL?.trim() || '';
