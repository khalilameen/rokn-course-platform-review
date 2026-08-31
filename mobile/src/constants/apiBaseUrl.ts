const DEFAULT_ROKN_API_URL =
  'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/';

/**
 * Expo accepts either the public origin or the full API base in release
 * environments. Keep that deployment detail out of screens and services so a
 * bare Laravel Cloud origin cannot silently send every request to `/`.
 */
export const normalizeRoknApiUrl = (value?: string) => {
  const configured = value?.trim() || DEFAULT_ROKN_API_URL;
  const withoutTrailingSlash = configured.replace(/\/+$/, '');

  if (/\/api\/v1$/i.test(withoutTrailingSlash)) {
    return `${withoutTrailingSlash}/`;
  }
  if (/\/api$/i.test(withoutTrailingSlash)) {
    return `${withoutTrailingSlash}/v1/`;
  }
  if (/^https?:\/\/[^/]+$/i.test(withoutTrailingSlash)) {
    return `${withoutTrailingSlash}/api/v1/`;
  }

  return `${withoutTrailingSlash}/`;
};

export const roknApiUrl = normalizeRoknApiUrl(
  process.env.EXPO_PUBLIC_API_URL,
);
