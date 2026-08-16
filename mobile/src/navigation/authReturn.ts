import {
  LOGIN_RETURN_TO_PARAMLESS_ROUTES,
  type LoginReturnTo,
  type LoginReturnToParamlessRoute,
} from './types';

type RouteSnapshot = {
  name?: unknown;
  params?: object;
};

const cleanId = (value: unknown): string | undefined => {
  if (typeof value !== 'string') return undefined;
  const valueTrimmed = value.trim();
  return valueTrimmed.length > 0 ? valueTrimmed : undefined;
};

/**
 * Keep only the small, non-sensitive route snapshot required to return a
 * learner to the interrupted course. Never persist arbitrary navigation
 * params supplied by a server or deep link.
 */
export const safeLoginReturnToFromRoute = (
  route?: RouteSnapshot,
): LoginReturnTo | undefined => {
  if (
    typeof route?.name === 'string' &&
    LOGIN_RETURN_TO_PARAMLESS_ROUTES.includes(
      route.name as LoginReturnToParamlessRoute,
    )
  ) {
    return {name: route.name as LoginReturnToParamlessRoute};
  }

  const params = (route?.params ?? {}) as Record<string, unknown>;
  const courseId = cleanId(params.courseId);
  if (!courseId) return undefined;

  if (route?.name === 'CourseDetails') {
    return {
      name: 'CourseDetails',
      params: {
        courseId,
        openCodeRedemption: params.openCodeRedemption === true,
        openPurchase: params.openPurchase === true,
        resumeAfterPreview: params.resumeAfterPreview === true,
        resumeReelId: cleanId(params.resumeReelId),
      },
    };
  }

  if (route?.name === 'Reels') {
    const previewCount = Number(params.previewCount);
    return {
      name: 'Reels',
      params: {
        courseId,
        reelId: cleanId(params.reelId),
        lessonId: cleanId(params.lessonId),
        preview: params.preview === true,
        previewCount:
          Number.isInteger(previewCount) && previewCount > 0
            ? previewCount
            : undefined,
      },
    };
  }

  return undefined;
};
