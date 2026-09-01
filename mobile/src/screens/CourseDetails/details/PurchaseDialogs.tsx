import React from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  Text,
  TextInput,
  View,
} from 'react-native';
import {CoinAmount} from '../../../components/ui/RoknCoin';
import {Palette} from '../../../constants/designSystem';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../../../constants/arabicFormatting';
import type {DemoCoinPackage} from '../../../services/demoExperience';
import type {CourseAccessPlan} from '../../../services/roknApi';
import {planBenefits} from './selectors';
import styles from './styles';
import {useReducedMotion} from '../../../hooks/useReducedMotion';
import {useSafeAreaInsets} from 'react-native-safe-area-context';

export type DialogStep = 'plans' | 'topup' | 'confirm' | 'success' | null;

type CourseCodeEntryProps = {
  codeBusy: boolean;
  courseCode: string;
  onCourseCodeChange: (value: string) => void;
  onRedeemCourseCode: () => void | Promise<void>;
};

const CourseCodeEntry = ({
  codeBusy,
  courseCode,
  onCourseCodeChange,
  onRedeemCourseCode,
}: CourseCodeEntryProps) => (
  <View style={styles.codeBox}>
    <Text style={styles.codeTitle}>كود جهة تعليمية</Text>
    <View style={styles.codeRow}>
      <TextInput
        accessibilityHint="أدخل الكود الذي استلمته من الجهة التعليمية"
        accessibilityLabel="كود الوصول إلى الكورس"
        autoCapitalize="characters"
        autoCorrect={false}
        editable={!codeBusy}
        maxLength={50}
        onChangeText={onCourseCodeChange}
        onSubmitEditing={() => void onRedeemCourseCode()}
        placeholder="اكتب الكود"
        placeholderTextColor={Palette.textFaint}
        returnKeyType="done"
        style={styles.codeInput}
        value={courseCode}
      />
      <Pressable
        accessibilityLabel="تفعيل كود الوصول"
        accessibilityRole="button"
        accessibilityState={{busy: codeBusy, disabled: codeBusy}}
        disabled={codeBusy}
        onPress={() => void onRedeemCourseCode()}
        style={({pressed}) => [
          styles.codeButton,
          pressed && styles.pressed,
          codeBusy && styles.disabled,
        ]}>
        {codeBusy ? (
          <ActivityIndicator color={Palette.text} size="small" />
        ) : (
          <Text style={styles.codeButtonText}>تفعيل</Text>
        )}
      </Pressable>
    </View>
  </View>
);

type CourseCodeRedemptionDialogProps = {
  bottomInset: number;
  codeBusy: boolean;
  courseCode: string;
  isTablet: boolean;
  notice: string;
  onClose: () => void;
  onCourseCodeChange: (value: string) => void;
  onRedeemCourseCode: () => void | Promise<void>;
  visible: boolean;
};

export const CourseCodeRedemptionDialog = ({
  bottomInset,
  codeBusy,
  courseCode,
  isTablet,
  notice,
  onClose,
  onCourseCodeChange,
  onRedeemCourseCode,
  visible,
}: CourseCodeRedemptionDialogProps) => {
  const reducedMotion = useReducedMotion();
  const insets = useSafeAreaInsets();
  const horizontalPadding = isTablet ? 28 : 18;
  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'slide'}
      onRequestClose={() => {
        if (!codeBusy) onClose();
      }}
      statusBarTranslucent
      transparent
      visible={visible}>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        style={styles.modalRoot}>
        <Pressable
          accessibilityLabel="إغلاق نافذة تفعيل كود الكورس"
          accessibilityRole="button"
          accessibilityState={{disabled: codeBusy}}
          disabled={codeBusy}
          onPress={onClose}
          style={styles.modalBackdrop}
        />
        <View
          accessibilityLabel="تفعيل كود جهة تعليمية"
          accessibilityViewIsModal
          style={[
            styles.sheet,
            styles.codeDialogSheet,
            {
              paddingBottom: Math.max(bottomInset, 16) + 10,
              paddingLeft: Math.max(horizontalPadding, insets.left + 12),
              paddingRight: Math.max(horizontalPadding, insets.right + 12),
            },
          ]}>
          <View style={styles.sheetHandle} />
          <ScrollView
            automaticallyAdjustKeyboardInsets
            bounces={false}
            contentContainerStyle={styles.sheetContent}
            keyboardShouldPersistTaps="handled"
            showsVerticalScrollIndicator={false}>
            <Text style={styles.sheetEyebrow}>كود الوصول</Text>
            <Text style={styles.sheetTitle}>فعّل وصولك إلى هذا الكورس</Text>
            <Text style={styles.sheetDescription}>
              أدخل الكود الذي سلّمته لك الجهة التعليمية
              {'\n'}لن تبدأ أي عملية دفع
            </Text>
            <CourseCodeEntry
              codeBusy={codeBusy}
              courseCode={courseCode}
              onCourseCodeChange={onCourseCodeChange}
              onRedeemCourseCode={onRedeemCourseCode}
            />
            {!!notice && (
              <Text accessibilityLiveRegion="polite" style={styles.notice}>
                {notice}
              </Text>
            )}
            <Pressable
              accessibilityLabel="إغلاق نافذة تفعيل الكود"
              accessibilityRole="button"
              accessibilityState={{disabled: codeBusy}}
              disabled={codeBusy}
              onPress={onClose}
              style={({pressed}) => [
                styles.codeDialogClose,
                pressed && styles.pressed,
                codeBusy && styles.disabled,
              ]}>
              <Text style={styles.codeDialogCloseText}>إغلاق</Text>
            </Pressable>
          </ScrollView>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
};

