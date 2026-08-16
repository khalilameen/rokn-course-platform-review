import {createDemoCourse} from '../../../components/VideoPlayer/demoCourse';
import {
  ECONOMY_CONFIG,
  selectSmallestSufficientPackage,
} from '../../../config/economy';
import {CAN_START_EXTERNAL_CHECKOUT} from '../../../constants/distribution';
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

export const planBenefits = (plan: CourseAccessPlan): string[] => {
  const items = ['الكورس كامل ومشروعات العبور'];
  if (!plan.chatEnabled) {
    items.push('من غير Rokn AI');
  } else {
    items.push(
      `حتى ${formatArabicNumber(plan.chatMessageLimit)} رسالة مع Rokn AI`,
    );
  }
  if (plan.projectFeedbackLevel === 'report') {
    items.push('تقرير وتوصيات على وصف مشروعك');
  } else if (plan.projectFeedbackLevel === 'enhanced') {
    items.push(
      plan.projectOutputEnabled
        ? 'مراجعة أعمق ونموذج محسّن عند ملاءمته'
        : 'مراجعة أعمق وتوصيات أكثر تفصيلًا',
    );
  } else {
    items.push('العبور بعد المحاولة الجادة');
  }
  if (plan.certificateEnabled) items.push('إصدار الشهادة عند استيفاء شروطها');
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
  remotePackages: DemoCoinPackage[];
  remoteSession: boolean | null;
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
  remotePackages,
  remoteSession,
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
          chatEnabled: false,
          chatMessageLimit: 0,
          projectFeedbackLevel: 'pass_only',
          projectReportEnabled: false,
          projectOutputEnabled: false,
          certificateEnabled: true,
        },
        {
          code: 'guided',
          name: 'التعلّم بإرشاد',
          priceCoins: DEMO_COURSE_PRICE + 1300,
          chatEnabled: true,
          chatMessageLimit: 25,
          projectFeedbackLevel: 'report',
          projectReportEnabled: true,
          projectOutputEnabled: false,
          certificateEnabled: true,
        },
        {
          code: 'mentor',
          name: 'التعلّم بمتابعة',
          priceCoins: DEMO_COURSE_PRICE + 4200,
          chatEnabled: true,
          chatMessageLimit: 80,
          projectFeedbackLevel: 'enhanced',
          projectReportEnabled: true,
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
    ? 'ابنِ عرضك، أدر عميلك، وحوّل التسليم إلى مشروع يفتح لك الباب التالي.'
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
  const spendableBalance = isDemoCourse
    ? (experience?.paidBalance ?? 0) +
      Math.min(
        experience?.rewardBalance ?? 0,
        ECONOMY_CONFIG.maxRewardContributionPerCourse,
      )
    : remoteSpendableBalance ?? 0;
  const purchasePrice = selectedPlan?.priceCoins ?? coursePrice ?? 0;
  const shortfall = Math.max(0, purchasePrice - spendableBalance);
  const packages = (isDemoCourse ? DEMO_COIN_PACKAGES : remotePackages)
    .slice()
    .sort((left, right) => left.coins - right.coins);
  const sufficientPackage = selectSmallestSufficientPackage(
    packages,
    shortfall,
  );
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
    : !CAN_START_EXTERNAL_CHECKOUT && hasPreview
    ? 'شاهد المحتوى المجاني'
    : !CAN_START_EXTERNAL_CHECKOUT
    ? 'متاح للحسابات المشتركة'
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
    durationMinutes,
    hasPreview,
    owned,
    packages,
    pageReady,
    previewReelCount,
    primaryActionLabel,
    projectCount,
    purchasePrice,
    ratingAverage,
    ratingsCount,
    reelCount,
    selectedPlan,
    shortfall,
    spendableBalance,
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
