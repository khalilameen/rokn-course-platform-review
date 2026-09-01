export type RoknDestination =
  | {name: 'Home'}
  | {name: 'Profile'}
  | {name: 'Wallet'}
  | {name: 'Feedback'; params: {caseId: string}}
  | {name: 'CourseDetails'; params: {courseId: string}}
  | {
      name: 'Reels';
      params: {courseId: string; reelId?: string; lessonId?: string};
    };

const TRUSTED_APP_LINK_HOSTS = new Set([
  'rokn.app',
  'www.rokn.app',
  // Temporary parser compatibility for links already delivered by production.
  // This host is deliberately not declared as an OS app-link association.
  'rokn-course-platform-review-production-b7gpy1.laravel.cloud',
]);

const safeDecode = (value: string): string | null => {
  try {
    return decodeURIComponent(value);
  } catch {
    return null;
  }
};

export const safeRoknRouteId = (value: unknown): string | null => {
  if (typeof value !== 'string' && typeof value !== 'number') return null;
  const raw = String(value);
  // Numeric identifiers have one canonical spelling. Reject percent-encoded
  // aliases so dedupe, caches and backend link validation see the same key.
  if (raw.includes('%')) return null;
  const decoded = safeDecode(raw);
  if (decoded === null) return null;
  const normalized = decoded.trim();
  // Route identifiers are data, never path fragments. This also rejects
  // encoded slashes, traversal tokens and control characters from pushes or
  // custom-scheme links before they reach an interpolated API URL.
  return /^[1-9]\d{0,17}$/.test(normalized) ? normalized : null;
};

const safeSupportCaseId = (value: unknown): string | null => {
  if (typeof value !== 'string' || value.includes('%')) return null;
  const decoded = safeDecode(value);
  const normalized = decoded?.trim().toUpperCase() || '';
  return /^[0-9A-HJKMNP-TV-Z]{26}$/.test(normalized) ? normalized : null;
};

const pathFromTrustedLink = (raw: string): string | null => {
  if (/^https?:\/\//i.test(raw)) {
    // Universal links are HTTPS-only and must not contain credentials, ports,
    // lookalike hosts or an open-redirect style protocol-relative path.
    const match = raw.match(/^https:\/\/([^/?#]+)(\/[^?#]*)?(?:[?#].*)?$/i);
    if (!match) return null;
    const authority = match[1].toLowerCase();
    if (authority.includes('@') || authority.includes(':')) return null;
    if (!TRUSTED_APP_LINK_HOSTS.has(authority)) return null;
    return (match[2] || '/').replace(/^\/+/, '');
  }

  if (/^[a-z][a-z\d+.-]*:/i.test(raw)) {
    if (!/^rokn:\/\//i.test(raw)) return null;
    return raw.slice('rokn://'.length).split(/[?#]/, 1)[0].replace(/^\/+/, '');
  }

  if (raw.startsWith('//') || raw.includes('\\')) return null;
  return raw.split(/[?#]/, 1)[0].replace(/^\/+/, '');
};

const queryRouteId = (raw: string, names: string[]): string | undefined => {
  const query = raw.includes('?')
    ? raw.slice(raw.indexOf('?') + 1).split('#', 1)[0]
    : '';
  if (!query) return undefined;
  const accepted = new Set(names.map(name => name.toLowerCase()));
  for (const pair of query.split('&')) {
    const [rawKey, ...rawValue] = pair.split('=');
    const key = safeDecode(rawKey || '')?.trim().toLowerCase();
    if (!key || !accepted.has(key)) continue;
    const value = safeDecode(rawValue.join('=') || '');
    const id = value === null ? null : safeRoknRouteId(value);
    return id || undefined;
  }
  return undefined;
};

/** One parser for API notifications, push links and navigation aliases. */
export const parseRoknDestination = (
  rawLink?: string | null,
): RoknDestination | null => {
  const raw = String(rawLink || '').trim();
  if (!raw) return null;

  const path = pathFromTrustedLink(raw);
  if (
    path === null ||
    Array.from(path).some(character => {
      const code = character.charCodeAt(0);
      return code <= 31 || code === 127;
    })
  ) {
    return null;
  }

  const course = path.match(
    /^courses?\/([^/]+)(?:\/watch(?:\/([^/]+))?)?\/?$/i,
  );
  if (course?.[1]) {
    const courseId = safeRoknRouteId(course[1]);
    const reelId = course[2] ? safeRoknRouteId(course[2]) : undefined;
    if (!courseId || (course[2] && !reelId)) return null;
    const lessonId = queryRouteId(raw, ['lessonId', 'lesson_id']);
    const queryReelId = queryRouteId(raw, ['reelId', 'reel_id']);
    return path.toLowerCase().includes('/watch')
      ? {
          name: 'Reels',
          params: {
            courseId,
            ...(lessonId
              ? {lessonId}
              : reelId || queryReelId
              ? {reelId: reelId || queryReelId}
              : {}),
          },
        }
      : {name: 'CourseDetails', params: {courseId}};
  }

  // Adjacent releases and old notification templates used these two detail
  // paths. Keep them as parser aliases only; new links stay canonical.
  const legacyCourseDetails = path.match(
    /^(?:course-details\/([^/]+)|courses?\/([^/]+)\/details|api\/courses\/([^/]+)\/details)\/?$/i,
  );
  if (legacyCourseDetails) {
    const courseId = safeRoknRouteId(
      legacyCourseDetails[1] ||
        legacyCourseDetails[2] ||
        legacyCourseDetails[3],
    );
    return courseId ? {name: 'CourseDetails', params: {courseId}} : null;
  }

  const lesson = path.match(/^courses?\/([^/]+)\/lesson\/([^/]+)\/?$/i);
  if (lesson) {
    const courseId = safeRoknRouteId(lesson[1]);
    const lessonId = safeRoknRouteId(lesson[2]);
    return courseId && lessonId
      ? {name: 'Reels', params: {courseId, lessonId}}
      : null;
  }

  if (/^home\/?$/i.test(path)) return {name: 'Home'};
  if (/^profile\/?$/i.test(path)) return {name: 'Profile'};
  if (/^wallet\/?$/i.test(path)) return {name: 'Wallet'};
  const support = path.match(/^support\/([^/]+)\/?$/i);
  if (support) {
    const caseId = safeSupportCaseId(support[1]);
    return caseId ? {name: 'Feedback', params: {caseId}} : null;
  }
  return null;
};

export const roknDestinationKey = (destination: RoknDestination): string => {
  if (!('params' in destination)) return destination.name;
  if (destination.name === 'CourseDetails') {
    return `${destination.name}:${destination.params.courseId}`;
  }
  if (destination.name === 'Feedback') {
    return `${destination.name}:${destination.params.caseId}`;
  }
  return [
    destination.name,
    destination.params.courseId,
    destination.params.reelId || '',
    destination.params.lessonId || '',
  ].join(':');
};

export const isExternalWebLink = (rawLink?: string | null) =>
  /^https:\/\//i.test(String(rawLink || '').trim()) &&
  !/^https:\/\/(?:(?:www\.)?rokn\.app|rokn-course-platform-review-production-b7gpy1\.laravel\.cloud)(?:\/|$)/i.test(
    String(rawLink || '').trim(),
  );
