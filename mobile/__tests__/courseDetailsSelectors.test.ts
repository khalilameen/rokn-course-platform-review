import {
  selectCourseDetailsPresentation,
  selectCourseHeroHeight,
} from '../src/screens/CourseDetails/details/selectors';
import type {CourseAccessPlan, CourseDetails} from '../src/services/roknApi';

const plan = (code: string, priceCoins: number): CourseAccessPlan => ({
  code,
  name: code,
  priceCoins,
  chatEnabled: code !== 'basic',
  chatMessageLimit: code === 'mentor' ? 80 : 0,
  projectFeedbackLevel: code === 'mentor' ? 'enhanced' : 'pass_only',
  projectReportEnabled: code === 'mentor',
  projectOutputEnabled: code === 'mentor',
  certificateEnabled: true,
});

const course: CourseDetails = {
  id: '42',
  title: 'كورس الإنتاج',
  description: 'وصف الكورس',
  price: 300,
  instructor: 'مدرب ركن',
  instructorBio: '',
  owned: false,
  modules: [],
  reelCount: 18,
  projectCount: 2,
  previewReelCount: 3,
  ratingAverage: 4.8,
  ratingsCount: 91,
  studentsCount: 640,
  durationMinutes: 125,
  accessPlans: [plan('basic', 300), plan('mentor', 700)],
};

const presentation = (
  overrides: Partial<
    Parameters<typeof selectCourseDetailsPresentation>[0]
  > = {},
) =>
  selectCourseDetailsPresentation({
    courseId: course.id,
    experience: null,
    isDemoCourse: false,
    remoteBalance: 650,
    remoteCourse: course,
    remoteError: '',
    remoteLoading: false,
    remoteOwned: false,
    remotePackages: [
      {id: 'large', coins: 1000, price: 500, label: 'كبيرة'},
      {id: 'small', coins: 250, price: 150, label: 'صغيرة'},
    ],
    remoteSession: true,
    remoteSpendableBalance: 500,
    selectedPlanCode: 'mentor',
    ...overrides,
  });

describe('course details presentation contract', () => {
  it('preserves production metadata and the selected pricing tier', () => {
    const result = presentation();

    expect(result).toMatchObject({
      courseTitle: course.title,
      courseDescription: course.description,
      reelCount: 18,
      projectCount: 2,
      previewReelCount: 3,
      ratingAverage: 4.8,
      ratingsCount: 91,
      studentsCount: 640,
      durationMinutes: 125,
      coursePrice: 300,
      purchasePrice: 700,
      shortfall: 200,
      pageReady: true,
    });
    expect(result.selectedPlan?.code).toBe('mentor');
  });

  it('keeps authentication and ownership ahead of checkout labels', () => {
    expect(presentation({remoteSession: false}).primaryActionLabel).toContain(
      'سجّل الدخول',
    );
    expect(presentation({remoteOwned: true}).primaryActionLabel).toBe(
      'استكمل الكورس',
    );
  });

  it('sorts package choices without mutating API data', () => {
    const packages = [
      {id: 'large', coins: 1000, price: 500, label: 'كبيرة'},
      {id: 'small', coins: 250, price: 150, label: 'صغيرة'},
    ];

    const result = presentation({remotePackages: packages});

    expect(result.packages.map(item => item.id)).toEqual(['small', 'large']);
    expect(packages.map(item => item.id)).toEqual(['large', 'small']);
  });

  it('keeps the responsive hero height bounded by the viewport', () => {
    expect(
      selectCourseHeroHeight({
        width: 400,
        height: 800,
        isTablet: false,
        fontScale: 1,
      }),
    ).toBe(352);
    expect(
      selectCourseHeroHeight({
        width: 1200,
        height: 900,
        isTablet: true,
        fontScale: 2,
      }),
    ).toBeLessThanOrEqual(648);
  });
});
