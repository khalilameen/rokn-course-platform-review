import {useNavigation, useRoute} from '@react-navigation/native';
import type {RootNavigation, RootRoute} from '../../navigation/types';
import {errorPayload} from '../../utils/errorPayload';
import React, {useCallback, useEffect, useRef, useState} from 'react';
import {ScrollView, StatusBar, Text, View} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {Palette, useResponsiveLayout} from '../../constants/designSystem';
import {
  CAN_REDEEM_COURSE_ACCESS_CODE,
  CAN_START_EXTERNAL_CHECKOUT,
} from '../../constants/distribution';
import {ECONOMY_CONFIG} from '../../config/economy';
import {LOCAL_DEMO_ENABLED} from '../../config/runtime';
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
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
  purchaseCourse,
  redeemCourseCode as redeemCourseAccessCode,
} from '../../services/roknApi';
import type {LoginReturnTo} from '../../navigation/types';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';
import {
  CourseBody,
  CourseHero,
  CourseIntro,
  StickyCourseAction,
} from './details/sections';
import {CourseCodeRedemptionAction} from './details/CourseCodeRedemptionAction';
import {
  CourseCodeRedemptionDialog,
  CoursePurchaseDialog,
  CourseRetentionDialog,
} from './details/PurchaseDialogs';
import type {DialogStep} from './details/PurchaseDialogs';
import {
  selectCourseDetailsPresentation,
  selectCourseHeroHeight,
} from './details/selectors';
import {useCourseDetailsData} from './details/useCourseDetailsData';
import {useStickyCourseAction} from './details/useStickyCourseAction';
import styles from './details/styles';

