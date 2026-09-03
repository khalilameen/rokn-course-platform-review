import {
  useFocusEffect,
  useIsFocused,
  useNavigation,
  useRoute,
} from '@react-navigation/native';
import type {RootNavigation, RootRoute} from '../../navigation/types';
import {errorCode, learnerErrorMessage} from '../../utils/errorPayload';
import React, {useCallback, useEffect, useRef, useState} from 'react';
import {Pressable, ScrollView, StatusBar, Text, View} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {Palette, useResponsiveLayout} from '../../constants/designSystem';
import {
  CAN_REDEEM_COURSE_ACCESS_CODE,
  CAN_START_COIN_CHECKOUT,
} from '../../constants/distribution';
import {LOCAL_DEMO_ENABLED} from '../../config/runtime';
import {
  DEMO_COURSE_ID,
  getDemoExperience,
  purchaseDemoCourse,
  redeemDemoCourseCode,
} from '../../services/demoExperience';
import type {DemoCoinPackage} from '../../services/demoExperience';
import {openCoinCheckout} from '../../services/coinCheckout';
import {
  getWallet,
  getCoinPackages,
  deleteCourseRating,
  purchaseCourse,
  quoteCoursePurchase,
  rateCourse,
  redeemCourseCode as redeemCourseAccessCode,
} from '../../services/roknApi';
import type {LoginReturnTo} from '../../navigation/types';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';
import {
  CourseBody,
  CourseHero,
  CourseIntro,
  CourseRatingAction,
  StickyCourseAction,
} from './details/sections';
import {
  CourseCodeRedemptionDialog,
  CoursePurchaseDialog,
  CourseRetentionDialog,
} from './details/PurchaseDialogs';
import type {DialogStep} from './details/PurchaseDialogs';
import {
  canChooseCourseAccess,
  selectCourseDetailsPresentation,
  selectCourseHeroHeight,
} from './details/selectors';
import {useCourseDetailsData} from './details/useCourseDetailsData';
import {useStickyCourseAction} from './details/useStickyCourseAction';
import styles from './details/styles';
import type {CoursePurchaseQuote} from '../../services/roknApi';
import {normalizeHumanIdentifier} from '../../utils/unicodeText';
import {trackProductEvent} from '../../services/productAnalytics';
import {useSelector} from 'react-redux';
import {
  extractApiToken,
  extractUserProfile,
} from '../../constants/helpers';
import type {RootState} from '../../store/store';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import {useAppActiveState} from '../../hooks/useAppActiveState';

const retentionShownCourses = new Set<string>();
const rememberRetentionOffer = (courseId: string) => {
  retentionShownCourses.delete(courseId);
  retentionShownCourses.add(courseId);
  while (retentionShownCourses.size > 64) {
    const oldest = retentionShownCourses.values().next().value;
    if (typeof oldest !== 'string') break;
    retentionShownCourses.delete(oldest);
  }
};

