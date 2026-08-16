export type RoknDestination =
  | {name: 'Home'}
  | {name: 'Profile'}
  | {name: 'Wallet'}
  | {name: 'CourseDetails'; params: {courseId: string}}
  | {name: 'Reels'; params: {courseId: string; reelId?: string}};

const safeDecode = (value: string) => {
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
};

const safeRouteId = (value: string): string | null => {
  const decoded = safeDecode(value).trim();
  // Route identifiers are data, never path fragments. This also rejects
  // encoded slashes, traversal tokens and control characters from pushes or
  // custom-scheme links before they reach an interpolated API URL.
  return /^[\p{L}\p{N}][\p{L}\p{N}._-]{0,127}$/u.test(decoded)
    ? decoded
    : null;
};

/** One parser for API notifications, push links and navigation aliases. */
export const parseRoknDestination = (
  rawLink?: string | null,
): RoknDestination | null => {
  const raw = String(rawLink || '').trim();
  if (!raw) return null;

  const path = raw
    .replace(
      /^(?:rokn:\/\/|https?:\/\/(?:www\.)?rokn\.(?:app|com)\/)/i,
      '',
    )
    .replace(/^\/+/, '')
    .split(/[?#]/, 1)[0];

  const course = path.match(
    /^courses?\/([^/]+)(?:\/watch(?:\/([^/]+))?)?\/?$/i,
  );
  if (course?.[1]) {
    const courseId = safeRouteId(course[1]);
    const reelId = course[2] ? safeRouteId(course[2]) : undefined;
    if (!courseId || (course[2] && !reelId)) return null;
    return path.toLowerCase().includes('/watch')
      ? {name: 'Reels', params: {courseId, ...(reelId ? {reelId} : {})}}
      : {name: 'CourseDetails', params: {courseId}};
  }

  if (/^home\/?$/i.test(path)) return {name: 'Home'};
  if (/^profile\/?$/i.test(path)) return {name: 'Profile'};
  if (/^wallet\/?$/i.test(path)) return {name: 'Wallet'};
  return null;
};

export const isExternalWebLink = (rawLink?: string | null) =>
  /^https:\/\//i.test(String(rawLink || '').trim()) &&
  !/^https:\/\/(?:www\.)?rokn\.(?:app|com)(?:\/|$)/i.test(
    String(rawLink || '').trim(),
  );