const retentionShownCourses = new Set<string>();

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
  const [redemptionVisible, setRedemptionVisible] = useState(false);
  const [grantActivated, setGrantActivated] = useState(false);
  const [codeBusy, setCodeBusy] = useState(false);
  const autoPurchaseHandledRef = useRef(false);
  const autoRedemptionHandledRef = useRef(false);
  const commerceInFlightRef = useRef(false);

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
    remoteSpendableBalance,
    setExperience,
    setRemoteBalance,
    setRemoteOwned,
    setRemotePaidBalance,
    setRemotePackages,
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
    packages,
    planSpendableBalances,
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
    remoteSpendableBalance,
    routeParams: route.params,
    selectedPlanCode,
  });

  useEffect(() => {
    if (!accessPlans.length) return;
    if (!accessPlans.some(plan => plan.code === selectedPlanCode)) {
      setSelectedPlanCode(accessPlans[0].code);
    }
  }, [accessPlans, selectedPlanCode]);

  const heroHeight = selectCourseHeroHeight(layout);
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

  const startPreview = (reelId?: string) =>
    navigation.navigate('Reels', {
      courseId,
      ...(reelId ? {reelId} : {}),
      preview: true,
      previewCount: previewReelCount,
      coinPrice: coursePrice,
      title: courseTitle,
      description: courseDescription,
    });

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
    if (!CAN_START_EXTERNAL_CHECKOUT && (coursePrice ?? 0) > 0) {
      if (hasPreview) startPreview();
      else
        setNotice(
          'سجّل الدخول بالحساب الذي فُتح عليه الكورس لتجده جاهزًا هنا.',
        );
      return;
    }
    if (coursePrice === null) {
      setNotice('سعر هذا الكورس لم يُنشر بعد، لذلك لم نفتح أي عملية شراء.');
      return;
    }
    if (!isDemoCourse && coursePrice > 0 && remoteBalance === null) {
      setNotice('تعذّر التحقق من رصيدك الآن. حاول مرة أخرى بعد لحظات.');
      return;
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

  const openCodeRedemption = () => {
    if (!CAN_REDEEM_COURSE_ACCESS_CODE) return;
    setNotice('');
    if (!isDemoCourse && remoteSession === false) {
      openLoginForCodeRedemption();
      return;
    }
    setRedemptionVisible(true);
  };

  useEffect(() => {
    autoPurchaseHandledRef.current = false;
    autoRedemptionHandledRef.current = false;
    setCourseCode('');
    setRedemptionVisible(false);
    setRetentionQueued(false);
    setRetentionVisible(false);
  }, [courseId]);

  useEffect(() => {
    if (
      !route.params?.openPurchase ||
      !CAN_START_EXTERNAL_CHECKOUT ||
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
      setNotice('سعر هذا الكورس لم يُنشر بعد، لذلك لم نفتح أي عملية شراء.');
      return;
    }
    if (!isDemoCourse && coursePrice > 0 && remoteBalance === null) {
      setNotice('تعذّر التحقق من رصيدك الآن. حاول مرة أخرى بعد لحظات.');
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
    if (busy) return;
    const shouldOfferTasks =
      dialogStep !== null &&
      dialogStep !== 'success' &&
      !owned &&
      !retentionShownCourses.has(courseId);
    if (shouldOfferTasks) {
      retentionShownCourses.add(courseId);
      setRetentionQueued(true);
    }
    setDialogStep(null);
  };

  const buyCoins = async (coinPackage: DemoCoinPackage) => {
    if (!CAN_START_EXTERNAL_CHECKOUT || commerceInFlightRef.current) return;
    commerceInFlightRef.current = true;
    setBusy(true);
    setNotice('');
    try {
      const result = await openCoinCheckout(coinPackage);
      if (result.cancelled) {
        setNotice('أُغلقت صفحة الدفع ولم يتغير رصيدك.');
      } else if (result.pending) {
        setNotice(
          'الدفع قيد التأكيد. سنحدّث الرصيد تلقائيًا عند وصول النتيجة.',
        );
      } else if (result.success) {
        if (result.demo) {
          const state = await getDemoExperience();
          setExperience(state);
          const nextSpendable =
            state.paidBalance +
            Math.min(
              state.rewardBalance,
              ECONOMY_CONFIG.maxRewardContributionPerCourse,
            );
          setDialogStep(nextSpendable >= purchasePrice ? 'confirm' : 'topup');
          setNotice(
            formatArabicDisplayText(
              `تمت إضافة ${result.coinsAdded} إلى رصيدك.`,
            ),
          );
        } else {
          try {
            const wallet = await getWallet();
            setRemoteBalance(wallet.balance);
            setRemoteSpendableBalance(wallet.spendableBalance);
            setRemotePaidBalance(wallet.paidBalance);
            setRemoteRewardBalance(wallet.rewardBalance);
            setDialogStep(
              wallet.spendableBalance >= purchasePrice ? 'confirm' : 'topup',
            );
            setNotice(
              formatArabicDisplayText(
                `تم تأكيد الدفع وإضافة ${result.coinsAdded} إلى رصيدك.`,
              ),
            );
          } catch {
            setDialogStep(null);
            setNotice(
              'وصل تأكيد الدفع، لكن تحديث الرصيد لسه متأخر. مش محتاج تدفع تاني؛ افتح الصفحة بعد لحظات.',
            );
          }
        }
      }
    } catch {
      setNotice('تعذر فتح بوابة الدفع الآن. حاول مرة أخرى دون أن تفقد مكانك.');
    } finally {
      commerceInFlightRef.current = false;
      setBusy(false);
    }
  };

  const confirmPurchase = async () => {
    if (
      (!CAN_START_EXTERNAL_CHECKOUT && purchasePrice > 0) ||
      commerceInFlightRef.current
    ) {
      return;
    }
    commerceInFlightRef.current = true;
    setGrantActivated(false);
    setBusy(true);
    setNotice('');
    try {
      if (isDemoCourse) {
        const result = await purchaseDemoCourse(
          courseId,
          purchasePrice,
          (selectedPlan?.code as 'basic' | 'guided' | 'mentor') || 'basic',
        );
        setExperience(result.state);
        setDialogStep(result.purchased ? 'success' : 'topup');
      } else {
        const result = await purchaseCourse(courseId, selectedPlan?.code);
        if (result.kind === 'success') {
          setRemoteBalance(result.balance);
          setRemoteSpendableBalance(result.spendableBalance);
          setRemotePaidBalance(result.paidBalance);
          setRemoteRewardBalance(result.rewardBalance);
          setRemoteOwned(true);
          setDialogStep('success');
        } else {
          setRemoteBalance(result.balance);
          setRemoteSpendableBalance(result.spendableBalance);
          setRemotePaidBalance(result.paidBalance);
          setRemoteRewardBalance(result.rewardBalance);
          if (result.packages.length) setRemotePackages(result.packages);
          setNotice(
            formatArabicDisplayText(
              `تحتاج ${result.deficit} إضافية في رصيدك لفتح الكورس.`,
            ),
          );
          setDialogStep('topup');
        }
      }
    } catch {
      setNotice('تعذّر فتح الكورس الآن. لم يتغير رصيدك.');
    } finally {
      commerceInFlightRef.current = false;
      setBusy(false);
    }
  };

  const redeemCourseCode = async () => {
    if (!CAN_REDEEM_COURSE_ACCESS_CODE) return;
    setGrantActivated(false);
    const normalizedCode = courseCode.trim().toUpperCase();
    if (!normalizedCode) {
      setNotice('اكتب الكود أولًا.');
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
          setNotice('الكود غير صحيح أو لم يعد متاحًا.');
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
            ? `هذا الكود مخصص لكورس «${result.courseName}».`
            : 'هذا الكود مخصص لكورس آخر.',
        );
        return;
      }
      setRemoteOwned(true);
      setGrantActivated(
        result.accessType === 'scholarship' && !result.alreadyEnrolled,
      );
      setCourseCode('');
      setRedemptionVisible(false);
      setDialogStep('success');
    } catch (error: unknown) {
      const payload = errorPayload(error);
      setNotice(
        String(payload.message || '') ||
          'تعذّر تفعيل الكود الآن. لم يتغير رصيدك.',
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
          projectCount={projectCount}
          reelCount={reelCount}
          remoteCourse={remoteCourse}
          remoteLoading={remoteLoading}
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
            previewReelCount={previewReelCount}
            primaryActionLabel={primaryActionLabel}
            ratingAverage={ratingAverage}
            ratingsCount={ratingsCount}
            remoteError={remoteError}
            studentsCount={studentsCount}
          />
          <CourseCodeRedemptionAction
            onPress={openCodeRedemption}
            visible={
              CAN_REDEEM_COURSE_ACCESS_CODE &&
              !owned &&
              pageReady &&
              !remoteError
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
        courseTitle={courseTitle}
        dialogStep={dialogStep}
        grantActivated={grantActivated}
        isTablet={layout.isTablet}
        notice={notice}
        onBuyCoins={buyCoins}
        onClose={closePurchaseDialog}
        onConfirmPurchase={confirmPurchase}
        onSelectPlan={plan => {
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
        packages={packages}
        purchasePrice={purchasePrice}
        selectedPlan={selectedPlan}
        shortfall={shortfall}
        sufficientPackage={sufficientPackage}
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
