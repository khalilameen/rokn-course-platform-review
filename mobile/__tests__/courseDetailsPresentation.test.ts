import {
  normalizeCourseDurationMinutes,
  shouldShowStickyCourseAction,
} from '../src/utils/courseDetailsPresentation';

describe('course details presentation', () => {
  it('uses the exact API duration before the legacy hours fallback', () => {
    expect(
      normalizeCourseDurationMinutes({
        metadata: {duration_minutes: 95, hours_count: 3},
      }),
    ).toBe(95);
    expect(
      normalizeCourseDurationMinutes({metadata: {hours_count: 2}}),
    ).toBe(120);
  });

  it('does not invent a duration for missing, zero, or invalid metadata', () => {
    expect(normalizeCourseDurationMinutes(undefined)).toBeNull();
    expect(
      normalizeCourseDurationMinutes({metadata: {duration_minutes: 0}}),
    ).toBeNull();
    expect(
      normalizeCourseDurationMinutes({metadata: {duration_minutes: 'unknown'}}),
    ).toBeNull();
  });

  it('shows the sticky action only after the inline action has left the viewport', () => {
    const layout = {heroHeight: 420, primaryActionLocalBottom: 310};

    expect(
      shouldShowStickyCourseAction({...layout, scrollOffset: 738}),
    ).toBe(false);
    expect(
      shouldShowStickyCourseAction({...layout, scrollOffset: 739}),
    ).toBe(true);
  });

  it('keeps the sticky action hidden until the inline action is measured', () => {
    expect(
      shouldShowStickyCourseAction({
        scrollOffset: 1_000,
        heroHeight: 420,
        primaryActionLocalBottom: null,
      }),
    ).toBe(false);
  });
});
