import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../../constants/api';
import {secureRandomUuid} from '../../utils/secureRandom';
import {normalizeHumanIdentifier} from '../../utils/unicodeText';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  removeItem,
  saveItem,
} from '../../constants/helpers';
import type {AccountSessionBoundary} from '../../constants/helpers';
import type {DemoCoinPackage} from '../demoExperience';
import {mapCoinPackages} from './coinPackageMapper';
import {
  ApiRecord,
  firstBoolean,
  isApiRecord,
  payload,
  requireNonNegativeNumber,
  valueAsBoolean,
} from './common';

type CourseAuthorizationDto = {
  total_balance?: unknown;
  remaining_balance?: unknown;
  spendable_balance?: unknown;
  current_coins?: unknown;
  deficit?: unknown;
  recommended_packages?: unknown;
  purchased_balance?: unknown;
  reward_balance?: unknown;
  original_price?: unknown;
  discount_amount?: unknown;
  final_price?: unknown;
  coupon?: {
    code?: unknown;
    discount_percentage?: unknown;
  } | null;
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

type PersistedCoursePurchaseAttempt = {
  courseId: number;
  accessPlanCode: string;
  couponCode: string;
  idempotencyKey: string;
};

type PersistedCourseUpgradeAttempt = {
  courseId: number;
  targetPlanCode: string;
  expectedPrice: number;
  idempotencyKey: string;
};

const COURSE_PURCHASE_ATTEMPT_KEY = '@rokn/course-purchase-attempt/v2';
const COURSE_UPGRADE_ATTEMPT_KEY = '@rokn/course-upgrade-attempt/v1';
const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
let coursePurchaseStorageTail: Promise<void> = Promise.resolve();

const withCoursePurchaseStorageLock = <T>(
  operation: () => Promise<T>,
): Promise<T> => {
  const result = coursePurchaseStorageTail.then(operation, operation);
  coursePurchaseStorageTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

const coursePurchaseStorageKey = (
  courseId: number,
  accessPlanCode: string,
  couponCode: string,
  boundary?: AccountSessionBoundary,
) =>
  accountScopedStorageKey(
    `${COURSE_PURCHASE_ATTEMPT_KEY}:${courseId}:${accessPlanCode}:${encodeURIComponent(
      couponCode || 'none',
    )}`,
    boundary,
  );

const readCoursePurchaseAttempt = async (
  storageKey: string,
  courseId: number,
  accessPlanCode: string,
  couponCode: string,
): Promise<PersistedCoursePurchaseAttempt | null> => {
  const raw = await AsyncStorage.getItem(storageKey);
  if (raw === null) return null;
  let value: ApiRecord;
  try {
    const parsed: unknown = JSON.parse(raw);
    if (!isApiRecord(parsed)) throw new Error('INVALID_SHAPE');
    value = parsed;
  } catch {
    throw new Error('COURSE_PURCHASE_RECOVERY_RECORD_INVALID');
  }
  const storedCourseId = Number(value.courseId ?? value.course_id);
  const storedPlan = String(
    value.accessPlanCode ?? value.access_plan_code ?? '',
  ).trim();
  const storedCoupon = String(
    value.couponCode ?? value.coupon_code ?? '',
  ).trim();
  const idempotencyKey = String(
    value.idempotencyKey ?? value.idempotency_key ?? '',
  ).toLowerCase();
  if (
    storedCourseId !== courseId ||
    storedPlan !== accessPlanCode ||
    storedCoupon !== couponCode ||
    !UUID_PATTERN.test(idempotencyKey)
  ) {
    // Losing or replacing this key after an uncertain response could charge
    // twice. Leave the bytes untouched so recovery/support can inspect them.
    throw new Error('COURSE_PURCHASE_RECOVERY_RECORD_INVALID');
  }
  return {
    courseId,
    accessPlanCode,
    couponCode,
    idempotencyKey,
  };
};

const getOrCreateCoursePurchaseKey = async (
  courseId: number,
  accessPlanCode: string,
  couponCode: string,
  boundary: AccountSessionBoundary,
): Promise<string> =>
  withCoursePurchaseStorageLock(async () => {
    assertAccountSessionBoundary(boundary);
    const storageKey = await coursePurchaseStorageKey(
      courseId,
      accessPlanCode,
      couponCode,
      boundary,
    );
    const stored = await readCoursePurchaseAttempt(
      storageKey,
      courseId,
      accessPlanCode,
      couponCode,
    );
    if (stored) {
      return stored.idempotencyKey;
    }

    const idempotencyKey = secureRandomUuid();
    const persisted = await saveItem(storageKey, {
      courseId,
      accessPlanCode,
      couponCode,
      idempotencyKey,
    } satisfies PersistedCoursePurchaseAttempt);
    if (!persisted) throw new Error('COURSE_PURCHASE_IDEMPOTENCY_UNAVAILABLE');

    return idempotencyKey;
  });

const clearCoursePurchaseKey = async (
  courseId: number,
  accessPlanCode: string,
  couponCode: string,
  expectedIdempotencyKey: string,
  boundary: AccountSessionBoundary,
) =>
  withCoursePurchaseStorageLock(async () => {
    assertAccountSessionBoundary(boundary);
    const storageKey = await coursePurchaseStorageKey(
      courseId,
      accessPlanCode,
      couponCode,
      boundary,
    );
    const stored = await readCoursePurchaseAttempt(
      storageKey,
      courseId,
      accessPlanCode,
      couponCode,
    );
    if (stored?.idempotencyKey === expectedIdempotencyKey) {
      await removeItem(storageKey);
    }
  });

const courseUpgradeStorageKey = (
  courseId: number,
  targetPlanCode: string,
  expectedPrice: number,
  boundary?: AccountSessionBoundary,
) =>
  accountScopedStorageKey(
    `${COURSE_UPGRADE_ATTEMPT_KEY}:${courseId}:${targetPlanCode}:${expectedPrice}`,
    boundary,
  );

const readCourseUpgradeAttempt = async (
  storageKey: string,
  courseId: number,
  targetPlanCode: string,
  expectedPrice: number,
): Promise<PersistedCourseUpgradeAttempt | null> => {
  const raw = await AsyncStorage.getItem(storageKey);
  if (raw === null) return null;
  let value: ApiRecord;
  try {
    const parsed: unknown = JSON.parse(raw);
    if (!isApiRecord(parsed)) throw new Error('INVALID_SHAPE');
    value = parsed;
  } catch {
    throw new Error('COURSE_UPGRADE_RECOVERY_RECORD_INVALID');
  }
  const storedCourseId = Number(value.courseId ?? value.course_id);
  const storedPlanCode = String(
    value.targetPlanCode ?? value.target_plan_code ?? '',
  ).trim().toLowerCase();
  const storedExpectedPrice = Number(
    value.expectedPrice ?? value.expected_price,
  );
  const idempotencyKey = String(
    value.idempotencyKey ?? value.idempotency_key ?? '',
  ).toLowerCase();
  if (
    storedCourseId !== courseId ||
    storedPlanCode !== targetPlanCode ||
    storedExpectedPrice !== expectedPrice ||
    !UUID_PATTERN.test(idempotencyKey)
  ) {
    throw new Error('COURSE_UPGRADE_RECOVERY_RECORD_INVALID');
  }
  return {courseId, targetPlanCode, expectedPrice, idempotencyKey};
};

const getOrCreateCourseUpgradeKey = async (
  courseId: number,
  targetPlanCode: string,
  expectedPrice: number,
  boundary: AccountSessionBoundary,
): Promise<string> =>
  withCoursePurchaseStorageLock(async () => {
    assertAccountSessionBoundary(boundary);
    const storageKey = await courseUpgradeStorageKey(
      courseId,
      targetPlanCode,
      expectedPrice,
      boundary,
    );
    const stored = await readCourseUpgradeAttempt(
      storageKey,
      courseId,
      targetPlanCode,
      expectedPrice,
    );
    if (stored) return stored.idempotencyKey;

    const idempotencyKey = secureRandomUuid();
    const persisted = await saveItem(storageKey, {
      courseId,
      targetPlanCode,
      expectedPrice,
      idempotencyKey,
    } satisfies PersistedCourseUpgradeAttempt);
    if (!persisted) throw new Error('COURSE_UPGRADE_IDEMPOTENCY_UNAVAILABLE');

    return idempotencyKey;
  });

const clearCourseUpgradeKey = async (
  courseId: number,
  targetPlanCode: string,
  expectedPrice: number,
  expectedIdempotencyKey: string,
  boundary: AccountSessionBoundary,
) =>
  withCoursePurchaseStorageLock(async () => {
    assertAccountSessionBoundary(boundary);
    const storageKey = await courseUpgradeStorageKey(
      courseId,
      targetPlanCode,
      expectedPrice,
      boundary,
    );
    const stored = await readCourseUpgradeAttempt(
      storageKey,
      courseId,
      targetPlanCode,
      expectedPrice,
    );
    if (stored?.idempotencyKey === expectedIdempotencyKey) {
      await removeItem(storageKey);
    }
  });

const errorBody = (error: unknown): ApiRecord => {
  if (!isApiRecord(error)) return {};
  if (isApiRecord(error.data)) return error.data;
  if (isApiRecord(error.response) && isApiRecord(error.response.data)) {
    return error.response.data;
  }
  return {};
};

const numericCourseId = (courseId: string): number => {
  const parsed = Number(courseId);
  if (!Number.isSafeInteger(parsed) || parsed <= 0) {
    throw new Error('API_CONTRACT_INVALID_COURSE_ID');
  }
  return parsed;
};

const mapFinancialPackages = (value: unknown): DemoCoinPackage[] => {
  return mapCoinPackages(value, 'API_CONTRACT_INVALID_RECOMMENDED_PACKAGES');
};

const mapBalanceBreakdown = (data: CourseAuthorizationDto) => {
  const balance = requireNonNegativeNumber(
    data.total_balance ?? data.remaining_balance ?? data.current_coins,
    'COURSE_PURCHASE_TOTAL_BALANCE',
  );
  const spendableBalance = requireNonNegativeNumber(
    data.spendable_balance,
    'COURSE_PURCHASE_SPENDABLE_BALANCE',
  );
  const paidBalance = requireNonNegativeNumber(
    data.purchased_balance,
    'COURSE_PURCHASE_PAID_BALANCE',
  );
  const rewardBalance = requireNonNegativeNumber(
    data.reward_balance,
    'COURSE_PURCHASE_REWARD_BALANCE',
  );
  if (
    paidBalance + rewardBalance !== balance ||
    spendableBalance > balance
  ) {
    throw new Error('API_CONTRACT_INVALID_COURSE_PURCHASE_BALANCE');
  }
  return {balance, spendableBalance, paidBalance, rewardBalance};
};

export type CoursePurchaseResult =
  | {
      kind: 'success';
      balance: number;
      spendableBalance: number;
      paidBalance: number;
      rewardBalance: number;
      originalPrice: number;
      discountAmount: number;
    }
  | {
      kind: 'insufficient';
      balance: number;
      spendableBalance: number;
      paidBalance: number;
      rewardBalance: number;
      deficit: number;
      packages: DemoCoinPackage[];
    };

export type CoursePurchaseQuote = {
  accessPlanCode?: string;
  originalPrice: number;
  discountAmount: number;
  finalPrice: number;
  couponCode: string;
  discountPercentage: number;
};

export const quoteCoursePurchase = async (
  courseId: string,
  accessPlanCode: string | undefined,
  couponCode: string,
): Promise<CoursePurchaseQuote> => {
  const normalizedCoupon = normalizeHumanIdentifier(couponCode);
  const courseIdValue = numericCourseId(courseId);
  const data = payload<CourseAuthorizationDto>(
    await publicRequest.post('courses/purchase-quote', {
      course_id: courseIdValue,
      ...(accessPlanCode ? {access_plan_code: accessPlanCode} : {}),
      coupon_code: normalizedCoupon,
    }),
  );
  const originalPrice = requireNonNegativeNumber(
    data.original_price,
    'COURSE_QUOTE_ORIGINAL_PRICE',
  );
  const discountAmount = requireNonNegativeNumber(
    data.discount_amount,
    'COURSE_QUOTE_DISCOUNT_AMOUNT',
  );
  const finalPrice = requireNonNegativeNumber(
    data.final_price,
    'COURSE_QUOTE_FINAL_PRICE',
  );
  const discountPercentage = requireNonNegativeNumber(
    data.coupon?.discount_percentage ?? 0,
    'COURSE_QUOTE_DISCOUNT_PERCENTAGE',
  );
  if (
    discountAmount > originalPrice ||
    finalPrice + discountAmount !== originalPrice ||
    discountPercentage > 100
  ) {
    throw new Error('API_CONTRACT_INVALID_COURSE_QUOTE');
  }
  return {
    accessPlanCode: accessPlanCode || undefined,
    originalPrice,
    discountAmount,
    finalPrice,
    couponCode: normalizeHumanIdentifier(data.coupon?.code || normalizedCoupon),
    discountPercentage,
  };
};

export const purchaseCourse = async (
  courseId: string,
  accessPlanCode?: string,
  couponCode?: string,
  expectedPrice?: number,
): Promise<CoursePurchaseResult> => {
  const boundary = await captureAccountSessionBoundary();
  const courseIdValue = numericCourseId(courseId);
  const normalizedPlanCode = accessPlanCode || 'default';
  const normalizedCouponCode = normalizeHumanIdentifier(couponCode);
  const idempotencyKey = await getOrCreateCoursePurchaseKey(
    courseIdValue,
    normalizedPlanCode,
    normalizedCouponCode,
    boundary,
  );
  try {
    // A mounted purchase sheet may outlive logout/account replacement. Never
    // let its old intent authorize against the bearer of the new account.
    assertAccountSessionBoundary(boundary);
    const data = payload<CourseAuthorizationDto>(
      await publicRequest.post('courses/authorize', {
        course_id: courseIdValue,
        ...(accessPlanCode ? {access_plan_code: accessPlanCode} : {}),
        ...(normalizedCouponCode ? {coupon_code: normalizedCouponCode} : {}),
        ...(Number.isFinite(expectedPrice)
          ? {expected_price: Math.max(0, Math.trunc(expectedPrice as number))}
          : {}),
        idempotency_key: idempotencyKey,
      }),
    );
    const balances = mapBalanceBreakdown(data);
    const originalPrice = requireNonNegativeNumber(
      data.original_price,
      'COURSE_PURCHASE_ORIGINAL_PRICE',
    );
    const discountAmount = requireNonNegativeNumber(
      data.discount_amount,
      'COURSE_PURCHASE_DISCOUNT_AMOUNT',
    );
    if (discountAmount > originalPrice) {
      throw new Error('API_CONTRACT_INVALID_COURSE_PURCHASE_PRICE');
    }
    // Only discard the durable replay key once the authoritative financial
    // response itself has been validated.
    await clearCoursePurchaseKey(
      courseIdValue,
      normalizedPlanCode,
      normalizedCouponCode,
      idempotencyKey,
      boundary,
    );
    return {
      kind: 'success',
      ...balances,
      originalPrice,
      discountAmount,
    };
  } catch (error: unknown) {
    const body = errorBody(error);
    const data = isApiRecord(body.data)
      ? (body.data as CourseAuthorizationDto)
      : {};
    if (body.code === 'insufficient_coins' || data.deficit !== undefined) {
      assertAccountSessionBoundary(boundary);
      const balances = mapBalanceBreakdown(data);
      const deficit = requireNonNegativeNumber(
        data.deficit,
        'COURSE_PURCHASE_DEFICIT',
      );
      if (deficit <= 0) {
        throw new Error('API_CONTRACT_INVALID_COURSE_PURCHASE_DEFICIT');
      }
      return {
        kind: 'insufficient',
        ...balances,
        deficit,
        packages: mapFinancialPackages(data.recommended_packages),
      };
    }
    throw error;
  }
};

export const redeemCourseCode = async (
  code: string,
  expectedCourseId?: string,
) => {
  const normalizedCode = normalizeHumanIdentifier(code);
  if (!normalizedCode || normalizedCode.length > 100) {
    throw new Error('INVALID_COURSE_CODE');
  }
  const expectedCourseIdValue = expectedCourseId
    ? numericCourseId(expectedCourseId)
    : undefined;
  const response = await publicRequest.post('course-codes/redeem', {
    code: normalizedCode,
    course_id: expectedCourseIdValue,
  });
  const data = payload<CourseRedemptionDto>(response);
  const returnedCourseId =
    data.course?.id === null || data.course?.id === undefined
      ? null
      : String(data.course.id);
  if (
    expectedCourseIdValue !== undefined &&
    returnedCourseId !== String(expectedCourseIdValue)
  ) {
    throw new Error('COURSE_CODE_CONTRACT_MISMATCH');
  }
  return {
    code: normalizeHumanIdentifier(data.code || normalizedCode),
    type: String(data.type || ''),
    accessType: data.access_type ? String(data.access_type) : undefined,
    learningAccess: valueAsBoolean(data.learning_access),
    alreadyEnrolled: valueAsBoolean(data.already_enrolled),
    chatAvailable: valueAsBoolean(data.chat_available),
    certificateAvailable:
      data.certificate_available === undefined
        ? undefined
        : valueAsBoolean(data.certificate_available),
    courseId: returnedCourseId,
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
): CourseChatUpgradeQuote => {
  const alreadyUpgraded = firstBoolean(data.already_upgraded) ?? false;
  if (alreadyUpgraded) {
    return {
      alreadyUpgraded: true,
      chatAvailable: firstBoolean(data.chat_available) ?? true,
      certificateAvailable:
        firstBoolean(data.certificate_available) ?? false,
      aiIncluded: firstBoolean(data.ai_included) ?? true,
      courseId: data.course_id ? String(data.course_id) : undefined,
      courseTitle: data.course_title ? String(data.course_title) : undefined,
      price: 0,
      totalBalance: 0,
      spendableBalance: 0,
      deficit: 0,
      rewardContributionCap: 0,
      packages: [],
      targetPlanCode: data.target_plan_code
        ? String(data.target_plan_code)
        : undefined,
      targetPlanName: data.target_plan_name
        ? String(data.target_plan_name)
        : undefined,
    };
  }
  const price = requireNonNegativeNumber(
    data.upgrade_price,
    'COURSE_UPGRADE_PRICE',
  );
  const totalBalance = requireNonNegativeNumber(
    data.total_balance,
    'COURSE_UPGRADE_TOTAL_BALANCE',
  );
  const spendableBalance = requireNonNegativeNumber(
    data.spendable_balance,
    'COURSE_UPGRADE_SPENDABLE_BALANCE',
  );
  const deficit = requireNonNegativeNumber(
    data.deficit,
    'COURSE_UPGRADE_DEFICIT',
  );
  const rewardContributionCap = requireNonNegativeNumber(
    data.reward_contribution_cap_per_course,
    'COURSE_UPGRADE_REWARD_CAP',
  );
  if (
    spendableBalance > totalBalance ||
    deficit !== Math.max(0, price - spendableBalance)
  ) {
    throw new Error('API_CONTRACT_INVALID_COURSE_UPGRADE_QUOTE');
  }
  const targetMessageLimit =
    data.target_message_limit === null ||
    data.target_message_limit === undefined
      ? undefined
      : requireNonNegativeNumber(
          data.target_message_limit,
          'COURSE_UPGRADE_MESSAGE_LIMIT',
        );

  return {
    alreadyUpgraded: false,
    chatAvailable: firstBoolean(data.chat_available) ?? false,
    certificateAvailable: firstBoolean(data.certificate_available) ?? false,
    aiIncluded: firstBoolean(data.ai_included) ?? false,
    courseId: data.course_id ? String(data.course_id) : undefined,
    courseTitle: data.course_title ? String(data.course_title) : undefined,
    price,
    totalBalance,
    spendableBalance,
    deficit,
    rewardContributionCap,
    packages: mapFinancialPackages(data.recommended_packages),
    targetPlanCode: data.target_plan_code
      ? String(data.target_plan_code)
      : undefined,
    targetPlanName: data.target_plan_name
      ? String(data.target_plan_name)
      : undefined,
    targetMessageLimit,
  };
};

export const getCourseChatUpgradeQuote = async (
  courseId: string,
): Promise<CourseChatUpgradeQuote> =>
  mapCourseChatUpgradeQuote(
    payload(
      await publicRequest.get(
        `courses/${numericCourseId(courseId)}/full-track-upgrade`,
      ),
    ),
  );

export const purchaseCourseChatUpgrade = async (
  courseId: string,
  targetPlanCode?: string,
  expectedPrice?: number,
): Promise<CourseChatUpgradeQuote> => {
  const boundary = await captureAccountSessionBoundary();
  const courseIdValue = numericCourseId(courseId);
  const normalizedTargetPlan = String(targetPlanCode || '')
    .trim()
    .toLowerCase();
  if (
    !['guided', 'mentor'].includes(normalizedTargetPlan) ||
    !Number.isSafeInteger(expectedPrice) ||
    Number(expectedPrice) < 0
  ) {
    throw new Error('COURSE_UPGRADE_INTENT_INVALID');
  }
  const normalizedExpectedPrice = Math.trunc(Number(expectedPrice));
  const idempotencyKey = await getOrCreateCourseUpgradeKey(
    courseIdValue,
    normalizedTargetPlan,
    normalizedExpectedPrice,
    boundary,
  );
  assertAccountSessionBoundary(boundary);
  const result = mapCourseChatUpgradeQuote(
    payload(
      await publicRequest.post(
        `courses/${courseIdValue}/full-track-upgrade`,
        {
          target_plan_code: normalizedTargetPlan,
          expected_price: normalizedExpectedPrice,
          idempotency_key: idempotencyKey,
        },
        {headers: {'Idempotency-Key': idempotencyKey}},
      ),
    ),
  );
  assertAccountSessionBoundary(boundary);
  await clearCourseUpgradeKey(
    courseIdValue,
    normalizedTargetPlan,
    normalizedExpectedPrice,
    idempotencyKey,
    boundary,
  );

  return result;
};

export const getFullTrackUpgradeQuote = getCourseChatUpgradeQuote;
export const purchaseFullTrackUpgrade = purchaseCourseChatUpgrade;

export const purchaseProductionCourse = purchaseCourse;
export const redeemProductionCourseCode = redeemCourseCode;
export const getProductionCourseChatUpgradeQuote = getCourseChatUpgradeQuote;
export const purchaseProductionCourseChatUpgrade = purchaseCourseChatUpgrade;
export const getProductionFullTrackUpgradeQuote = getFullTrackUpgradeQuote;
export const purchaseProductionFullTrackUpgrade = purchaseFullTrackUpgrade;