type CoursePurchaseDialogProps = {
  accessPlans: CourseAccessPlan[];
  balance: number;
  bottomInset: number;
  busy: boolean;
  codeBusy?: boolean;
  courseTitle: string;
  courseCode?: string;
  courseCodeEnabled?: boolean;
  couponApplied?: boolean;
  couponBusy?: boolean;
  couponCode?: string;
  couponDiscountAmount?: number;
  dialogStep: DialogStep;
  grantActivated: boolean;
  isTablet: boolean;
  notice: string;
  onBuyCoins: (coinPackage: DemoCoinPackage) => void | Promise<void>;
  onApplyCoupon?: () => void | Promise<void>;
  onCouponCodeChange?: (value: string) => void;
  onClose: () => void;
  onConfirmPurchase: () => void | Promise<void>;
  onCourseCodeChange?: (value: string) => void;
  onRedeemCourseCode?: () => void | Promise<void>;
  onSelectPlan: (plan: CourseAccessPlan) => void;
  onSuccessStart: () => void;
  packages: DemoCoinPackage[];
  originalPurchasePrice?: number;
  purchasePrice: number;
  rewardContributionLimit: number;
  rewardContributionPercent: number;
  selectedPlan?: CourseAccessPlan;
  shortfall: number;
  sufficientPackage?: DemoCoinPackage;
  usableCurrentBalance: number;
};

