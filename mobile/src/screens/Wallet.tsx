import React, {useCallback, useEffect, useRef, useState} from 'react';
import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import {errorPayload} from '../utils/errorPayload';
import {
  ActivityIndicator,
  AppState,
  Alert,
  Linking,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import TabBar from '../components/TabBar';
import {Container, Content} from '../components/containers/Containers';
import {
  PremiumCard,
  ResponsiveFrame,
  SectionHeading,
  StatusView,
} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import Package from '../components/view/Package';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../constants/designSystem';
import {
  beginDemoTask,
  claimDemoTask,
  DEMO_COIN_PACKAGES,
  DemoCoinPackage,
  DemoCoinTask,
  DemoExperienceState,
  subscribeDemoExperience,
} from '../services/demoExperience';
import {openCoinCheckout} from '../services/coinCheckout';
import {
  getCoinPackages,
  getCoinTasks,
  getWallet,
  hasSession,
  claimCoinTask,
  CoinTask,
  startCoinTask,
  WalletSnapshot,
} from '../services/roknApi';
import RoknCoin, {CoinAmount, RoknCoinStack} from '../components/ui/RoknCoin';
import {CAN_START_EXTERNAL_CHECKOUT} from '../constants/distribution';
import {ECONOMY_CONFIG, ECONOMY_RULES} from '../config/economy';
import TaskBrandIcon from '../components/ui/TaskBrandIcon';
import {
  formatArabicDisplayText,
  formatArabicNumber,
  toArabicDigits,
} from '../constants/arabicFormatting';
import {LOCAL_DEMO_ENABLED} from '../config/runtime';
import {trustedExternalTaskUrl} from '../services/externalTaskUrlPolicy';

export default function Wallet() {
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const {contentWidth, fontScale, gutter, width} = useResponsiveLayout();
  const packageColumns =
    fontScale > 1.18
      ? 1
      : contentWidth >= 820
      ? 3
      : contentWidth >= 560
      ? 2
      : 1;
  const packageCardWidth = Math.max(
    0,
    Math.floor(
      (contentWidth - gutter * 2 - Spacing.sm * (packageColumns - 1)) /
        packageColumns,
    ),
  );
  const stackTaskActions = width < 420 || fontScale > 1.18;
  const balanceArtworkSize =
    fontScale > 1.18 ? 82 : Math.min(104, Math.max(84, width * 0.26));
  const [experience, setExperience] = useState<DemoExperienceState | null>(
    null,
  );
  const [remoteWallet, setRemoteWallet] = useState<WalletSnapshot | null>(null);
  const [remotePackages, setRemotePackages] = useState<DemoCoinPackage[]>([]);
  const [remoteTasks, setRemoteTasks] = useState<CoinTask[]>([]);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [remoteLoading, setRemoteLoading] = useState(false);
  const [remoteError, setRemoteError] = useState('');
  const [checkoutLoading, setCheckoutLoading] = useState<string | null>(null);
  const [taskLoadingIds, setTaskLoadingIds] = useState<string[]>([]);
  const [walletModal, setWalletModal] = useState<
    'breakdown' | 'rules' | null
  >(null);
  const checkoutFlightRef = useRef(false);
  const taskFlightsRef = useRef(new Set<string>());
  const walletRefreshRequestRef = useRef(0);

  const refreshWallet = useCallback(async () => {
    const requestId = ++walletRefreshRequestRef.current;
    let sessionAvailable = false;
    try {
      sessionAvailable = await hasSession();
    } catch {
      if (requestId === walletRefreshRequestRef.current) {
        setRemoteLoading(false);
        setRemoteError('تعذّر قراءة جلسة المحفظة بأمان. أعد فتح الشاشة.');
      }
      return;
    }
    if (requestId !== walletRefreshRequestRef.current) return;
    setServerSession(sessionAvailable);
    if (!sessionAvailable) {
      setRemoteLoading(false);
      return;
    }
    setRemoteLoading(true);
    const [walletResult, packagesResult, tasksResult] =
      await Promise.allSettled([
        getWallet(),
        getCoinPackages(),
        getCoinTasks(),
      ]);
    if (requestId !== walletRefreshRequestRef.current) return;
    if (walletResult.status === 'fulfilled')
      setRemoteWallet(walletResult.value);
    if (packagesResult.status === 'fulfilled')
      setRemotePackages(packagesResult.value);
    if (tasksResult.status === 'fulfilled') setRemoteTasks(tasksResult.value);
    const failed = [walletResult, packagesResult, tasksResult].some(
      result => result.status === 'rejected',
    );
    setRemoteError(
      failed
        ? 'تعذّر تحديث بعض بيانات المحفظة الآن. لم نخصم أو نضف أي عملات.'
        : '',
    );
    setRemoteLoading(false);
  }, []);

  useEffect(() => {
    const unsubscribe = LOCAL_DEMO_ENABLED
      ? subscribeDemoExperience(setExperience)
      : () => undefined;
    void refreshWallet();
    return () => {
      walletRefreshRequestRef.current += 1;
      unsubscribe();
    };
  }, [refreshWallet]);

  useEffect(() => {
    const subscription = AppState.addEventListener('change', state => {
      if (state === 'active') void refreshWallet();
    });

    return () => subscription.remove();
  }, [refreshWallet]);

  const usingRemoteWallet = serverSession === true;
  const displayedBalance = usingRemoteWallet
    ? remoteWallet?.balance ?? null
    : serverSession === false && LOCAL_DEMO_ENABLED
    ? experience?.balance ?? 0
    : null;
  const walletWithBuckets = remoteWallet as
    | (WalletSnapshot & {
        paidBalance?: number;
        rewardBalance?: number;
        paid_balance?: number;
        purchased_balance?: number;
        reward_balance?: number;
      })
    | null;
  const displayedPaidBalance = usingRemoteWallet
    ? Number(
        walletWithBuckets?.paidBalance ??
          walletWithBuckets?.paid_balance ??
          walletWithBuckets?.purchased_balance ??
          0,
      )
    : serverSession === false && LOCAL_DEMO_ENABLED
    ? experience?.paidBalance ?? 0
    : 0;
  const displayedRewardBalance = usingRemoteWallet
    ? Number(
        walletWithBuckets?.rewardBalance ??
          walletWithBuckets?.reward_balance ??
          Math.max(0, (displayedBalance ?? 0) - displayedPaidBalance),
      )
    : serverSession === false && LOCAL_DEMO_ENABLED
    ? experience?.rewardBalance ?? displayedBalance ?? 0
    : 0;
  const displayedRewardContributionCap = usingRemoteWallet
    ? remoteWallet?.rewardContributionCap ?? 0
    : ECONOMY_CONFIG.maxRewardContributionPerCourse;
  const displayedSpendableBalance = usingRemoteWallet
    ? remoteWallet?.spendableBalance ?? displayedBalance ?? 0
    : displayedPaidBalance +
      Math.min(displayedRewardBalance, displayedRewardContributionCap);
  const displayedCoinRules =
    usingRemoteWallet && remoteWallet?.coinRules?.length
      ? remoteWallet.coinRules
      : ECONOMY_RULES;
  const displayedPackages = (
    usingRemoteWallet
      ? remotePackages
      : serverSession === false && LOCAL_DEMO_ENABLED
      ? DEMO_COIN_PACKAGES
      : []
  )
    .slice()
    .sort((left, right) => left.coins - right.coins);
  const displayedTasks = usingRemoteWallet
    ? remoteTasks
    : serverSession === false && LOCAL_DEMO_ENABLED
    ? experience?.tasks ?? []
    : [];
  const displayedTransactions = usingRemoteWallet
    ? (remoteWallet?.transactions ?? []).map(item => ({
        id: item.id,
        title: item.category || 'عملية محفظة',
        amount: item.amount,
        createdAt: item.occurred_at
          ? new Date(item.occurred_at).getTime()
          : Date.now(),
      }))
    : serverSession === false && LOCAL_DEMO_ENABLED
    ? experience?.transactions ?? []
    : [];

  const isRemoteTask = (task: DemoCoinTask | CoinTask): task is CoinTask =>
    'serverId' in task;

  const isCoinGuideTask = (task: DemoCoinTask | CoinTask) =>
    task.id === 'coin-guide' ||
    (isRemoteTask(task) && task.actionKey.toLowerCase().includes('coin_guide'));

  const isWhatsAppTask = (
    task: DemoCoinTask | CoinTask,
  ): task is CoinTask =>
    isRemoteTask(task) && task.actionKey === 'link_whatsapp';

  const runTaskAction = async (task: DemoCoinTask | CoinTask) => {
    if (isWhatsAppTask(task)) {
      try {
        const started = await startCoinTask(task);
        setRemoteTasks(current =>
          current.map(item =>
            item.id === task.id
              ? {
                  ...item,
                  status:
                    started.status === 'claimed' ? 'claimed' : 'started',
                }
              : item,
          ),
        );
        if (started.status === 'claimed') {
          await refreshWallet();
          return;
        }
        const safeActionUrl = trustedExternalTaskUrl(started.url);
        if (!safeActionUrl) {
          Alert.alert(
            'ربط واتساب غير متاح',
            'تعذّر تجهيز رسالة الربط الآن. جرّب بعد قليل.',
          );
          return;
        }
        await Linking.openURL(safeActionUrl);
      } catch (error: unknown) {
        const details = errorPayload(error);
        Alert.alert(
          'تعذّر فتح واتساب',
          String(details.message || 'حاول مرة أخرى بعد التأكد من الاتصال.'),
        );
      }
      return;
    }
    if (task.status === 'available') {
      try {
        let actionUrl = task.url;
        if (isRemoteTask(task)) {
          const started = await startCoinTask(task);
          actionUrl = started.url;
          setRemoteTasks(current =>
            current.map(item =>
              item.id === task.id
                ? {
                    ...item,
                    status:
                      started.status === 'claimed' ? 'claimed' : 'started',
                  }
                : item,
            ),
          );
          if (started.status === 'claimed') {
            await refreshWallet();
            return;
          }
        } else {
          // Persist the immutable attempt before opening either the in-app guide
          // or an external task destination.
          await beginDemoTask(task.id);
        }
        if (isCoinGuideTask(task)) {
          setWalletModal('rules');
        } else if (actionUrl) {
          const safeActionUrl = trustedExternalTaskUrl(actionUrl);
          if (!safeActionUrl) {
            Alert.alert(
              'تعذّر فتح المهمة',
              'رابط المهمة غير صالح. لم نفتح أي صفحة، وهنراجع الرابط.',
            );
            return;
          }
          await Linking.openURL(safeActionUrl);
        }
      } catch (error: unknown) {
        const payload = errorPayload(error);
        Alert.alert(
          'تعذّر بدء المهمة',
          String(payload.message || 'حاول مرة أخرى بعد التأكد من الاتصال.'),
        );
      }
      return;
    }
    try {
      if (isRemoteTask(task)) {
        await claimCoinTask(task);
        setRemoteTasks(current =>
          current.map(item =>
            item.id === task.id ? {...item, status: 'claimed'} : item,
          ),
        );
        await refreshWallet();
      } else {
        await claimDemoTask(task.id);
      }
    } catch (error: unknown) {
      const payload = errorPayload(error);
      Alert.alert(
        'المكافأة ليست جاهزة بعد',
        String(payload.message || 'أكمل المهمة ثم عد للمطالبة بالعملات.'),
      );
    }
  };

  const handleTask = async (task: DemoCoinTask | CoinTask) => {
    if (task.status === 'claimed' || taskFlightsRef.current.has(task.id))
      return;
    taskFlightsRef.current.add(task.id);
    setTaskLoadingIds(current => [...current, task.id]);
    try {
      await runTaskAction(task);
    } finally {
      taskFlightsRef.current.delete(task.id);
      setTaskLoadingIds(current => current.filter(id => id !== task.id));
    }
  };

  const startCheckout = async (item: DemoCoinPackage) => {
    if (checkoutLoading || checkoutFlightRef.current) return;
    checkoutFlightRef.current = true;
    setCheckoutLoading(item.id);
    try {
      const result = await openCoinCheckout(item);
      if (result.success) {
        if (!result.demo) await refreshWallet();
        Alert.alert(
          'تم شحن الرصيد',
          `أضفنا ${formatArabicNumber(result.coinsAdded)} إلى رصيدك.`,
        );
      } else if (result.pending) {
        Alert.alert(
          'العملية قيد التأكيد',
          'سنحدّث رصيدك تلقائيًا فور وصول تأكيد الدفع.',
        );
      }
    } catch {
      Alert.alert('تعذّر فتح الدفع', 'رصيدك لم يتغير. حاول مرة أخرى بعد قليل.');
    } finally {
      checkoutFlightRef.current = false;
      setCheckoutLoading(null);
    }
  };

  if (serverSession === false && !LOCAL_DEMO_ENABLED) {
    return (
      <Container noPadding>
        <Content noPadding>
          <ResponsiveFrame>
            <HeaderWithBack hasArrow={false} title="المحفظة" />
            <StatusView
              actionLabel="تسجيل الدخول"
              description="سجّل دخولك علشان تشوف رصيدك ومكافآتك وتكمل من أي جهاز."
              onAction={() =>
                navigation.navigate('Login', {
                  returnTo: {name: 'Wallet'},
                })
              }
              state="empty"
              title="رصيدك مرتبط بحسابك"
            />
          </ResponsiveFrame>
        </Content>
        <TabBar />
      </Container>
    );
  }

  return (
    <Container noPadding>
      <Content
        noPadding
        paddingBottom={Math.max(Spacing.xl, insets.bottom + Spacing.md)}>
        <ResponsiveFrame>
          <HeaderWithBack hasArrow={false} title="المحفظة" />
          <PremiumCard style={styles.balanceCard}>
            <View style={styles.balanceHeroTop}>
              <View style={styles.balanceHeroCopy}>
                <Text style={styles.balanceCaption}>إجمالي رصيدك</Text>
                <Text style={styles.balanceHeroHint}>
                  استخدمه لفتح الكورسات
                </Text>
              </View>
              <RoknCoinStack
                size={balanceArtworkSize}
                style={styles.coinStack}
              />
            </View>
            <Pressable
              accessibilityHint="يعرض العملات المدفوعة وعملات المكافآت"
              accessibilityLabel="تفاصيل رصيد العملات"
              accessibilityRole="button"
              disabled={displayedBalance === null}
              onPress={() => setWalletModal('breakdown')}
              style={({pressed}) => [
                styles.balanceButton,
                pressed && styles.pressed,
              ]}>
              <View style={styles.balanceRow}>
                <RoknCoin size={34} style={styles.coinSpacing} />
                <Text
                  adjustsFontSizeToFit
                  numberOfLines={1}
                  style={styles.balance}>
                  {displayedBalance === null
                    ? '—'
                    : formatArabicNumber(displayedBalance)}
                </Text>
              </View>
              <Text style={styles.balanceDetails}>عرض التفاصيل</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={() => setWalletModal('rules')}
              style={({pressed}) => [
                styles.rulesLink,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.rulesLinkLabel}>كيف يعمل الرصيد؟</Text>
              <Text style={styles.rulesLinkArrow}>‹</Text>
            </Pressable>
          </PremiumCard>

          {CAN_START_EXTERNAL_CHECKOUT && (
            <SectionHeading style={styles.sectionHeading} title="شحن الرصيد" />
          )}
        </ResponsiveFrame>
        {CAN_START_EXTERNAL_CHECKOUT && displayedPackages.length ? (
          <ScrollView
            contentContainerStyle={[
              styles.packages,
              {gap: Spacing.sm, paddingHorizontal: gutter},
            ]}
            horizontal
            nestedScrollEnabled
            showsHorizontalScrollIndicator={false}>
            {displayedPackages.map(item => (
              <Package
                buttonTitle={
                  checkoutLoading === item.id
                    ? 'جاري الفتح…'
                    : checkoutLoading
                    ? 'انتظر لحظة'
                    : 'اختيار الباقة'
                }
                disabled={Boolean(checkoutLoading)}
                key={item.id}
                onPress={() => startCheckout(item)}
                price={String(item.price)}
                rPrice={String(item.coins)}
                width={packageCardWidth}
              />
            ))}
          </ScrollView>
        ) : CAN_START_EXTERNAL_CHECKOUT && usingRemoteWallet ? (
          <ResponsiveFrame>
            <PremiumCard style={styles.unavailableCard}>
              <Text style={styles.remoteNote}>
                {remoteLoading
                  ? 'نحدّث باقات الرصيد…'
                  : 'تعذّر تحميل الباقات الآن.'}
              </Text>
              {!remoteLoading && (
                <Pressable
                  accessibilityRole="button"
                  onPress={refreshWallet}
                  style={styles.retryButton}>
                  <Text style={styles.retryLabel}>إعادة المحاولة</Text>
                </Pressable>
              )}
            </PremiumCard>
          </ResponsiveFrame>
        ) : null}

        <ResponsiveFrame>
          {!!remoteError && <Text style={styles.apiError}>{remoteError}</Text>}
          <SectionHeading
            style={styles.sectionHeading}
            title="اكسب رصيدًا"
            eyebrow="المكافآت المتاحة لك الآن"
          />
          <PremiumCard style={styles.tasksCard}>
            {displayedTasks.length ? (
              displayedTasks.map((task, index, allTasks) => {
                const taskLoading = taskLoadingIds.includes(task.id);
                return (
                <View key={task.id}>
                  <View
                    style={[
                      styles.taskRow,
                      stackTaskActions && styles.taskRowStacked,
                    ]}>
                    <View style={styles.taskMain}>
                      <View style={styles.taskIcon}>
                        <TaskBrandIcon
                          value={`${
                            isRemoteTask(task) ? task.actionKey : task.id
                          } ${task.title} ${task.url || ''}`}
                        />
                      </View>
                      <View style={styles.taskCopy}>
                        <Text style={styles.taskTitle}>
                          {formatArabicDisplayText(task.title)}
                        </Text>
                        <Text style={styles.taskDescription}>
                          {formatArabicDisplayText(task.description)}
                        </Text>
                        <View style={styles.taskReward}>
                          <Text style={styles.rewardPlus}>+</Text>
                          <CoinAmount size={15} value={task.reward} />
                        </View>
                      </View>
                    </View>
                    <Pressable
                      accessibilityRole="button"
                      accessibilityState={{
                        busy: taskLoading,
                        disabled: task.status === 'claimed' || taskLoading,
                      }}
                      disabled={task.status === 'claimed' || taskLoading}
                      onPress={() => handleTask(task)}
                      style={({pressed}) => [
                        styles.taskAction,
                        stackTaskActions && styles.taskActionStacked,
                        task.status === 'claimed' && styles.taskActionDone,
                        pressed && styles.pressed,
                      ]}>
                      {taskLoading ? (
                        <ActivityIndicator color={Palette.text} size="small" />
                      ) : (
                        <Text
                          style={[
                            styles.taskActionLabel,
                            task.status === 'claimed' &&
                              styles.taskActionLabelDone,
                          ]}>
                          {task.status === 'available'
                            ? isCoinGuideTask(task)
                              ? 'اعرف أكثر'
                              : 'اذهب'
                            : task.status === 'started'
                            ? 'استلام'
                            : 'تم الاستلام'}
                        </Text>
                      )}
                    </Pressable>
                  </View>
                  {index < allTasks.length - 1 && (
                    <View style={styles.divider} />
                  )}
                </View>
                );
              })
            ) : (
              <Text style={styles.remoteNote}>
                {usingRemoteWallet && remoteLoading
                  ? 'نحدّث المهام المتاحة…'
                  : usingRemoteWallet && remoteError
                  ? 'تعذّر تحميل المهام الآن. جرّب التحديث بعد قليل.'
                  : 'أنهيت كل المهام المتاحة حاليًا.'}
              </Text>
            )}
          </PremiumCard>

          {!!displayedTransactions.length && (
            <>
              <SectionHeading
                style={styles.sectionHeading}
                title="آخر العمليات"
              />
              <PremiumCard style={styles.transactionsCard}>
                {displayedTransactions
                  .slice(0, 5)
                  .map((item, index, allTransactions) => (
                    <View key={item.id}>
                      <View style={styles.transactionRow}>
                        <View style={styles.transactionCopy}>
                          <Text style={styles.transactionTitle}>
                            {formatArabicDisplayText(item.title)}
                          </Text>
                          <Text style={styles.transactionDate}>
                            {toArabicDigits(
                              new Date(item.createdAt).toLocaleDateString(
                                'ar-EG',
                                {day: 'numeric', month: 'short'},
                              ),
                            )}
                          </Text>
                        </View>
                        <Text
                          style={[
                            styles.transactionValue,
                            item.amount > 0 && styles.positive,
                          ]}>
                          {item.amount > 0 ? '+' : '−'}
                          {formatArabicNumber(Math.abs(item.amount))}
                        </Text>
                      </View>
                      {index < allTransactions.length - 1 && (
                        <View style={styles.divider} />
                      )}
                    </View>
                  ))}
              </PremiumCard>
            </>
          )}
        </ResponsiveFrame>
      </Content>
      <TabBar />
      <Modal
        animationType="fade"
        onRequestClose={() => setWalletModal(null)}
        transparent
        visible={walletModal !== null}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="إغلاق تفاصيل الرصيد"
          onPress={() => setWalletModal(null)}
          style={styles.breakdownOverlay}>
          <Pressable
            accessible={false}
            accessibilityViewIsModal
            onPress={event => event.stopPropagation()}
            style={[
              styles.breakdownSheet,
              {paddingBottom: Math.max(Spacing.xl, insets.bottom + Spacing.md)},
            ]}>
            <View style={styles.breakdownHandle} />
            <ScrollView
              bounces={false}
              contentContainerStyle={styles.breakdownContent}
              showsVerticalScrollIndicator={false}
              style={styles.breakdownScroll}>
              {walletModal === 'rules' ? (
                <>
                  <Text style={styles.rulesTitle}>كيف يعمل الرصيد؟</Text>
                  <Text style={styles.rulesIntro}>
                    اشحنه أو اكسبه من المهام، ثم استخدمه لفتح الكورسات.
                  </Text>
                  <View style={styles.rulesList}>
                    {displayedCoinRules.map((rule, index) => (
                      <View key={rule} style={styles.ruleRow}>
                        <View style={styles.ruleNumber}>
                          <Text style={styles.ruleNumberLabel}>
                            {formatArabicNumber(index + 1)}
                          </Text>
                        </View>
                        <Text style={styles.ruleText}>
                          {formatArabicDisplayText(rule)}
                        </Text>
                      </View>
                    ))}
                  </View>
                </>
              ) : (
                <>
                  <View style={styles.breakdownHero}>
                    <RoknCoin size={58} style={styles.coinSpacing} />
                    <View style={styles.breakdownHeroCopy}>
                      <Text style={styles.breakdownCaption}>إجمالي الرصيد</Text>
                      <CoinAmount
                        size={24}
                        textStyle={styles.breakdownTotal}
                        value={displayedBalance ?? 0}
                      />
                    </View>
                  </View>

                  <View style={styles.bucketRow}>
                    <View style={[styles.bucketDot, styles.paidDot]} />
                    <View style={styles.bucketCopy}>
                      <Text style={styles.bucketTitle}>رصيد مدفوع</Text>
                      <Text style={styles.bucketHint}>من عمليات الشحن</Text>
                    </View>
                    <CoinAmount size={16} value={displayedPaidBalance} />
                  </View>
                  <View style={styles.bucketDivider} />
                  <View style={styles.bucketRow}>
                    <View style={[styles.bucketDot, styles.rewardDot]} />
                    <View style={styles.bucketCopy}>
                      <Text style={styles.bucketTitle}>رصيد مكافآت</Text>
                      <Text style={styles.bucketHint}>ترحيب ومهام</Text>
                    </View>
                    <CoinAmount size={16} value={displayedRewardBalance} />
                  </View>
                  <View style={styles.bucketDivider} />
                  <View style={styles.bucketRow}>
                    <View style={[styles.bucketDot, styles.spendableDot]} />
                    <View style={styles.bucketCopy}>
                      <Text style={styles.bucketTitle}>المتاح لكورس واحد</Text>
                      <Text style={styles.bucketHint}>
                        بعد تطبيق حد المكافآت
                      </Text>
                    </View>
                    <CoinAmount size={16} value={displayedSpendableBalance} />
                  </View>
                  <Text style={styles.bucketPolicy}>
                    عند فتح كورس نستخدم المكافآت أولًا بحد أقصى{' '}
                    {formatArabicNumber(displayedRewardContributionCap)} ثم
                    الرصيد المدفوع.
                  </Text>
                </>
              )}
              <Pressable
                accessibilityRole="button"
                onPress={() => setWalletModal(null)}
                style={({pressed}) => [
                  styles.breakdownClose,
                  pressed && styles.pressed,
                ]}>
                <Text style={styles.breakdownCloseLabel}>تم</Text>
              </Pressable>
            </ScrollView>
          </Pressable>
        </Pressable>
      </Modal>
    </Container>
  );
}

const styles = StyleSheet.create({
  balanceCard: {
    padding: Spacing.xl,
    marginBottom: Spacing.xl,
    backgroundColor: '#111721',
  },
  balanceHeroTop: {minHeight: 78, ...rtlRowStyle, alignItems: 'center'},
  balanceHeroCopy: {flex: 1, minWidth: 0},
  balanceCaption: {...Type.caption, ...textDirection, color: Palette.textMuted},
  balanceHeroHint: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    marginTop: 2,
  },
  coinStack: {marginStart: Spacing.sm},
  balanceRow: {...rtlRowStyle, alignItems: 'center', marginTop: Spacing.xs},
  balanceButton: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    alignItems: 'center',
    justifyContent: 'space-between',
    minHeight: Accessibility.minTouchTarget,
    gap: Spacing.xs,
  },
  balance: {...Type.display, color: Palette.text, fontSize: 40, flexShrink: 1},
  balanceDetails: {
    ...Type.caption,
    ...textDirection,
    color: '#DDB95D',
    flexShrink: 1,
  },
  balanceHint: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    marginTop: Spacing.xs,
  },
  rulesLink: {
    minHeight: Accessibility.minTouchTarget,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    marginTop: Spacing.sm,
    paddingTop: Spacing.sm,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.lineSoft,
  },
  rulesLinkLabel: {...Type.caption, ...textDirection, color: '#DDB95D'},
  rulesLinkArrow: {fontSize: 24, color: '#DDB95D'},
  coinSpacing: {marginEnd: Spacing.sm},
  sectionHeading: {marginTop: Spacing.md, marginBottom: Spacing.sm},
  packages: {...rtlRowStyle, paddingBottom: Spacing.md},
  tasksCard: {paddingHorizontal: Spacing.md},
  taskRow: {...rtlRowStyle, alignItems: 'center', paddingVertical: Spacing.md},
  taskRowStacked: {flexDirection: 'column', alignItems: 'stretch'},
  taskMain: {
    flexGrow: 1,
    flexShrink: 1,
    minWidth: 0,
    ...rtlRowStyle,
    alignItems: 'flex-start',
  },
  taskIcon: {
    width: 40,
    minWidth: 40,
    minHeight: 40,
    flexShrink: 0,
    alignItems: 'center',
    justifyContent: 'center',
    marginEnd: Spacing.md,
  },
  taskCopy: {flex: 1, minWidth: 0},
  taskTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    flexShrink: 1,
  },
  taskDescription: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 2,
    flexShrink: 1,
  },
  taskCoins: {
    ...Type.caption,
    ...textDirection,
    color: '#E9C66F',
    marginTop: 2,
  },
  taskReward: {
    width: '100%',
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 2,
    marginTop: 2,
  },
  rewardPlus: {...Type.caption, color: '#E9C66F'},
  taskAction: {
    minWidth: 78,
    maxWidth: '36%',
    minHeight: Accessibility.minTouchTarget,
    paddingHorizontal: Spacing.sm,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.md,
    backgroundColor: Palette.primarySoft,
    marginStart: Spacing.sm,
    flexShrink: 0,
  },
  taskActionStacked: {
    width: '100%',
    maxWidth: '100%',
    marginStart: 0,
    marginTop: Spacing.sm,
  },
  taskActionDone: {backgroundColor: 'rgba(255,255,255,0.05)'},
  taskActionLabel: {
    ...Type.caption,
    ...textDirection,
    textAlign: 'center',
    color: '#8BB5FF',
    flexShrink: 1,
  },
  taskActionLabelDone: {color: Palette.textFaint},
  divider: {
    height: StyleSheet.hairlineWidth,
    backgroundColor: Palette.lineSoft,
  },
  transactionsCard: {paddingHorizontal: Spacing.md, marginBottom: Spacing.xl},
  transactionRow: {minHeight: 68, ...rtlRowStyle, alignItems: 'center'},
  transactionCopy: {flex: 1, minWidth: 0},
  transactionTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  transactionDate: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
  },
  transactionValue: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.textMuted,
    marginStart: Spacing.sm,
    flexShrink: 1,
  },
  positive: {color: Palette.success},
  pressed: {opacity: 0.75},
  apiError: {
    ...Type.caption,
    ...textDirection,
    color: Palette.danger,
    marginBottom: Spacing.sm,
  },
  remoteNote: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    paddingVertical: Spacing.lg,
  },
  unavailableCard: {alignItems: 'center', marginBottom: Spacing.md},
  retryButton: {
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    paddingHorizontal: Spacing.lg,
    borderRadius: Radius.md,
    backgroundColor: Palette.primarySoft,
  },
  retryLabel: {...Type.caption, color: '#8BB5FF'},
  breakdownOverlay: {
    flex: 1,
    justifyContent: 'flex-end',
    alignItems: 'center',
    backgroundColor: Palette.overlay,
  },
  breakdownSheet: {
    width: '100%',
    maxWidth: 620,
    maxHeight: '90%',
    paddingHorizontal: Spacing.xl,
    paddingTop: Spacing.md,
    borderTopLeftRadius: Radius.xl,
    borderTopRightRadius: Radius.xl,
    backgroundColor: Palette.canvasSoft,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    overflow: 'hidden',
  },
  breakdownHandle: {
    alignSelf: 'center',
    width: 42,
    height: 4,
    borderRadius: 2,
    backgroundColor: Palette.line,
    marginBottom: Spacing.lg,
  },
  breakdownScroll: {width: '100%'},
  breakdownContent: {paddingBottom: Spacing.xs},
  breakdownHero: {
    ...rtlRowStyle,
    alignItems: 'center',
    marginBottom: Spacing.lg,
  },
  breakdownHeroCopy: {flex: 1, minWidth: 0, marginHorizontal: Spacing.sm},
  breakdownCaption: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
  },
  breakdownTotal: {
    ...Type.title,
    ...textDirection,
    color: Palette.text,
    marginTop: 2,
  },
  bucketRow: {...rtlRowStyle, alignItems: 'center', minHeight: 70},
  bucketDot: {width: 10, height: 10, borderRadius: 5, marginEnd: Spacing.sm},
  paidDot: {backgroundColor: Palette.coin},
  rewardDot: {backgroundColor: Palette.primary},
  spendableDot: {backgroundColor: Palette.success},
  bucketCopy: {flex: 1, minWidth: 0},
  bucketTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  bucketHint: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 2,
  },
  bucketValue: {...Type.title, color: Palette.text, marginStart: Spacing.sm},
  bucketDivider: {
    height: StyleSheet.hairlineWidth,
    backgroundColor: Palette.lineSoft,
  },
  bucketPolicy: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    backgroundColor: Palette.surface,
    borderRadius: Radius.md,
    padding: Spacing.md,
    marginTop: Spacing.md,
  },
  breakdownClose: {
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
    marginTop: Spacing.lg,
  },
  breakdownCloseLabel: {...Type.bodyStrong, color: '#FFFFFF'},
  rulesTitle: {...Type.title, ...textDirection, color: Palette.text},
  rulesIntro: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
    marginBottom: Spacing.md,
  },
  rulesList: {gap: Spacing.sm},
  ruleRow: {...rtlRowStyle, alignItems: 'flex-start'},
  ruleNumber: {
    width: 26,
    height: 26,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.coinSoft,
    marginEnd: Spacing.sm,
    marginTop: 1,
  },
  ruleNumberLabel: {...Type.caption, color: '#E8C66F'},
  ruleText: {
    ...Type.body,
    ...textDirection,
    color: Palette.text,
    flex: 1,
    minWidth: 0,
  },
});
