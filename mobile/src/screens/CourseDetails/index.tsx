import {
  useFocusEffect,
  useNavigation,
  useRoute,
} from '@react-navigation/native';
import type {RootNavigation, RootRoute} from '../../navigation/types';
import {learnerErrorMessage} from '../../utils/errorPayload';
import React, {useCallback, useEffect, useRef, useState} from 'react';
import {ScrollView, StatusBar, Text, View} from 'react-native';
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
  const autoRedemptionHandledRef = useRef(false);
  const commerceInFlightRef = useRef(false);
  const ratingInFlightRef = useRef(false);
  const courseDetailsFocusedOnceRef = useRef(false);
  const purchaseCompletedTrackedRef = useRef(false);

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
    setRemoteSpendableBalance,
    setRemoteRewardBalance,
  } = useCourseDetailsData({courseId, isDemoCourse, setNotice});

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
      if (courseDetailsFocusedOnceRef.current && !isDemoCourse) {
        reloadRemote();
      }
      courseDetailsFocusedOnceRef.current = true;
    }, [isDemoCourse, reloadRemote]),
  );

  useEffect(() => {
    if (!accessPlans.length) return;
    if (!accessPlans.some(plan => plan.code === selectedPlanCode)) {
      setSelectedPlanCode(accessPlans[0].code);
    }
  }, [accessPlans, selectedPlanCode]);

  useEffect(() => {
    setCouponQuote(null);
  }, [selectedPlanCode]);

  const appliedCoupon = Boolean(
    couponQuote &&
      couponQuote.accessPlanCode === selectedPlan?.code &&
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
      setRatingBusy(true);
      setNotice('');
      try {
        const result = await rateCourse(courseId, rating, expectedVersion);
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
        setNotice(learnerErrorMessage(error, 'تعذّر حفظ التقييم'));
        reloadRemote();
      } finally {
        ratingInFlightRef.current = false;
        setRatingBusy(false);
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
    setRatingBusy(true);
    setNotice('');
    try {
      const result = await deleteCourseRating(
        courseId,
        remoteCourse.ratingVersion,
      );
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
      setNotice(learnerErrorMessage(error, 'تعذّر حذف التقييم'));
      reloadRemote();
    } finally {
      ratingInFlightRef.current = false;
      setRatingBusy(false);
    }
  }, [
    courseId,
    isDemoCourse,
    ratingBusy,
    reloadRemote,
    remoteCourse?.ratingVersion,
    setRemoteCourse,
    submittedRating,
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
    const returnTo: LoginReturnTo = {
      name: 'CourseDetails',
      params: {
        courseId,
        openPurchase: true,
        coinPrice: coursePrice,
        title: courseTitle,
        description: courseDescription,
      },
    };
    navigation.navigate('Login', {returnTo});
  }, [courseDescription, courseId, coursePrice, courseTitle, navigation]);

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
    navigation.navigate('Login', {returnTo});
  }, [courseDescription, courseId, coursePrice, courseTitle, navigation]);

  const startCourse = () =>
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

  const startPreview = (reelId?: string) => {
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
  }, [courseId]);

  useEffect(() => {
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
    autoPurchaseHandledRef.current = true;
    setNotice('');
    if (!isDemoCourse && remoteSession === false) {
      openLoginForPurchase();
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
    setDialogStep(
      accessPlans.length > 1
        ? 'plans'
        : spendableBalance >= purchasePrice
        ? 'confirm'
        : 'topup',
    );
  }, [
    spendableBalance,
    coursePrice,
    isDemoCourse,
    navigation,
    openLoginForPurchase,
    owned,
    pageReady,
    remoteBalance,
    remoteSession,
    route.params?.openPurchase,
    accessPlans.length,
    purchasePrice,
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
    const shouldOfferTasks =
      dialogStep !== null &&
      dialogStep !== 'success' &&
      !owned &&
      !retentionShownCourses.has(courseId);
    if (shouldOfferTasks) {
      rememberRetentionOffer(courseId);
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

  const activateSelectedCourse = async () => {
    if (isDemoCourse) {
      const result = await purchaseDemoCourse(
        courseId,
        purchasePrice,
        (selectedPlan?.code as 'basic' | 'guided' | 'mentor') || 'basic',
      );
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
    );
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
    setNotice('لم يصل تأكيد الرصيد بعد\nلا تدفع مرة أخرى\nحاول بعد لحظات');
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
    setCouponBusy(true);
    setNotice('');
    try {
      const quote = await quoteCoursePurchase(
        courseId,
        selectedPlan?.code,
        normalized,
      );
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
      setCouponQuote(null);
      setNotice(learnerErrorMessage(error, 'الكود غير صحيح أو انتهت صلاحيته'));
    } finally {
      setCouponBusy(false);
    }
  };

  const buyCoins = async (coinPackage: DemoCoinPackage) => {
    if (
      !CAN_START_COIN_CHECKOUT ||
      coinPackage.coins < effectiveShortfall ||
      commerceInFlightRef.current
    ) {
      return;
    }
    commerceInFlightRef.current = true;
    setBusy(true);
    setNotice('');
    try {
      const result = await openCoinCheckout(coinPackage, {
        returnTo: {
          name: 'CourseDetails',
          params: {courseId, openPurchase: true},
        },
      });
      if (result.cancelled) {
        setNotice('أُغلقت صفحة الدفع\nسنراجع العملية تلقائيًا');
      } else if (result.pending) {
        setNotice('الدفع قيد التأكيد\nسنحدّث الرصيد تلقائيًا');
      } else if (result.success) {
        if (result.demo) {
          const state = await getDemoExperience();
          setExperience(state);
          await activateSelectedCourse();
        } else {
          try {
            const wallet = await getWallet();
            setRemoteBalance(wallet.balance);
            setRemoteSpendableBalance(wallet.spendableBalance);
            setRemotePaidBalance(wallet.paidBalance);
            setRemoteRewardBalance(wallet.rewardBalance);
            await activateSelectedCourse();
          } catch {
            setDialogStep(null);
            setNotice(
              'وصل تأكيد الدفع\nسيظهر الرصيد بعد لحظات\nلا تدفع مرة أخرى',
            );
          }
        }
      }
    } catch {
      setNotice('تعذّر فتح الدفع\nمكانك ورصيدك محفوظان\nحاول مرة أخرى');
    } finally {
      commerceInFlightRef.current = false;
      setBusy(false);
    }
  };

  const confirmPurchase = async () => {
    if (
      (!CAN_START_COIN_CHECKOUT && purchasePrice > 0) ||
      commerceInFlightRef.current
    ) {
      return;
    }
    commerceInFlightRef.current = true;
    setGrantActivated(false);
    setBusy(true);
    setNotice('');
    try {
      await activateSelectedCourse();
    } catch {
      setNotice('تعذّر فتح الكورس\nرصيدك لم يتغير');
    } finally {
      commerceInFlightRef.current = false;
      setBusy(false);
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
    commerceInFlightRef.current = true;
    setCodeBusy(true);
    setNotice('');
    try {
      if (isDemoCourse) {
        const result = await redeemDemoCourseCode(normalizedCode, courseId);
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
      setNotice(
        learnerErrorMessage(error, 'تعذّر تفعيل الكود\nلم يتغير رصيدك'),
      );
    } finally {
      commerceInFlightRef.current = false;
      setCodeBusy(false);
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
        visible={showStickyAction && pageReady && dialogStep === null}
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
