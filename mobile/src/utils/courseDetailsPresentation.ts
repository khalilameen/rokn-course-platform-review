type CourseDurationPayload = {
  duration_minutes?: unknown;
  metadata?: {
    duration_minutes?: unknown;
    hours_count?: unknown;
  };
  modules?: Array<{
    sections?: Array<{
      content?: {duration_minutes?: unknown};
      sectionable?: {duration_minutes?: unknown};
      lesson?: {duration_minutes?: unknown};
    }>;
  }>;
};

export const normalizeCourseDurationMinutes = (
  course: CourseDurationPayload | null | undefined,
): number | null => {
  const explicitMinutes =
    course?.metadata?.duration_minutes ?? course?.duration_minutes;
  const parsedExplicitMinutes = Number(explicitMinutes);
  const hours = Number(course?.metadata?.hours_count);
  const sectionMinutes = (course?.modules ?? []).reduce(
    (courseTotal, module) =>
      courseTotal +
      (module.sections ?? []).reduce((moduleTotal, section) => {
        const minutes = Number(
          section.content?.duration_minutes ??
            section.sectionable?.duration_minutes ??
            section.lesson?.duration_minutes,
        );
        return moduleTotal +
          (Number.isFinite(minutes) && minutes > 0 ? minutes : 0);
      }, 0),
    0,
  );
  const rawMinutes =
    (Number.isFinite(parsedExplicitMinutes) && parsedExplicitMinutes > 0
      ? parsedExplicitMinutes
      : undefined) ??
    (sectionMinutes > 0
      ? sectionMinutes
      : Number.isFinite(hours) && hours > 0
      ? hours * 60
      : NaN);
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
