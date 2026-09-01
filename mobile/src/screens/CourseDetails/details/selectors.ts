import {createDemoCourse} from '../../../components/VideoPlayer/demoCourse';
import {
  ECONOMY_CONFIG,
  selectSmallestSufficientPackage,
} from '../../../config/economy';
import {CAN_START_COIN_CHECKOUT} from '../../../constants/distribution';
import {formatArabicNumber} from '../../../constants/arabicFormatting';
import {
  DEMO_COIN_PACKAGES,
  DEMO_COURSE_PRICE,
} from '../../../services/demoExperience';
import type {
  DemoCoinPackage,
  DemoExperienceState,
} from '../../../services/demoExperience';
import type {
  CourseAccessPlan,
  CourseDetails as CourseDetailsDto,
} from '../../../services/roknApi';

const COURSE_TITLE = 'من أول مهارة إلى أول عميل';
const DEMO_COURSE_DURATION_MINUTES = Math.ceil(
  createDemoCourse().modules.reduce(
    (total, module) =>
      total +
      module.reels.reduce(
        (moduleTotal, reel) => moduleTotal + (reel.durationSeconds || 0),
        0,
      ),
    0,
  ) / 60,
);
let demoAccessPlans: CourseAccessPlan[] | null = null;

export const canChooseCourseAccess = ({
  isDemoCourse,
  owned,
  pageReady,
  remoteError,
  remoteSession,
}: {
  isDemoCourse: boolean;
  owned: boolean;
  pageReady: boolean;
  remoteError: string;
  remoteSession: boolean | null;
}) =>
  !owned &&
  pageReady &&
  !remoteError &&
  (isDemoCourse || remoteSession === true);

export const planBenefits = (plan: CourseAccessPlan): string[] => {
  const items = ['محتوى الكورس كامل'];
  if (!plan.chatEnabled) {
    items.push('من غير Rokn AI');
  } else {
    items.push(
      `حتى ${formatArabicNumber(plan.chatMessageLimit)} رسالة مع Rokn AI`,
    );
  }
  if (plan.projectFeedbackLevel === 'report') {
    items.push('تقرير المشروع داخل شات ركن');
  } else if (plan.projectFeedbackLevel === 'enhanced') {
    items.push(
      plan.projectFollowupEnabled && (plan.projectFollowupMessageLimit ?? 0) > 0
        ? `تقرير ومتابعة داخل شات ركن حتى ${formatArabicNumber(
            plan.projectFollowupMessageLimit ?? 0,
          )} رسالة`
        : 'تقرير المشروع داخل شات ركن',
    );
  } else {
    items.push('العبور بعد قبول المشروع');
  }
  if (plan.certificateEnabled) items.push('إصدار الشهادة عند استيفاء شروطها');
  if ((plan.minimumPaidCoins ?? 0) > 0) {
    items.push(
      `يمكن استخدام عملات المكافآت حتى ${formatArabicNumber(
        Math.max(0, plan.priceCoins - (plan.minimumPaidCoins ?? 0)),
      )} عملة ركن من السعر`,
    );
  }
  return items;
};

type CourseRouteParams = {
  coinPrice?: unknown;
  description?: unknown;
  price?: unknown;
  title?: unknown;
};

type CourseDetailsPresentationInput = {
  courseId: string;
  experience: DemoExperienceState | null;
  isDemoCourse: boolean;
  remoteBalance: number | null;
  remoteCourse: CourseDetailsDto | null;
  remoteError: string;
  remoteLoading: boolean;
  remoteOwned: boolean;
  remotePaidBalance?: number | null;
  remotePackages: DemoCoinPackage[];
  remoteSession: boolean | null;
  remoteRewardBalance?: number | null;
  remoteRewardContributionCap?: number | null;
  remoteSpendableBalance: number | null;
  routeParams?: CourseRouteParams;
  selectedPlanCode: string;
};