export const CoursePurchaseDialog = ({
  accessPlans,
  balance,
  bottomInset,
  busy,
  codeBusy = false,
  courseTitle,
  courseCode = '',
  courseCodeEnabled = false,
  couponApplied = false,
  couponBusy = false,
  couponCode = '',
  couponDiscountAmount = 0,
  dialogStep,
  grantActivated,
  isTablet,
  notice,
  onBuyCoins,
  onApplyCoupon = () => undefined,
  onCouponCodeChange = () => undefined,
  onClose,
  onConfirmPurchase,
  onCourseCodeChange = () => undefined,
  onRedeemCourseCode = () => undefined,
  onSelectPlan,
  onSuccessStart,
  packages,
  purchasePrice,
  originalPurchasePrice = purchasePrice,
  rewardContributionLimit,
  rewardContributionPercent,
  selectedPlan,
  shortfall,
  sufficientPackage,
  usableCurrentBalance,
}: CoursePurchaseDialogProps) => {
  const reducedMotion = useReducedMotion();
  const insets = useSafeAreaInsets();
  const horizontalPadding = isTablet ? 28 : 18;
  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'slide'}
      onRequestClose={() => {
        if (!busy && !couponBusy && !codeBusy) onClose();
      }}
      statusBarTranslucent
      transparent
      visible={dialogStep !== null}>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        style={styles.modalRoot}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="إغلاق"
          accessibilityState={{disabled: busy || couponBusy || codeBusy}}
          disabled={busy || couponBusy || codeBusy}
          onPress={onClose}
          style={styles.modalBackdrop}
        />
        <View
          accessibilityLabel="خيارات شراء الكورس"
          accessibilityViewIsModal
          style={[
            styles.sheet,
            {
              paddingBottom: Math.max(bottomInset, 16) + 10,
              paddingLeft: Math.max(horizontalPadding, insets.left + 12),
              paddingRight: Math.max(horizontalPadding, insets.right + 12),
            },
          ]}>
          <View style={styles.sheetHandle} />
          <ScrollView
            automaticallyAdjustKeyboardInsets
            bounces={false}
            contentContainerStyle={styles.sheetContent}
            keyboardShouldPersistTaps="handled"
            style={styles.sheetScroll}
            showsVerticalScrollIndicator={false}>
            {dialogStep === 'plans' && (
              <>
                <Text style={styles.sheetEyebrow}>اختر مستوى الدعم</Text>
                <Text style={styles.sheetTitle}>
                  الكورس واحد والدعم حسب احتياجك
                </Text>
                <Text style={styles.sheetDescription}>
                  ادفع مقابل الدعم الذي ستستخدمه فعلًا
                  {'\n'}محتوى الكورس موجود في كل اختيار
                </Text>
                <View style={styles.planList}>
                  {accessPlans.map(plan => (
                    <Pressable
                      accessibilityRole="button"
                      accessibilityState={{
                        disabled: codeBusy,
                        selected: plan.code === selectedPlan?.code,
                      }}
                      disabled={codeBusy}
                      key={plan.code}
                      onPress={() => onSelectPlan(plan)}
                      style={({pressed}) => [
                        styles.planCard,
                        plan.code === selectedPlan?.code &&
                          styles.planCardSelected,
                        pressed && styles.pressed,
                        codeBusy && styles.disabled,
                      ]}>
                      <View style={styles.planHeader}>
                        <Text style={styles.planName}>{plan.name}</Text>
                        <CoinAmount size={17} value={plan.priceCoins} />
                      </View>
                      <View style={styles.planBenefits}>
                        {planBenefits(plan).map(item => (
                          <View key={item} style={styles.planBenefitRow}>
                            <View style={styles.planCheck} />
                            <Text style={styles.planBenefitText}>{item}</Text>
                          </View>
                        ))}
                      </View>
                    </Pressable>
                  ))}
                </View>
                {courseCodeEnabled && (
                  <CourseCodeEntry
                    codeBusy={codeBusy}
                    courseCode={courseCode}
                    onCourseCodeChange={onCourseCodeChange}
                    onRedeemCourseCode={onRedeemCourseCode}
                  />
                )}
              </>
            )}
            {dialogStep === 'topup' && (
              <>
                <Text style={styles.sheetEyebrow}>دفعة واحدة</Text>
                <Text style={styles.sheetTitle}>
                  افتح {selectedPlan?.name || 'الفئة المختارة'} الآن
                </Text>
                <Text style={styles.sheetDescription}>
                  اختر باقة تغطي الرصيد الناقص
                  {'\n'}وسنفتح الفئة بعد تأكيد الدفع
                </Text>
                <View style={styles.topupSummary}>
                  <View style={styles.topupMetric}>
                    <Text style={styles.summaryLabel}>
                      {couponApplied ? 'بعد الخصم' : 'سعر الكورس'}
                    </Text>
                    <CoinAmount size={18} value={purchasePrice} />
                  </View>
                  <View style={styles.topupMetric}>
                    <Text style={styles.summaryLabel}>رصيدك</Text>
                    <CoinAmount size={18} value={balance} />
                  </View>
                  <View style={styles.topupMetric}>
                    <Text style={styles.summaryLabel}>سنستخدم الآن</Text>
                    <CoinAmount size={18} value={usableCurrentBalance} />
                  </View>
                </View>
                {rewardContributionLimit < purchasePrice && (
                  <Text style={styles.packageUnavailable}>
                    عملات المكافآت تغطي حتى{' '}
                    {formatArabicNumber(rewardContributionPercent)}٪ من هذه
                    الفئة
                    {'\n'}ينقصك {formatArabicNumber(shortfall)} عملة ركن
                  </Text>
                )}
                <View style={styles.packageList}>
                  {packages.length ? (
                    packages.map(item => {
                      const remainingAfterPurchase = Math.max(
                        0,
                        balance + item.coins - purchasePrice,
                      );
                      const isQuickChoice = item.id === sufficientPackage?.id;

                      return (
                        <Pressable
                          accessibilityLabel={`اشحن ${formatArabicNumber(
                            item.coins,
                          )} عملة ركن مقابل ${
                            item.displayPrice ||
                            `${formatArabicNumber(item.price)} جنيه`
                          }`}
                          accessibilityRole="button"
                          disabled={busy}
                          key={item.id}
                          onPress={() => void onBuyCoins(item)}
                          style={({pressed}) => [
                            styles.packageCard,
                            isQuickChoice && styles.packageCardSufficient,
                            pressed && styles.pressed,
                            busy && styles.disabled,
                          ]}>
                          <View style={styles.packageCopy}>
                            <View style={styles.planHeader}>
                              <CoinAmount size={18} value={item.coins} />
                              {isQuickChoice && (
                                <Text style={styles.sheetEyebrow}>
                                  الاختيار السريع
                                </Text>
                              )}
                            </View>
                            <Text style={styles.packageUnavailable}>
                              يتبقى {formatArabicNumber(remainingAfterPurchase)}{' '}
                              عملة ركن بعد فتح الفئة
                            </Text>
                          </View>
                          <Text style={styles.packagePrice}>
                            {item.displayPrice ||
                              `${formatArabicNumber(item.price)} جنيه`}
                          </Text>
                        </Pressable>
                      );
                    })
                  ) : (
                    <Text style={styles.packageUnavailable}>
                      لا توجد باقة تغطي هذه الفئة الآن
                      {'\n'}لم يبدأ الدفع ولم يتغير رصيدك
                    </Text>
                  )}
                </View>
              </>
            )}

            {dialogStep === 'confirm' && (
              <>
                <Text style={styles.sheetEyebrow}>تأكيد الفتح</Text>
                <Text style={styles.sheetTitle}>
                  {formatArabicDisplayText(courseTitle)}
                </Text>
                {!!selectedPlan && (
                  <View style={styles.selectedPlanSummary}>
                    <Text style={styles.selectedPlanName}>
                      {selectedPlan.name}
                    </Text>
                    <Text style={styles.selectedPlanDetail}>
                      {selectedPlan.chatEnabled
                        ? `حتى ${formatArabicNumber(
                            selectedPlan.chatMessageLimit,
                          )} رسالة مع Rokn AI`
                        : 'محتوى الكورس دون Rokn AI'}
                    </Text>
                  </View>
                )}
                <View style={styles.purchaseSummary}>
                  <View>
                    <Text style={styles.summaryLabel}>رصيدك</Text>
                    <CoinAmount size={18} value={balance} />
                  </View>
                  <View>
                    <Text style={styles.summaryLabel}>سنستخدم</Text>
                    <CoinAmount size={18} value={purchasePrice} />
                  </View>
                  <View>
                    <Text style={styles.summaryLabel}>يتبقى</Text>
                    <CoinAmount
                      size={18}
                      value={Math.max(0, balance - purchasePrice)}
                    />
                  </View>
                </View>
                {couponApplied && (
                  <Text style={styles.packageUnavailable}>
                    السعر قبل الخصم {formatArabicNumber(originalPurchasePrice)}{' '}
                    عملة ركن
                    {'\n'}وفرت {formatArabicNumber(couponDiscountAmount)} عملة
                    ركن
                  </Text>
                )}
                {rewardContributionLimit < purchasePrice && (
                  <Text style={styles.packageUnavailable}>
                    المتاح من عملات المكافآت لهذه الفئة{' '}
                    {formatArabicNumber(rewardContributionLimit)} عملة ركن
                    {'\n'}يعادل {formatArabicNumber(rewardContributionPercent)}٪
                    من السعر
                  </Text>
                )}
              </>
            )}

            {(dialogStep === 'confirm' || dialogStep === 'topup') && (
              <View style={styles.codeBox}>
                <Text style={styles.codeTitle}>كود خصم</Text>
                <View style={styles.codeRow}>
                  <TextInput
                    accessibilityLabel="كود خصم الكورس"
                    autoCapitalize="characters"
                    autoCorrect={false}
                    editable={!busy && !couponBusy}
                    maxLength={50}
                    onChangeText={onCouponCodeChange}
                    onSubmitEditing={() => void onApplyCoupon()}
                    placeholder="اكتب الكود"
                    placeholderTextColor={Palette.textFaint}
                    returnKeyType="done"
                    style={styles.codeInput}
                    value={couponCode}
                  />
                  <Pressable
                    accessibilityRole="button"
                    accessibilityState={{
                      busy: couponBusy,
                      disabled: busy || couponBusy || !couponCode.trim(),
                    }}
                    disabled={busy || couponBusy || !couponCode.trim()}
                    onPress={() => void onApplyCoupon()}
                    style={({pressed}) => [
                      styles.codeButton,
                      pressed && styles.pressed,
                      (busy || couponBusy || !couponCode.trim()) &&
                        styles.disabled,
                    ]}>
                    {couponBusy ? (
                      <ActivityIndicator color={Palette.text} size="small" />
                    ) : (
                      <Text style={styles.codeButtonText}>
                        {couponApplied ? 'مطبق' : 'تطبيق'}
                      </Text>
                    )}
                  </Pressable>
                </View>
                {couponApplied && (
                  <Text style={styles.reviewCode}>
                    خصم {formatArabicNumber(couponDiscountAmount)} عملة ركن
                  </Text>
                )}
              </View>
            )}

            {dialogStep === 'confirm' && (
              <Pressable
                accessibilityRole="button"
                accessibilityState={{
                  busy,
                  disabled: busy || couponBusy,
                }}
                disabled={busy || couponBusy}
                onPress={onConfirmPurchase}
                style={({pressed}) => [
                  styles.sheetPrimary,
                  pressed && styles.primaryButtonPressed,
                  (busy || couponBusy) && styles.disabled,
                ]}>
                {busy ? (
                  <ActivityIndicator color={Palette.text} />
                ) : (
                  <Text style={styles.sheetPrimaryText}>تأكيد فتح الكورس</Text>
                )}
              </Pressable>
            )}

            {dialogStep === 'success' && (
              <>
                <View style={styles.successMark}>
                  <Text style={styles.successMarkText}>✓</Text>
                </View>
                <Text style={[styles.sheetTitle, styles.centerText]}>
                  {grantActivated ? 'تم تفعيل المنحة' : 'تم فتح الكورس'}
                </Text>
                <Text style={[styles.sheetDescription, styles.centerText]}>
                  {grantActivated
                    ? 'محتوى الكورس متاح لك كاملًا\nيمكنك إضافة Rokn AI والشهادة لاحقًا'
                    : 'الكورس جاهز\nابدأ الآن أو استكمل من الرئيسية'}
                </Text>
                <Pressable
                  accessibilityRole="button"
                  onPress={onSuccessStart}
                  style={({pressed}) => [
                    styles.sheetPrimary,
                    pressed && styles.primaryButtonPressed,
                  ]}>
                  <Text style={styles.sheetPrimaryText}>
                    {grantActivated ? 'ابدأ التعلّم مجانًا' : 'ابدأ أول مقطع'}
                  </Text>
                </Pressable>
              </>
            )}

            {!!notice && (
              <Text accessibilityLiveRegion="polite" style={styles.notice}>
                {notice}
              </Text>
            )}
            {busy && dialogStep === 'topup' && (
              <View style={styles.busyRow}>
                <ActivityIndicator color={Palette.primary} size="small" />
                <Text style={styles.busyText}>جارٍ فتح الدفع</Text>
              </View>
            )}
          </ScrollView>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
};

