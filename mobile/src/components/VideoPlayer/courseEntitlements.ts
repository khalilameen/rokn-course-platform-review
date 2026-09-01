export type CourseAssistantEntitlement = {
  accessType?: string;
  chatAvailable?: boolean;
  certificateAvailable?: boolean;
  certificateIncluded?: boolean;
  isDemo?: boolean;
};

const GRANT_ACCESS_TYPES = new Set([
  'scholarship',
  'grant',
  'institutional',
  'institutional_grant',
]);

export const normalizeCourseAccessType = (value: unknown) =>
  String(value || '').trim().toLowerCase();

export const isGrantCourseAccess = (value: unknown) =>
  GRANT_ACCESS_TYPES.has(normalizeCourseAccessType(value));

/**
 * Default closed: the backend must explicitly grant this variable-cost feature.
 * That prevents an old or partially deployed response from exposing a composer
 * that will only fail after the learner has typed a question.
 */
export const includesCourseAssistant = ({
  accessType,
  chatAvailable,
  isDemo,
}: CourseAssistantEntitlement) =>
  isDemo === true ||
  (chatAvailable === true && !isGrantCourseAccess(accessType));

export const includesCourseCertificate = ({
  accessType,
  certificateAvailable,
  certificateIncluded,
  isDemo,
}: CourseAssistantEntitlement) =>
  isDemo === true ||
  ((certificateIncluded ?? certificateAvailable) === true &&
    !isGrantCourseAccess(accessType));