export const selectCourseDetailsPresentation = ({
  courseId,
  experience,
  isDemoCourse,
  remoteBalance,
  remoteCourse,
  remoteError,
  remoteLoading,
  remoteOwned,
  remotePaidBalance = null,
  remotePackages,
  remoteSession,
  remoteRewardBalance = null,
  remoteRewardContributionCap = null,
  remoteSpendableBalance,
  routeParams = {},
  selectedPlanCode,
}: CourseDetailsPresentationInput) => {
  const route = {params: routeParams};
  const routePrice = route.params?.coinPrice ?? route.params?.price;
  const parsedRoutePrice = Number(routePrice);
  const safeRoutePrice =
    routePrice === null ||
    routePrice === undefined ||
    !Number.isFinite(parsedRoutePrice)
      ? null
      : Math.max(0, parsedRoutePrice);
  const baseCoursePrice = isDemoCourse
    ? DEMO_COURSE_PRICE
    : remoteCourse?.price ?? safeRoutePrice;
  const accessPlans = ((): CourseAccessPlan[] => {
    if (isDemoCourse) {
      if (demoAccessPlans) return demoAccessPlans;
      demoAccessPlans = [
        {
          code: 'basic',
          name: 'التعلّم',
          priceCoins: DEMO_COURSE_PRICE,
          minimumPaidCoins: 0,
          chatEnabled: false,
          chatMessageLimit: 0,
          projectFeedbackLevel: 'pass_only',
          projectReportEnabled: false,
          projectFollowupEnabled: false,
          projectFollowupMessageLimit: 0,
          projectOutputEnabled: false,
          certificateEnabled: true,
        },
        {
          code: 'guided',
          name: 'التعلّم بإرشاد',
          priceCoins: DEMO_COURSE_PRICE + 1300,
          minimumPaidCoins: 1300,
          chatEnabled: true,
          chatMessageLimit: 25,
          projectFeedbackLevel: 'report',
          projectReportEnabled: true,
          projectFollowupEnabled: false,
          projectFollowupMessageLimit: 0,
          projectOutputEnabled: false,
          certificateEnabled: true,
        },
        {
          code: 'mentor',
          name: 'التعلّم بمتابعة',
          priceCoins: DEMO_COURSE_PRICE + 4200,
          minimumPaidCoins: 4200,
          chatEnabled: true,
          chatMessageLimit: 80,
          projectFeedbackLevel: 'enhanced',
          projectReportEnabled: true,
          projectFollowupEnabled: true,
          projectFollowupMessageLimit: 20,
          projectOutputEnabled: true,
          certificateEnabled: true,
        },
      ];
      return demoAccessPlans;
    }
    return remoteCourse?.accessPlans || [];
  })();
  const selectedPlan =
    accessPlans.find(plan => plan.code === selectedPlanCode) || accessPlans[0];
  const coursePrice = accessPlans.length
    ? Math.min(...accessPlans.map(plan => plan.priceCoins))
    : baseCoursePrice;
  const courseTitle = isDemoCourse
    ? COURSE_TITLE
    : remoteCourse?.title || String(route.params?.title || 'كورس ركن');
  const courseDescription = isDemoCourse
    ? 'ابنِ عرضك وأدر عميلك وحوّل التسليم إلى مشروع يفتح لك الباب التالي'
    : remoteCourse?.description || String(route.params?.description || '');
  const reelCount = isDemoCourse ? 30 : remoteCourse?.reelCount || 0;
  const projectCount = isDemoCourse ? 3 : remoteCourse?.projectCount || 0;
  const previewReelCount = isDemoCourse
    ? 2
    : remoteCourse?.previewReelCount || 0;
  const hasPreview = previewReelCount > 0;

  const owned = isDemoCourse
    ? Boolean(experience?.purchasedCourseIds.includes(courseId))
    : remoteOwned;
  const balance = isDemoCourse ? experience?.balance ?? 0 : remoteBalance ?? 0;
  const paidBalance = isDemoCourse
    ? experience?.paidBalance ?? 0
    : remotePaidBalance ?? 0;
  const rewardBalance = isDemoCourse
    ? experience?.rewardBalance ?? 0
    : remoteRewardBalance ?? Math.max(0, balance - paidBalance);
  const genericRewardAllowance = isDemoCourse
    ? ECONOMY_CONFIG.maxRewardContributionPerCourse
    : Math.max(
        0,
        remoteRewardContributionCap ??
          (remoteSpendableBalance ?? paidBalance) - paidBalance,
      );
  const planSpendableBalances = Object.fromEntries(
    accessPlans.map(plan => [
      plan.code,
      paidBalance +
        Math.min(
          rewardBalance,
          genericRewardAllowance,
          Math.max(0, plan.priceCoins - (plan.minimumPaidCoins ?? 0)),
        ),
    ]),
  ) as Record<string, number>;
  const spendableBalance = selectedPlan
    ? planSpendableBalances[selectedPlan.code] ?? 0
    : paidBalance + Math.min(rewardBalance, genericRewardAllowance);
  const purchasePrice = selectedPlan?.priceCoins ?? coursePrice ?? 0;
  const rewardContributionLimit = Math.min(
    genericRewardAllowance,
    Math.max(0, purchasePrice - (selectedPlan?.minimumPaidCoins ?? 0)),
  );
  const usableCurrentBalance = Math.min(purchasePrice, spendableBalance);
  const rewardContributionPercent =
    purchasePrice > 0
      ? Math.floor((rewardContributionLimit / purchasePrice) * 100)
      : 0;
  const shortfall = Math.max(0, purchasePrice - spendableBalance);
  const packages = (isDemoCourse ? DEMO_COIN_PACKAGES : remotePackages)
    .slice()
    .sort((left, right) => left.coins - right.coins);
  const sufficientPackage = selectSmallestSufficientPackage(
    packages,
    shortfall,
  );
  const checkoutPackages = packages.filter(item => item.coins >= shortfall);
  const pageReady = isDemoCourse
    ? Boolean(experience)
    : Boolean(remoteCourse) && !remoteLoading;
  const primaryActionLabel = remoteError
    ? 'تعذّر تحميل التفاصيل'
    : owned
    ? 'استكمل الكورس'
    : !isDemoCourse && remoteSession === false
    ? 'سجّل الدخول لفتح الكورس'
    : coursePrice === null
    ? 'السعر لم يُنشر بعد'
    : !CAN_START_COIN_CHECKOUT && hasPreview
    ? 'شاهد مجانًا'
    : !CAN_START_COIN_CHECKOUT
    ? 'الشراء غير متاح الآن'
    : coursePrice === 0
    ? 'ابدأ التعلّم مجانًا'
    : accessPlans.length > 1
    ? 'ابدأ التعلّم الآن'
    : 'شراء الكورس';
  // Demo metrics stay isolated from server course data.
  const ratingsCount = isDemoCourse ? 186 : remoteCourse?.ratingsCount ?? 0;
  const ratingAverage = isDemoCourse
    ? 4.9
    : remoteCourse?.ratingAverage ?? null;
  const studentsCount = isDemoCourse ? 320 : remoteCourse?.studentsCount ?? 0;
  const durationMinutes = isDemoCourse
    ? DEMO_COURSE_DURATION_MINUTES
    : remoteCourse?.durationMinutes ?? null;

  return {
    accessPlans,
    balance,
    courseDescription,
    coursePrice,
    courseTitle,
    checkoutPackages,
    durationMinutes,
    hasPreview,
    owned,
    packages,
    planSpendableBalances,
    pageReady,
    previewReelCount,
    primaryActionLabel,
    projectCount,
    purchasePrice,
    rewardContributionLimit,
    rewardContributionPercent,
    ratingAverage,
    ratingsCount,
    reelCount,
    selectedPlan,
    shortfall,
    spendableBalance,
    usableCurrentBalance,
    studentsCount,
    sufficientPackage,
  };
};

export const selectCourseHeroHeight = ({
  fontScale,
  height,
  isTablet,
  width,
}: {
  fontScale: number;
  height: number;
  isTablet: boolean;
  width: number;
}) => {
  const heroBaseHeight = Math.max(310, width * (isTablet ? 0.48 : 0.88));
  return Math.min(
    height * 0.72,
    heroBaseHeight + Math.max(0, fontScale - 1) * 150,
  );
};
