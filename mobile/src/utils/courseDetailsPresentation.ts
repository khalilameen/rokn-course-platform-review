type CourseDurationPayload = {
  duration_minutes?: unknown;
  metadata?: {
    duration_minutes?: unknown;
    hours_count?: unknown;
  };
};

export const normalizeCourseDurationMinutes = (
  course: CourseDurationPayload | null | undefined,
): number | null => {
  const explicitMinutes =
    course?.metadata?.duration_minutes ?? course?.duration_minutes;
  const hours = Number(course?.metadata?.hours_count);
  const rawMinutes =
    explicitMinutes ?? (Number.isFinite(hours) && hours > 0 ? hours * 60 : NaN);
  const minutes = Number(rawMinutes);

  return Number.isFinite(minutes) && minutes > 0 ? Math.ceil(minutes) : null;
};

type StickyCourseActionInput = {
  scrollOffset: number;
  heroHeight: number;
  primaryActionLocalBottom: number | null;
  clearance?: number;
};

export const shouldShowStickyCourseAction = ({
  scrollOffset,
  heroHeight,
  primaryActionLocalBottom,
  clearance = 8,
}: StickyCourseActionInput): boolean =>
  primaryActionLocalBottom !== null &&
  Number.isFinite(scrollOffset) &&
  Number.isFinite(heroHeight) &&
  Number.isFinite(primaryActionLocalBottom) &&
  scrollOffset > heroHeight + primaryActionLocalBottom + clearance;