export default function CourseDetails() {
  const route = useRoute<RootRoute<'CourseDetails'>>();
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const layout = useResponsiveLayout();
  const appIsActive = useAppActiveState();
  const screenFocused = useIsFocused();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const storedProfile = extractUserProfile(storedUser);
  const hasStoredToken = Boolean(extractApiToken(storedUser));
  const identityKey = hasStoredToken
    ? String(storedProfile.id ?? storedProfile.user_id ?? 'authenticated')
    : 'guest';
  const courseId = String(
    route.params?.courseId || (LOCAL_DEMO_ENABLED ? DEMO_COURSE_ID : ''),
  );
  const isDemoCourse = LOCAL_DEMO_ENABLED && courseId === DEMO_COURSE_ID;
  const [activeTab, setActiveTab] = useState<'about' | 'outline'>('about');
  const [dialogStep, setDialogStep] = useState<DialogStep>(null);
  const [selectedPlanCode, setSelectedPlanCode] = useState<string>('basic');
  const [retentionQueued, setRetentionQueued] = useState(false);
  const [retentionVisible, setRetentionVisible] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const [courseCode, setCourseCode] = useState('');
  const [purchaseCouponCode, setPurchaseCouponCode] = useState('');
  const [couponQuote, setCouponQuote] = useState<CoursePurchaseQuote | null>(
    null,
  );
  const [couponBusy, setCouponBusy] = useState(false);
  const [redemptionVisible, setRedemptionVisible] = useState(false);
  const [grantActivated, setGrantActivated] = useState(false);
  const [codeBusy, setCodeBusy] = useState(false);
  const [ratingBusy, setRatingBusy] = useState(false);
  const [submittedRating, setSubmittedRating] = useState<number | null>(null);
  const autoPurchaseHandledRef = useRef(false);
  const [purchaseRestoreState, setPurchaseRestoreState] = useState<{
    key: string;
    status: 'quoting' | 'ready' | 'failed';
  } | null>(null);
  const [purchasePlanRestoreKey, setPurchasePlanRestoreKey] = useState('');
  const autoRedemptionHandledRef = useRef(false);
  const purchaseRestoreRequestRef = useRef('');
  const commerceInFlightRef = useRef(false);
  const ratingInFlightRef = useRef(false);
  const activeCourseIdRef = useRef(courseId);
  const courseOperationGenerationRef = useRef(0);
  const courseDetailsFocusedOnceRef = useRef(false);
  const previousAppActiveRef = useRef(appIsActive);
  const reelsNavigationFlightRef = useRef(false);
  const purchaseCompletedTrackedRef = useRef(false);
  const selectedPlanCodeRef = useRef(selectedPlanCode);
  const purchaseCouponCodeRef = useRef(purchaseCouponCode);
  activeCourseIdRef.current = courseId;
  selectedPlanCodeRef.current = selectedPlanCode;
  purchaseCouponCodeRef.current = purchaseCouponCode;

  const ownsCourseOperation = useCallback(
    (expectedCourseId: string, generation: number) =>
      activeCourseIdRef.current === expectedCourseId &&
      courseOperationGenerationRef.current === generation,
    [],
  );

  useEffect(() => {
    courseOperationGenerationRef.current += 1;
    commerceInFlightRef.current = false;
    ratingInFlightRef.current = false;
    purchaseRestoreRequestRef.current = '';
    setBusy(false);
    setCouponBusy(false);
    setCodeBusy(false);
    setRatingBusy(false);
    setNotice('');
    setDialogStep(null);
    setRedemptionVisible(false);
    setCouponQuote(null);
    setPurchaseRestoreState(null);
    setPurchaseCouponCode('');
    selectedPlanCodeRef.current = 'basic';
    setSelectedPlanCode('basic');
    setPurchasePlanRestoreKey('');
    return () => {
      courseOperationGenerationRef.current += 1;
    };
  }, [courseId, identityKey]);

  const {
    experience,
    reloadRemote,
    remoteBalance,
    remoteCourse,
    remoteError,
    remoteLoading,
    remoteOwned,
    remotePaidBalance,
    remotePackages,
    remoteSession,
    remoteRewardBalance,
    remoteRewardContributionCap,
    remoteSpendableBalance,
    setExperience,
    setRemoteBalance,
    setRemoteCourse,
    setRemoteOwned,
    setRemotePaidBalance,
    setRemotePackages,
    setRemoteSpendableBalance,
    setRemoteRewardBalance,
  } = useCourseDetailsData({
    courseId,
    identityKey,
    isDemoCourse,
    setNotice,
  });

  const {
    accessPlans,
    balance,
    courseDescription,
    coursePrice,
    courseTitle,
    durationMinutes,
    hasPreview,
    owned,
    planSpendableBalances,
    pageReady,
    packages,
    previewReelCount,
    primaryActionLabel,
    purchasePrice,
    rewardContributionLimit,
    ratingAverage,
    ratingsCount,
    selectedPlan,
    spendableBalance,
    studentsCount,
  } = selectCourseDetailsPresentation({
    courseId,
    experience,
    isDemoCourse,
    remoteBalance,
    remoteCourse,
    remoteError,
    remoteLoading,
    remoteOwned,
    remotePaidBalance,
    remotePackages,
    remoteSession,
    remoteRewardBalance,
    remoteRewardContributionCap,
    remoteSpendableBalance,
    routeParams: route.params,
    selectedPlanCode,
  });

  useFocusEffect(
    useCallback(() => {
      reelsNavigationFlightRef.current = false;
      if (courseDetailsFocusedOnceRef.current && !isDemoCourse) {
        reloadRemote();
      }
      courseDetailsFocusedOnceRef.current = true;
    }, [isDemoCourse, reloadRemote]),
  );

  useEffect(() => {
    const becameActive = appIsActive && !previousAppActiveRef.current;
    previousAppActiveRef.current = appIsActive;
    if (becameActive && screenFocused && !isDemoCourse) reloadRemote();
  }, [appIsActive, isDemoCourse, reloadRemote, screenFocused]);

  useEffect(() => {
    if (!accessPlans.length) return;
    const resumedPlanCode = String(route.params?.purchasePlanCode || '').trim();
    if (
      route.params?.openPurchase &&
      resumedPlanCode &&
      accessPlans.some(plan => plan.code === resumedPlanCode) &&
      purchasePlanRestoreKey !== `${courseId}|${resumedPlanCode}`
    ) {
      setPurchasePlanRestoreKey(`${courseId}|${resumedPlanCode}`);
      if (selectedPlanCode !== resumedPlanCode) {
        setSelectedPlanCode(resumedPlanCode);
      }
      return;
    }
    if (!accessPlans.some(plan => plan.code === selectedPlanCode)) {
      setSelectedPlanCode(accessPlans[0].code);
    }
  }, [
    accessPlans,
    courseId,
    purchasePlanRestoreKey,
    route.params?.openPurchase,
    route.params?.purchasePlanCode,
    selectedPlanCode,
  ]);

  useEffect(() => {
    setCouponQuote(null);
  }, [selectedPlanCode]);

  const appliedCoupon = Boolean(
    couponQuote &&
      couponQuote.accessPlanCode === selectedPlan?.code &&
      couponQuote.courseRevision === remoteCourse?.publishedRevision &&
      couponQuote.originalPrice === purchasePrice &&
      couponQuote.couponCode === normalizeHumanIdentifier(purchaseCouponCode),
  );
  const effectivePurchasePrice = appliedCoupon
    ? couponQuote?.finalPrice ?? purchasePrice
    : purchasePrice;
  const currentPaidBalance = isDemoCourse
    ? experience?.paidBalance ?? 0
    : remotePaidBalance ?? 0;
  const currentRewardBalance = isDemoCourse
    ? experience?.rewardBalance ?? 0
    : remoteRewardBalance ?? Math.max(0, balance - currentPaidBalance);
  const effectiveRewardContributionLimit = Math.min(
    rewardContributionLimit,
    Math.max(0, effectivePurchasePrice - (selectedPlan?.minimumPaidCoins ?? 0)),
  );
  const effectiveSpendableBalance =
    currentPaidBalance +
    Math.min(currentRewardBalance, effectiveRewardContributionLimit);
  const purchaseRestoreKey = [
    identityKey,
    courseId,
    route.params?.openPurchase ? 'purchase' : 'closed',
    String(route.params?.purchasePlanCode || '').trim(),
    normalizeHumanIdentifier(route.params?.purchaseCouponCode),
    selectedPlan?.code || '',
  ].join('|');
  const purchaseRestoreStatus =
    purchaseRestoreState?.key === purchaseRestoreKey
      ? purchaseRestoreState.status
      : 'idle';
  useEffect(() => {
    purchaseRestoreRequestRef.current = '';
    return () => {
      purchaseRestoreRequestRef.current = '';
    };
  }, [purchaseRestoreKey]);
  const effectiveShortfall = Math.max(
    0,
    effectivePurchasePrice - effectiveSpendableBalance,
  );
  const effectivePackages = packages.filter(
    item => item.coins >= effectiveShortfall,
  );
  const effectiveSufficientPackage = effectivePackages[0];
  const effectiveUsableCurrentBalance = Math.min(
    effectivePurchasePrice,
    effectiveSpendableBalance,
  );
  const effectiveRewardContributionPercent =
    effectivePurchasePrice > 0
      ? Math.floor(
          (effectiveRewardContributionLimit / effectivePurchasePrice) * 100,
        )
      : 0;

  useEffect(() => {
    setSubmittedRating(remoteCourse?.userRating ?? null);
  }, [remoteCourse?.userRating]);

  const submitRating = useCallback(
    async (rating: number) => {
      const expectedVersion = remoteCourse?.ratingVersion;
      if (
        ratingInFlightRef.current ||
        ratingBusy ||
        !remoteSession ||
        !owned ||
        !remoteCourse?.ratingEligible ||
        expectedVersion === undefined ||
        isDemoCourse
      )
        return;
      ratingInFlightRef.current = true;
      const operationCourseId = courseId;
      const operationGeneration = courseOperationGenerationRef.current;
      setRatingBusy(true);
      setNotice('');
      try {
        const result = await rateCourse(courseId, rating, expectedVersion);
        if (!ownsCourseOperation(operationCourseId, operationGeneration))
          return;
        setSubmittedRating(result.rating);
        setRemoteCourse(current =>
          current
            ? {
                ...current,
                userRating: result.rating,
                ratingVersion: result.version,
                ratingAverage: result.averageRating,
                ratingsCount: result.ratingsCount,
              }
            : current,
        );
      } catch (error) {
        if (!ownsCourseOperation(operationCourseId, operationGeneration))
          return;
        setNotice(learnerErrorMessage(error, 'تعذّر حفظ التقييم'));
        reloadRemote();
      } finally {
        if (ownsCourseOperation(operationCourseId, operationGeneration)) {
          ratingInFlightRef.current = false;
          setRatingBusy(false);
        }
      }
    },
    [
      courseId,
      isDemoCourse,
      owned,
      ratingBusy,
      reloadRemote,
      remoteCourse,
      remoteSession,
      setRemoteCourse,
      ownsCourseOperation,
    ],
  );

  const removeRating = useCallback(async () => {
    if (
      ratingInFlightRef.current ||
      ratingBusy ||
      !submittedRating ||
      !remoteCourse?.ratingVersion ||
      isDemoCourse
    )
      return;
    ratingInFlightRef.current = true;
    const operationCourseId = courseId;
    const operationGeneration = courseOperationGenerationRef.current;
    setRatingBusy(true);
    setNotice('');
    try {
      const result = await deleteCourseRating(
        courseId,
        remoteCourse.ratingVersion,
      );
      if (!ownsCourseOperation(operationCourseId, operationGeneration)) return;
      setSubmittedRating(null);
      setRemoteCourse(current =>
        current
          ? {
              ...current,
              userRating: null,
              ratingVersion: result.version,
              ratingAverage: result.averageRating,
              ratingsCount: result.ratingsCount,
            }
          : current,
      );
    } catch (error) {
      if (!ownsCourseOperation(operationCourseId, operationGeneration)) return;
      setNotice(learnerErrorMessage(error, 'تعذّر حذف التقييم'));
      reloadRemote();
    } finally {
      if (ownsCourseOperation(operationCourseId, operationGeneration)) {
        ratingInFlightRef.current = false;
        setRatingBusy(false);
      }
    }
  }, [
    courseId,
    isDemoCourse,
    ratingBusy,
    reloadRemote,
    remoteCourse?.ratingVersion,
    setRemoteCourse,
    submittedRating,
    ownsCourseOperation,
  ]);

  const heroHeight = selectCourseHeroHeight(layout);
  const showCourseAccessOptions = canChooseCourseAccess({
    isDemoCourse,
    owned,
    pageReady,
    remoteError,
    remoteSession,
  });
  const {onPrimaryActionLayout, onScroll, showStickyAction} =
    useStickyCourseAction(heroHeight);

  const openLoginForPurchase = useCallback(() => {
    const routePlanCode = String(route.params?.purchasePlanCode || '').trim();
    const planCode = accessPlans.some(plan => plan.code === routePlanCode)
      ? routePlanCode
      : '';
    const couponCode = normalizeHumanIdentifier(
      purchaseCouponCode || route.params?.purchaseCouponCode,
    );
    const returnTo: LoginReturnTo = {
      name: 'CourseDetails',
      params: {
        courseId,
        openPurchase: true,
        ...(planCode ? {purchasePlanCode: planCode} : {}),
        ...(couponCode ? {purchaseCouponCode: couponCode} : {}),
        coinPrice: coursePrice,
        title: courseTitle,
        description: courseDescription,
      },
    };
    openGuestLogin(navigation, returnTo);
  }, [
    accessPlans,
    courseDescription,
    courseId,
    coursePrice,
    courseTitle,
    navigation,
    purchaseCouponCode,
    route.params?.purchaseCouponCode,
    route.params?.purchasePlanCode,
  ]);

  const openLoginForCodeRedemption = useCallback(() => {
    const returnTo: LoginReturnTo = {
      name: 'CourseDetails',
      params: {
        courseId,
        openCodeRedemption: true,
        coinPrice: coursePrice,
        title: courseTitle,
        description: courseDescription,
      },
    };
    openGuestLogin(navigation, returnTo);
  }, [courseDescription, courseId, coursePrice, courseTitle, navigation]);

  const startCourse = () => {
    if (reelsNavigationFlightRef.current) return;
    reelsNavigationFlightRef.current = true;
    navigation.navigate('Reels', {
      courseId,
      ...(route.params?.resumeAfterPreview
        ? route.params?.resumeReelId
          ? {reelId: String(route.params.resumeReelId)}
          : {}
        : {}),
      coinPrice: coursePrice,
      title: courseTitle,
      description: courseDescription,
    });
  };

  const startPreview = (reelId?: string) => {
    if (reelsNavigationFlightRef.current) return;
    reelsNavigationFlightRef.current = true;
    if (!isDemoCourse) {
      void trackProductEvent({
        event_name: 'sample_started',
        screen_key: 'course_details',
        course_id: courseId,
      });
    }
    navigation.navigate('Reels', {
      courseId,
      ...(reelId ? {reelId} : {}),
      preview: true,
      previewCount: previewReelCount,
      coinPrice: coursePrice,
      title: courseTitle,
      description: courseDescription,
    });
  };

  const handlePurchaseAction = () => {
    setNotice('');
    if (owned) {
      startCourse();
      return;
    }
    if (!isDemoCourse && remoteSession === false) {
      openLoginForPurchase();
      return;
    }
    if (!CAN_START_COIN_CHECKOUT && (coursePrice ?? 0) > 0) {
      if (hasPreview) startPreview();
      else setNotice('سجّل الدخول بالحساب الذي فُتح عليه الكورس');
      return;
    }
    if (coursePrice === null) {
      setNotice('سعر الكورس لم يُنشر بعد\nلم نبدأ أي عملية شراء');
      return;
    }
    if (!isDemoCourse && coursePrice > 0 && remoteBalance === null) {
      setNotice('تعذّر التحقق من رصيدك\nحاول بعد لحظات');
      return;
    }
    if (!isDemoCourse) {
      void trackProductEvent({
        event_name: 'paywall_viewed',
        screen_key: 'course_details',
        course_id: courseId,
      });
    }
    setDialogStep(
      accessPlans.length > 1
        ? 'plans'
        : spendableBalance >= purchasePrice
        ? 'confirm'
        : 'topup',
    );
  };

  const handlePrimaryAction = () => {
    handlePurchaseAction();
  };

  useEffect(() => {
    autoPurchaseHandledRef.current = false;
    autoRedemptionHandledRef.current = false;
    purchaseCompletedTrackedRef.current = false;
    setCourseCode('');
    setPurchaseCouponCode('');
    setCouponQuote(null);
    setRedemptionVisible(false);
    setRetentionQueued(false);
    setRetentionVisible(false);
  }, [
    courseId,
    identityKey,
    route.params?.openPurchase,
    route.params?.purchaseCouponCode,
    route.params?.purchasePlanCode,
  ]);

  useEffect(() => {
    const resumedPlanCode = String(route.params?.purchasePlanCode || '').trim();
    const resumedCoupon = normalizeHumanIdentifier(
      route.params?.purchaseCouponCode,
    );
    if (
      !route.params?.openPurchase ||
      !CAN_START_COIN_CHECKOUT ||
      autoPurchaseHandledRef.current ||
      !pageReady ||
      owned ||
      (!isDemoCourse && remoteSession === null)
    ) {
      return;
    }
    if (!isDemoCourse && remoteSession === false) {
      autoPurchaseHandledRef.current = true;
      setNotice('');
      openLoginForPurchase();
      return;
    }
    if (resumedPlanCode) {
      const restoredPlanAvailable = accessPlans.some(
        plan => plan.code === resumedPlanCode,
      );
      if (!restoredPlanAvailable) {
        autoPurchaseHandledRef.current = true;
        setNotice('تغيّرت فئات الكورس\nاختر الفئة المناسبة');
        setDialogStep('plans');
        return;
      }
      if (selectedPlan?.code !== resumedPlanCode) return;
    }
    if (
      resumedCoupon &&
      (purchaseRestoreStatus === 'idle' || purchaseRestoreStatus === 'quoting')
    ) {
      return;
    }
    if (resumedCoupon && purchaseRestoreStatus === 'failed') {
      // Do not expose an enabled confirmation for the undiscounted price after
      // restoring a coupon failed. The learner can reopen the sheet and retry
      // the visible code deliberately.
      autoPurchaseHandledRef.current = true;
      return;
    }
    autoPurchaseHandledRef.current = true;
    if (purchaseRestoreStatus !== 'failed') setNotice('');
    if (coursePrice === null) {
      setNotice('سعر الكورس لم يُنشر بعد\nلم نبدأ أي عملية شراء');
      return;
    }
    if (!isDemoCourse && coursePrice > 0 && remoteBalance === null) {
      setNotice('تعذّر التحقق من رصيدك\nحاول بعد لحظات');
      return;
    }
    setDialogStep(
      !resumedPlanCode && accessPlans.length > 1
        ? 'plans'
        : effectiveSpendableBalance >= effectivePurchasePrice
        ? 'confirm'
        : 'topup',
    );
  }, [
    coursePrice,
    effectivePurchasePrice,
    effectiveSpendableBalance,
    isDemoCourse,
    navigation,
    openLoginForPurchase,
    owned,
    pageReady,
    remoteBalance,
    remoteSession,
    route.params?.openPurchase,
    route.params?.purchaseCouponCode,
    route.params?.purchasePlanCode,
    accessPlans,
    purchaseRestoreStatus,
    selectedPlan,
  ]);

  useEffect(() => {
    if (
      !CAN_REDEEM_COURSE_ACCESS_CODE ||
      !route.params?.openCodeRedemption ||
      autoRedemptionHandledRef.current ||
      !pageReady ||
      owned ||
      (!isDemoCourse && remoteSession === null)
    ) {
      return;
    }
    autoRedemptionHandledRef.current = true;
    setNotice('');
    if (!isDemoCourse && remoteSession === false) {
      openLoginForCodeRedemption();
      return;
    }
    setRedemptionVisible(true);
  }, [
    isDemoCourse,
    openLoginForCodeRedemption,
    owned,
    pageReady,
    remoteSession,
    route.params?.openCodeRedemption,
  ]);

  useEffect(() => {
    if (!retentionQueued || dialogStep !== null) return;
    const timer = setTimeout(() => {
      setRetentionQueued(false);
      if (!owned) setRetentionVisible(true);
    }, 180);
    return () => clearTimeout(timer);
  }, [dialogStep, owned, retentionQueued]);

  const closePurchaseDialog = () => {
    if (busy || couponBusy) return;
    const retentionKey = `${identityKey}:${courseId}`;
    const shouldOfferTasks =
      dialogStep !== null &&
      dialogStep !== 'success' &&
      !owned &&
      !retentionShownCourses.has(retentionKey);
    if (shouldOfferTasks) {
      rememberRetentionOffer(retentionKey);
      setRetentionQueued(true);
    }
    if (dialogStep !== null && dialogStep !== 'success' && !isDemoCourse) {
      void trackProductEvent({
        event_name: 'paywall_dismissed',
        screen_key: 'course_details',
        course_id: courseId,
      });
    }
    setDialogStep(null);
  };

  const activateSelectedCourse = async (
    operationCourseId: string,
    operationGeneration: number,
  ) => {
    if (isDemoCourse) {
      const result = await purchaseDemoCourse(
        courseId,
        purchasePrice,
        (selectedPlan?.code as 'basic' | 'guided' | 'mentor') || 'basic',
      );
      if (!ownsCourseOperation(operationCourseId, operationGeneration)) {
        return false;
      }
      setExperience(result.state);
      setDialogStep(result.purchased ? 'success' : 'topup');
      return result.purchased;
    }

    void trackProductEvent({
      event_name: 'purchase_started',
      screen_key: 'course_details',
      course_id: courseId,
    });

    const result = await purchaseCourse(
      courseId,
      selectedPlan?.code,
      appliedCoupon ? couponQuote?.couponCode : undefined,
      effectivePurchasePrice,
      remoteCourse?.publishedRevision,
    );
    if (!ownsCourseOperation(operationCourseId, operationGeneration)) {
      return false;
    }
    setRemoteBalance(result.balance);
    setRemoteSpendableBalance(result.spendableBalance);
    setRemotePaidBalance(result.paidBalance);
    setRemoteRewardBalance(result.rewardBalance);
    if (result.kind === 'success') {
      if (!purchaseCompletedTrackedRef.current) {
        purchaseCompletedTrackedRef.current = true;
        void trackProductEvent({
          event_name: 'purchase_completed',
          screen_key: 'course_details',
          course_id: courseId,
        });
      }
      setRemoteOwned(true);
      setDialogStep('success');
      return true;
    }

    // Keep the hydrated catalogue already loaded for this distribution. The
    // insufficient-balance response is only a recommendation and does not
    // carry native product identities or store-authoritative display prices.
    setNotice('رصيدك لا يكفي\nاختر باقة شحن لإكمال الشراء');
    setDialogStep('topup');
    return false;
  };

  const applyPurchaseCoupon = async () => {
    const normalized = normalizeHumanIdentifier(purchaseCouponCode);
    if (!normalized || couponBusy || busy) return;
    if (isDemoCourse) {
      setNotice('كود الخصم غير متاح الآن');
      return;
    }
    const operationCourseId = courseId;
    const operationGeneration = courseOperationGenerationRef.current;
    const operationPlanCode = selectedPlan?.code;
    if (!operationPlanCode) return;
    setCouponBusy(true);
    setNotice('');
    try {
      const quote = await quoteCoursePurchase(
        courseId,
        operationPlanCode,
        normalized,
        remoteCourse?.publishedRevision,
      );
      if (
        !ownsCourseOperation(operationCourseId, operationGeneration) ||
        selectedPlanCodeRef.current !== operationPlanCode ||
        normalizeHumanIdentifier(purchaseCouponCodeRef.current) !== normalized
      ) {
        return;
      }
      setPurchaseCouponCode(quote.couponCode);
      setCouponQuote(quote);
      const paid = remotePaidBalance ?? 0;
      const reward = remoteRewardBalance ?? Math.max(0, balance - paid);
      const allowedReward = Math.min(
        rewardContributionLimit,
        Math.max(0, quote.finalPrice - (selectedPlan?.minimumPaidCoins ?? 0)),
      );
      const quotedSpendable = paid + Math.min(reward, allowedReward);
      setDialogStep(quotedSpendable >= quote.finalPrice ? 'confirm' : 'topup');
    } catch (error: unknown) {
      if (
        !ownsCourseOperation(operationCourseId, operationGeneration) ||
        selectedPlanCodeRef.current !== operationPlanCode ||
        normalizeHumanIdentifier(purchaseCouponCodeRef.current) !== normalized
      ) {
        return;
      }
      setCouponQuote(null);
      setNotice(learnerErrorMessage(error, 'الكود غير صحيح أو انتهت صلاحيته'));
    } finally {
      if (ownsCourseOperation(operationCourseId, operationGeneration)) {
        setCouponBusy(false);
      }
    }
  };

  useEffect(() => {
    const resumedCoupon = normalizeHumanIdentifier(
      route.params?.purchaseCouponCode,
    );
    const resumedPlanCode = String(route.params?.purchasePlanCode || '').trim();
    if (
      !route.params?.openPurchase ||
      !resumedCoupon ||
      purchaseRestoreStatus !== 'idle' ||
      !pageReady ||
      remoteSession !== true ||
      !selectedPlan ||
      (resumedPlanCode && selectedPlan.code !== resumedPlanCode)
    ) {
      return undefined;
    }

    const requestKey = purchaseRestoreKey;
    const operationPlanCode = selectedPlan.code;
    purchaseRestoreRequestRef.current = requestKey;
    setPurchaseRestoreState({key: purchaseRestoreKey, status: 'quoting'});
    setPurchaseCouponCode(resumedCoupon);
    setCouponBusy(true);
    void quoteCoursePurchase(
      courseId,
      operationPlanCode,
      resumedCoupon,
      remoteCourse?.publishedRevision,
    )
      .then(quote => {
        if (
          purchaseRestoreRequestRef.current !== requestKey ||
          selectedPlanCodeRef.current !== operationPlanCode
        ) {
          return;
        }
        setPurchaseCouponCode(quote.couponCode);
        setCouponQuote(quote);
        setPurchaseRestoreState({key: purchaseRestoreKey, status: 'ready'});
      })
      .catch(error => {
        if (
          purchaseRestoreRequestRef.current !== requestKey ||
          selectedPlanCodeRef.current !== operationPlanCode
        ) {
          return;
        }
        setCouponQuote(null);
        setPurchaseRestoreState({key: purchaseRestoreKey, status: 'failed'});
        setNotice(
          learnerErrorMessage(error, 'تعذّر إعادة حساب الخصم\nراجعه ثم حاول'),
        );
      })
      .finally(() => {
        if (purchaseRestoreRequestRef.current === requestKey) {
          setCouponBusy(false);
        }
      });
    return undefined;
  }, [
    courseId,
    pageReady,
    remoteCourse?.publishedRevision,
    remoteSession,
    purchaseRestoreKey,
    purchaseRestoreStatus,
    route.params?.openPurchase,
    route.params?.purchaseCouponCode,
    route.params?.purchasePlanCode,
    selectedPlan,
  ]);

  const buyCoins = async (coinPackage: DemoCoinPackage) => {
    if (
      !CAN_START_COIN_CHECKOUT ||
      coinPackage.coins < effectiveShortfall ||
      commerceInFlightRef.current
    ) {
      return;
    }
    const operationCourseId = courseId;
    const operationGeneration = courseOperationGenerationRef.current;
    commerceInFlightRef.current = true;
    setBusy(true);
    setNotice('');
    try {
      const result = await openCoinCheckout(coinPackage, {
        returnTo: {
          name: 'CourseDetails',
          params: {
            courseId,
            openPurchase: true,
            ...(selectedPlan?.code
              ? {purchasePlanCode: selectedPlan.code}
              : {}),
            ...(appliedCoupon && couponQuote?.couponCode
              ? {purchaseCouponCode: couponQuote.couponCode}
              : {}),
          },
        },
      });
      if (!ownsCourseOperation(operationCourseId, operationGeneration)) return;
      if (result.cancelled) {
        setNotice(
          result.pending
            ? 'أُغلقت صفحة الدفع\nسنراجع العملية تلقائيًا'
            : 'لم يكتمل الدفع\nيمكنك المحاولة مرة أخرى',
        );
      } else if (result.pending) {
        setNotice('الدفع قيد التأكيد\nسنحدّث الرصيد تلقائيًا');
      } else if (result.success) {
        if (result.demo) {
          const state = await getDemoExperience();
          if (!ownsCourseOperation(operationCourseId, operationGeneration))
            return;
          setExperience(state);
          const paid = state.paidBalance ?? 0;
          const reward =
            state.rewardBalance ?? Math.max(0, state.balance - paid);
          const allowedReward = Math.min(
            rewardContributionLimit,
            Math.max(
              0,
              effectivePurchasePrice - (selectedPlan?.minimumPaidCoins ?? 0),
            ),
          );
          const refreshedSpendable = paid + Math.min(reward, allowedReward);
          setNotice('تم شحن رصيدك\nراجع الإجمالي ثم أكد الشراء');
          setDialogStep(
            refreshedSpendable >= effectivePurchasePrice ? 'confirm' : 'topup',
          );
        } else {
          try {
            const [wallet, refreshedQuote] = await Promise.all([
              getWallet(),
              quoteCoursePurchase(
                courseId,
                selectedPlan?.code,
                appliedCoupon ? couponQuote?.couponCode || '' : '',
                remoteCourse?.publishedRevision,
              ),
            ]);
            if (!ownsCourseOperation(operationCourseId, operationGeneration))
              return;
            setRemoteBalance(wallet.balance);
            setRemoteSpendableBalance(wallet.spendableBalance);
            setRemotePaidBalance(wallet.paidBalance);
            setRemoteRewardBalance(wallet.rewardBalance);
            if (refreshedQuote.originalPrice !== purchasePrice) {
              setCouponQuote(null);
              reloadRemote();
              setNotice('تغيّر السعر\nراجع الفئات قبل الشراء');
              setDialogStep('plans');
              return;
            }
            setCouponQuote(refreshedQuote.couponCode ? refreshedQuote : null);
            if (refreshedQuote.couponCode) {
              setPurchaseCouponCode(refreshedQuote.couponCode);
            }
            const allowedReward = Math.min(
              rewardContributionLimit,
              Math.max(
                0,
                refreshedQuote.finalPrice -
                  (selectedPlan?.minimumPaidCoins ?? 0),
              ),
            );
            const refreshedSpendable =
              wallet.paidBalance +
              Math.min(wallet.rewardBalance, allowedReward);
            setNotice('تم شحن رصيدك\nراجع الإجمالي ثم أكد الشراء');
            setDialogStep(
              refreshedSpendable >= refreshedQuote.finalPrice
                ? 'confirm'
                : 'topup',
            );
          } catch {
            if (!ownsCourseOperation(operationCourseId, operationGeneration))
              return;
            if (appliedCoupon) {
              setCouponQuote(null);
            }
            reloadRemote();
            setDialogStep('topup');
            setNotice('تم تأكيد الشحن\nنحدّث الرصيد والسعر\nلا تدفع مرة أخرى');
          }
        }
      } else {
        setNotice('لم يكتمل الدفع\nيمكنك المحاولة مرة أخرى');
      }
    } catch (error) {
      if (!ownsCourseOperation(operationCourseId, operationGeneration)) return;
      if (
        ['package_terms_changed', 'package_not_available'].includes(
          errorCode(error),
        )
      ) {
        // The server rejected this exact package snapshot. Remove it before
        // releasing the single-flight guard so the next tap cannot replay the
        // same stale price/coin contract with a fresh idempotency key.
        const operationPlanCode = selectedPlan?.code;
        const operationCouponCode =
          appliedCoupon && couponQuote?.couponCode
            ? couponQuote.couponCode
            : '';
        setRemotePackages([]);
        setCouponQuote(null);
        setNotice('تغيّرت تفاصيل الباقة\nنحدّث خيارات الدفع');
        try {
          const [refreshedPackages, refreshedQuote] = await Promise.all([
            getCoinPackages(),
            quoteCoursePurchase(
              operationCourseId,
              operationPlanCode,
              operationCouponCode,
              remoteCourse?.publishedRevision,
            ),
          ]);
          if (!ownsCourseOperation(operationCourseId, operationGeneration)) {
            return;
          }
          setRemotePackages(refreshedPackages);
          if (refreshedQuote.originalPrice !== purchasePrice) {
            setPurchaseCouponCode('');
            setDialogStep('plans');
            setNotice('تغيّر السعر\nراجع الفئات قبل الشراء');
          } else {
            setCouponQuote(
              refreshedQuote.couponCode ? refreshedQuote : null,
            );
            if (refreshedQuote.couponCode) {
              setPurchaseCouponCode(refreshedQuote.couponCode);
            }
            setDialogStep('topup');
            setNotice('تم تحديث باقات الشحن\nاختر الباقة من جديد');
          }
        } catch {
          if (!ownsCourseOperation(operationCourseId, operationGeneration)) {
            return;
          }
          setDialogStep('topup');
          setNotice('تعذّر تحديث باقات الشحن\nحدّث الصفحة ثم اختر من جديد');
        }
        reloadRemote();
        return;
      }
      setNotice('تعذّر فتح الدفع\nمكانك ورصيدك محفوظان\nحاول مرة أخرى');
    } finally {
      if (ownsCourseOperation(operationCourseId, operationGeneration)) {
        commerceInFlightRef.current = false;
        setBusy(false);
      }
    }
  };

  const confirmPurchase = async () => {
    if (
      (!CAN_START_COIN_CHECKOUT && purchasePrice > 0) ||
      commerceInFlightRef.current
    ) {
      return;
    }
    const operationCourseId = courseId;
    const operationGeneration = courseOperationGenerationRef.current;
    commerceInFlightRef.current = true;
    setGrantActivated(false);
    setBusy(true);
    setNotice('');
    try {
      await activateSelectedCourse(operationCourseId, operationGeneration);
    } catch (error) {
      if (!ownsCourseOperation(operationCourseId, operationGeneration)) return;
      reloadRemote();
      if (
        ['course_terms_changed', 'course_plan_unavailable'].includes(
          errorCode(error),
        )
      ) {
        setCouponQuote(null);
        setDialogStep('plans');
        setNotice('تغيّرت تفاصيل الفئة\nراجعها قبل الشراء');
      } else {
        setNotice('تعذّر تأكيد فتح الكورس\nحدّث الصفحة قبل المحاولة مرة أخرى');
      }
    } finally {
      if (ownsCourseOperation(operationCourseId, operationGeneration)) {
        commerceInFlightRef.current = false;
        setBusy(false);
      }
    }
  };

  const redeemCourseCode = async () => {
    if (!CAN_REDEEM_COURSE_ACCESS_CODE) return;
    setGrantActivated(false);
    const normalizedCode = normalizeHumanIdentifier(courseCode);
    if (!normalizedCode) {
      setNotice('اكتب الكود أولًا');
      return;
    }
    if (!isDemoCourse && remoteSession === false) {
      setDialogStep(null);
      setRedemptionVisible(false);
      openLoginForCodeRedemption();
      return;
    }
    if (commerceInFlightRef.current) return;
    const operationCourseId = courseId;
    const operationGeneration = courseOperationGenerationRef.current;
    commerceInFlightRef.current = true;
    setCodeBusy(true);
    setNotice('');
    try {
      if (isDemoCourse) {
        const result = await redeemDemoCourseCode(normalizedCode, courseId);
        if (!ownsCourseOperation(operationCourseId, operationGeneration))
          return;
        if (!result.redeemed) {
          setNotice('الكود غير صحيح أو لم يعد متاحًا');
          return;
        }
        setExperience(result.state);
        setCourseCode('');
        setRedemptionVisible(false);
        setDialogStep('success');
        return;
      }
      const result = await redeemCourseAccessCode(normalizedCode, courseId);
      if (!ownsCourseOperation(operationCourseId, operationGeneration)) return;
      if (result.courseId && result.courseId !== courseId) {
        setNotice(
          result.courseName
            ? `هذا الكود مخصص لكورس «${result.courseName}»`
            : 'هذا الكود مخصص لكورس آخر',
        );
        return;
      }
      setRemoteOwned(true);
      if (!result.alreadyEnrolled) {
        void trackProductEvent({
          event_name: 'grant_claimed',
          screen_key: 'course_details',
          course_id: courseId,
        });
      }
      setGrantActivated(
        result.accessType === 'scholarship' && !result.alreadyEnrolled,
      );
      setCourseCode('');
      setRedemptionVisible(false);
      setDialogStep('success');
    } catch (error: unknown) {
      if (!ownsCourseOperation(operationCourseId, operationGeneration)) return;
      setNotice(
        learnerErrorMessage(
          error,
          'تعذّر تأكيد تفعيل الكود\nحدّث الصفحة قبل المحاولة مرة أخرى',
        ),
      );
    } finally {
      if (ownsCourseOperation(operationCourseId, operationGeneration)) {
        commerceInFlightRef.current = false;
        setCodeBusy(false);
      }
    }
  };

  return (
    <View style={styles.screen}>
      <StatusBar barStyle="light-content" backgroundColor={Palette.canvas} />
      <ScrollView
        contentContainerStyle={{paddingBottom: insets.bottom + 112}}
        onScroll={onScroll}
        scrollEventThrottle={80}
        showsVerticalScrollIndicator={false}>
        <CourseHero
          courseTitle={courseTitle}
          gutter={layout.gutter}
          heroHeight={heroHeight}
          isDemoCourse={isDemoCourse}
          maxContentWidth={layout.maxContentWidth}
          onBack={() => goBackOrHome(navigation)}
          remoteCourse={remoteCourse}
          topInset={insets.top}
        />

        <View
          style={[
            styles.content,
            {
              paddingHorizontal: layout.gutter,
              maxWidth: layout.maxContentWidth,
            },
          ]}>
          <CourseIntro
            courseDescription={courseDescription}
            durationMinutes={durationMinutes}
            hasPreview={hasPreview}
            onPrimaryAction={handlePrimaryAction}
            onPrimaryActionLayout={onPrimaryActionLayout}
            onPreview={() => startPreview()}
            owned={owned}
            pageReady={pageReady}
            primaryActionLabel={primaryActionLabel}
            ratingAverage={ratingAverage}
            ratingsCount={ratingsCount}
            remoteError={remoteError}
            studentsCount={studentsCount}
          />
          <CourseRatingAction
            busy={ratingBusy}
            editable={remoteCourse?.ratingEligible === true}
            onDelete={removeRating}
            onRate={submitRating}
            rating={submittedRating}
            visible={
              !isDemoCourse &&
              pageReady &&
              owned &&
              remoteSession === true &&
              (remoteCourse?.ratingEligible === true ||
                submittedRating !== null)
            }
          />
          {remoteCourse?.fromCache === true && (
            <Pressable
              accessibilityRole="button"
              onPress={reloadRemote}
              style={({pressed}) => [
                styles.cachedDetailsNotice,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.cachedDetailsText}>
                نعرض آخر تفاصيل محفوظة
              </Text>
              <Text style={styles.cachedDetailsAction}>إعادة المحاولة</Text>
            </Pressable>
          )}
          {!!notice && dialogStep === null && !redemptionVisible && (
            <Text style={[styles.notice, styles.inlineNotice]}>{notice}</Text>
          )}
          <CourseBody
            activeTab={activeTab}
            isDemoCourse={isDemoCourse}
            onPreviewSelect={startPreview}
            onRetry={reloadRemote}
            onTabChange={setActiveTab}
            owned={owned}
            remoteCourse={remoteCourse}
            remoteError={remoteError}
            remoteLoading={remoteLoading}
          />
        </View>
      </ScrollView>

      <StickyCourseAction
        bottomInset={insets.bottom}
        label={primaryActionLabel}
        onPress={handlePrimaryAction}
        visible={
          showStickyAction && pageReady && !remoteError && dialogStep === null
        }
      />

      <CourseCodeRedemptionDialog
        bottomInset={insets.bottom}
        codeBusy={codeBusy}
        courseCode={courseCode}
        isTablet={layout.isTablet}
        notice={notice}
        onClose={() => {
          if (codeBusy) return;
          setNotice('');
          setRedemptionVisible(false);
        }}
        onCourseCodeChange={setCourseCode}
        onRedeemCourseCode={redeemCourseCode}
        visible={redemptionVisible}
      />

      <CoursePurchaseDialog
        accessPlans={accessPlans}
        balance={balance}
        bottomInset={insets.bottom}
        busy={busy}
        codeBusy={codeBusy}
        courseTitle={courseTitle}
        courseCode={courseCode}
        courseCodeEnabled={
          CAN_REDEEM_COURSE_ACCESS_CODE && showCourseAccessOptions
        }
        couponApplied={appliedCoupon}
        couponBusy={couponBusy}
        couponCode={purchaseCouponCode}
        couponDiscountAmount={
          appliedCoupon ? couponQuote?.discountAmount ?? 0 : 0
        }
        dialogStep={dialogStep}
        grantActivated={grantActivated}
        isTablet={layout.isTablet}
        notice={notice}
        onApplyCoupon={applyPurchaseCoupon}
        onBuyCoins={buyCoins}
        onChangePlan={() => {
          setNotice('');
          setDialogStep('plans');
        }}
        onCouponCodeChange={value => {
          setPurchaseCouponCode(normalizeHumanIdentifier(value));
          setCouponQuote(null);
        }}
        onClose={closePurchaseDialog}
        onConfirmPurchase={confirmPurchase}
        onCourseCodeChange={setCourseCode}
        onRedeemCourseCode={redeemCourseCode}
        onSelectPlan={plan => {
          setCouponQuote(null);
          setSelectedPlanCode(plan.code);
          setDialogStep(
            (planSpendableBalances[plan.code] ?? 0) >= plan.priceCoins
              ? 'confirm'
              : 'topup',
          );
        }}
        onSuccessStart={() => {
          setDialogStep(null);
          startCourse();
        }}
        packages={effectivePackages}
        originalPurchasePrice={purchasePrice}
        purchasePrice={effectivePurchasePrice}
        rewardContributionLimit={effectiveRewardContributionLimit}
        rewardContributionPercent={effectiveRewardContributionPercent}
        selectedPlan={selectedPlan}
        shortfall={effectiveShortfall}
        sufficientPackage={effectiveSufficientPackage}
        usableCurrentBalance={effectiveUsableCurrentBalance}
      />

      <CourseRetentionDialog
        bottomInset={insets.bottom}
        isTablet={layout.isTablet}
        onClose={() => setRetentionVisible(false)}
        onOpenWallet={() => navigation.navigate('Wallet')}
        owned={owned}
        retentionVisible={retentionVisible}
      />
    </View>
  );
}
