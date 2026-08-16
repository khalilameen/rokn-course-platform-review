import {publicRequest} from '../../constants/api';
import type {DemoCoinPackage} from '../demoExperience';
import {
  ApiRecord,
  isApiRecord,
  payload,
  resourceList,
  valueAsBoolean,
} from './common';

type CoinPackageDto = {
  id?: unknown;
  coins?: unknown;
  price?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
};

type CourseAuthorizationDto = {
  total_balance?: unknown;
  remaining_balance?: unknown;
  spendable_balance?: unknown;
  current_coins?: unknown;
  deficit?: unknown;
  recommended_packages?: CoinPackageDto[];
};

type CourseRedemptionDto = {
  code?: unknown;
  type?: unknown;
  access_type?: unknown;
  learning_access?: unknown;
  already_enrolled?: unknown;
  chat_available?: unknown;
  certificate_available?: unknown;
  course?: {id?: unknown; name?: unknown};
};

type CourseUpgradeQuoteDto = CourseAuthorizationDto & {
  already_upgraded?: unknown;
  chat_available?: unknown;
  certificate_available?: unknown;
  ai_included?: unknown;
  course_id?: unknown;
  course_title?: unknown;
  upgrade_price?: unknown;
  reward_contribution_cap_per_course?: unknown;
  target_plan_code?: unknown;
  target_plan_name?: unknown;
  target_message_limit?: unknown;
};

const errorBody = (error: unknown): ApiRecord => {
  if (!isApiRecord(error)) return {};
  if (isApiRecord(error.data)) return error.data;
  if (isApiRecord(error.response) && isApiRecord(error.response.data)) {
    return error.response.data;
  }
  return {};
};

export type CoursePurchaseResult =
  | {kind: 'success'; balance: number; spendableBalance: number}
  | {
      kind: 'insufficient';
      balance: number;
      spendableBalance: number;
      deficit: number;
      packages: DemoCoinPackage[];
    };

export const purchaseCourse = async (
  courseId: string,
  accessPlanCode?: string,
): Promise<CoursePurchaseResult> => {
  try {
    const data = payload<CourseAuthorizationDto>(
      await publicRequest.post('courses/authorize', {
        course_id: Number(courseId),
        ...(accessPlanCode ? {access_plan_code: accessPlanCode} : {}),
      }),
    );
    return {
      kind: 'success',
      balance: Number(data.total_balance ?? data.remaining_balance ?? 0),
      spendableBalance: Number(
        data.spendable_balance ??
          data.total_balance ??
          data.remaining_balance ??
          0,
      ),
    };
  } catch (error: unknown) {
    const body = errorBody(error);
    const data = isApiRecord(body.data)
      ? (body.data as CourseAuthorizationDto)
      : {};
    if (body.code === 'insufficient_coins' || data.deficit !== undefined) {
      return {
        kind: 'insufficient',
        balance: Number(data.total_balance ?? data.current_coins ?? 0),
        spendableBalance: Number(
          data.spendable_balance ?? data.current_coins ?? 0,
        ),
        deficit: Number(data.deficit || 0),
        packages: (data.recommended_packages || []).map(item => ({
          id: String(item.id),
          coins: Number(item.coins || 0),
          price: Number(item.price || 0),
          label: String(item.name_ar || item.name_en || 'باقة عملات'),
        })),
      };
    }
    throw error;
  }
};

export const redeemCourseCode = async (
  code: string,
  expectedCourseId?: string,
) => {
  const response = await publicRequest.post('course-codes/redeem', {
    code: code.trim().toUpperCase(),
    course_id: expectedCourseId,
  });
  const data = payload<CourseRedemptionDto>(response);
  return {
    code: String(data.code || code).toUpperCase(),
    type: String(data.type || ''),
    accessType: data.access_type ? String(data.access_type) : undefined,
    learningAccess: valueAsBoolean(data.learning_access),
    alreadyEnrolled: valueAsBoolean(data.already_enrolled),
    chatAvailable: valueAsBoolean(data.chat_available),
    certificateAvailable:
      data.certificate_available === undefined
        ? undefined
        : valueAsBoolean(data.certificate_available),
    courseId:
      data.course?.id === null || data.course?.id === undefined
        ? null
        : String(data.course.id),
    courseName: data.course?.name ? String(data.course.name) : '',
  };
};

export type CourseChatUpgradeQuote = {
  alreadyUpgraded: boolean;
  chatAvailable: boolean;
  certificateAvailable: boolean;
  aiIncluded: boolean;
  courseId?: string;
  courseTitle?: string;
  price: number;
  totalBalance: number;
  spendableBalance: number;
  deficit: number;
  rewardContributionCap: number;
  packages: DemoCoinPackage[];
  targetPlanCode?: string;
  targetPlanName?: string;
  targetMessageLimit?: number;
};

const mapCourseChatUpgradeQuote = (
  data: CourseUpgradeQuoteDto,
): CourseChatUpgradeQuote => ({
  alreadyUpgraded: Boolean(data.already_upgraded),
  chatAvailable: Boolean(data.chat_available),
  certificateAvailable: Boolean(data.certificate_available),
  aiIncluded: Boolean(data.ai_included),
  courseId: data.course_id ? String(data.course_id) : undefined,
  courseTitle: data.course_title ? String(data.course_title) : undefined,
  price: Math.max(0, Number(data.upgrade_price || 0)),
  totalBalance: Math.max(0, Number(data.total_balance || 0)),
  spendableBalance: Math.max(0, Number(data.spendable_balance || 0)),
  deficit: Math.max(0, Number(data.deficit || 0)),
  rewardContributionCap: Math.max(
    0,
    Number(data.reward_contribution_cap_per_course || 0),
  ),
  packages: resourceList<CoinPackageDto>(data.recommended_packages).map(
    item => ({
      id: String(item.id),
      coins: Number(item.coins || 0),
      price: Number(item.price || 0),
      label: String(item.name_ar || item.name_en || 'باقة عملات'),
    }),
  ),
  targetPlanCode: data.target_plan_code
    ? String(data.target_plan_code)
    : undefined,
  targetPlanName: data.target_plan_name
    ? String(data.target_plan_name)
    : undefined,
  targetMessageLimit:
    data.target_message_limit === null ||
    data.target_message_limit === undefined
      ? undefined
      : Math.max(0, Number(data.target_message_limit) || 0),
});

export const getCourseChatUpgradeQuote = async (
  courseId: string,
): Promise<CourseChatUpgradeQuote> =>
  mapCourseChatUpgradeQuote(
    payload(await publicRequest.get(`courses/${courseId}/full-track-upgrade`)),
  );

export const purchaseCourseChatUpgrade = async (
  courseId: string,
  targetPlanCode?: string,
): Promise<CourseChatUpgradeQuote> =>
  mapCourseChatUpgradeQuote(
    payload(
      await publicRequest.post(`courses/${courseId}/full-track-upgrade`, {
        ...(targetPlanCode ? {target_plan_code: targetPlanCode} : {}),
      }),
    ),
  );

export const getFullTrackUpgradeQuote = getCourseChatUpgradeQuote;
export const purchaseFullTrackUpgrade = purchaseCourseChatUpgrade;

export const purchaseProductionCourse = purchaseCourse;
export const redeemProductionCourseCode = redeemCourseCode;
export const getProductionCourseChatUpgradeQuote = getCourseChatUpgradeQuote;
export const purchaseProductionCourseChatUpgrade = purchaseCourseChatUpgrade;
export const getProductionFullTrackUpgradeQuote = getFullTrackUpgradeQuote;
export const purchaseProductionFullTrackUpgrade = purchaseFullTrackUpgrade;