type CourseRetentionDialogProps = {
  bottomInset: number;
  isTablet: boolean;
  onClose: () => void;
  onOpenWallet: () => void;
  owned: boolean;
  retentionVisible: boolean;
};

export const CourseRetentionDialog = ({
  bottomInset,
  isTablet,
  onClose,
  onOpenWallet,
  owned,
  retentionVisible,
}: CourseRetentionDialogProps) => {
  const reducedMotion = useReducedMotion();
  const insets = useSafeAreaInsets();
  const horizontalPadding = isTablet ? 28 : 18;
  return (
    <Modal
      animationType={reducedMotion ? 'none' : 'fade'}
      onRequestClose={() => onClose()}
      statusBarTranslucent
      transparent
      visible={retentionVisible && !owned}>
      <View style={styles.modalRoot}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="إغلاق اقتراح مهام العملات"
          onPress={() => onClose()}
          style={styles.modalBackdrop}
        />
        <View
          accessibilityViewIsModal
          style={[
            styles.sheet,
            styles.retentionSheet,
            {
              paddingBottom: Math.max(bottomInset, 16) + 10,
              paddingLeft: Math.max(horizontalPadding, insets.left + 12),
              paddingRight: Math.max(horizontalPadding, insets.right + 12),
            },
          ]}>
          <View style={styles.sheetHandle} />
          <ScrollView
            bounces={false}
            contentContainerStyle={styles.retentionContent}
            showsVerticalScrollIndicator={false}>
            <View style={styles.retentionMark}>
              <Text style={styles.retentionMarkText}>＋</Text>
            </View>
            <Text style={[styles.sheetTitle, styles.centerText]}>
              يمكنك المتابعة دون شحن الآن
            </Text>
            <Text style={[styles.sheetDescription, styles.centerText]}>
              أنجز مهمة مرة واحدة واحصل على عملات ركن
              {'\n'}ثم ارجع للكورس من مكانك
            </Text>
            <Pressable
              accessibilityRole="button"
              onPress={() => {
                onClose();
                onOpenWallet();
              }}
              style={({pressed}) => [
                styles.sheetPrimary,
                pressed && styles.primaryButtonPressed,
              ]}>
              <Text style={styles.sheetPrimaryText}>عرض مهام العملات</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={() => onClose()}
              style={({pressed}) => [
                styles.retentionSecondary,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.retentionSecondaryText}>ليس الآن</Text>
            </Pressable>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
};
