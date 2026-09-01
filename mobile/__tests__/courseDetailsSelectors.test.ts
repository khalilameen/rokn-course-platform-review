import {
  canChooseCourseAccess,
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
  userRating: null,
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
    expect(presentation().primaryActionLabel).toBe('اختر الفئة المناسبة لك');
  });

  it('never exposes pricing tiers or educational access codes to a guest', () => {
    const base = {
      isDemoCourse: false,
      owned: false,
      pageReady: true,
      remoteError: '',
    };

    expect(canChooseCourseAccess({...base, remoteSession: false})).toBe(false);
    expect(canChooseCourseAccess({...base, remoteSession: null})).toBe(false);
    expect(canChooseCourseAccess({...base, remoteSession: true})).toBe(true);
    expect(
      canChooseCourseAccess({...base, owned: true, remoteSession: true}),
    ).toBe(false);
  });

  it('sorts package choices without mutating API data', () => {
    const packages = [
      {id: 'large', coins: 1000, price: 500, label: 'كبيرة'},
      {id: 'small', coins: 250, price: 150, label: 'صغيرة'},
    ];

    const result = presentation({remotePackages: packages});

    expect(result.packages.map(item => item.id)).toEqual(['small', 'large']);
    expect(result.checkoutPackages.map(item => item.id)).toEqual([
      'small',
      'large',
    ]);
    expect(packages.map(item => item.id)).toEqual(['large', 'small']);
  });

  it('marks the smallest sufficient top-up and never offers a partial package', () => {
    expect(presentation().sufficientPackage?.id).toBe('small');
    const insufficient = presentation({
      remoteSpendableBalance: 0,
      remotePackages: [
        {id: 'too-small', coins: 250, price: 150, label: 'صغيرة'},
      ],
    });
    expect(insufficient.sufficientPackage).toBeUndefined();
    expect(insufficient.checkoutPackages).toEqual([]);
  });

  it('keeps reward coins visible but excludes the part above the selected plan discount', () => {
    const result = presentation({
      remoteBalance: 650,
      remotePaidBalance: 100,
      remoteRewardBalance: 550,
      remoteSpendableBalance: 500,
      remoteCourse: {
        ...course,
        accessPlans: course.accessPlans.map(item =>
          item.code === 'mentor'
            ? {...item, minimumPaidCoins: 400}
            : item,
        ),
      },
    });

    expect(result.balance).toBe(650);
    expect(result.spendableBalance).toBe(400);
    expect(result.shortfall).toBe(300);
    expect(result.planSpendableBalances.mentor).toBe(400);
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
