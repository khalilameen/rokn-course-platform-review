import React from 'react';
import {
  ActivityIndicator,
  Modal,
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
    <Text style={styles.codeTitle}>لديك كود من جهة تعليمية؟</Text>
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
}: CourseCodeRedemptionDialogProps) => (
  <Modal
    animationType="slide"
    onRequestClose={() => {
      if (!codeBusy) onClose();
    }}
    transparent
    visible={visible}>
    <View style={styles.modalRoot}>
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
            paddingHorizontal: isTablet ? 28 : 18,
          },
        ]}>
        <View style={styles.sheetHandle} />
        <Text style={styles.sheetEyebrow}>كود الوصول</Text>
        <Text style={styles.sheetTitle}>فعّل وصولك إلى هذا الكورس</Text>
        <Text style={styles.sheetDescription}>
          أدخل الكود الذي سلّمته لك الجهة التعليمية. لن يبدأ هذا الإجراء أي
          عملية دفع.
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
      </View>
    </View>
  </Modal>
);

type CoursePurchaseDialogProps = {
  accessPlans: CourseAccessPlan[];
  balance: number;
  bottomInset: number;
  busy: boolean;
  courseTitle: string;
  dialogStep: DialogStep;
  grantActivated: boolean;
  isTablet: boolean;
  notice: string;
  onBuyCoins: (coinPackage: DemoCoinPackage) => void | Promise<void>;
  onClose: () => void;
  onConfirmPurchase: () => void | Promise<void>;
  onSelectPlan: (plan: CourseAccessPlan) => void;
  onSuccessStart: () => void;
  packages: DemoCoinPackage[];
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
  courseTitle,
  dialogStep,
  grantActivated,
  isTablet,
  notice,
  onBuyCoins,
  onClose,
  onConfirmPurchase,
  onSelectPlan,
  onSuccessStart,
  packages,
  purchasePrice,
  rewardContributionLimit,
  rewardContributionPercent,
  selectedPlan,
  shortfall,
  sufficientPackage,
  usableCurrentBalance,
}: CoursePurchaseDialogProps) => {
  return (
    <Modal
      animationType="slide"
      onRequestClose={onClose}
      transparent
      visible={dialogStep !== null}>
      <View style={styles.modalRoot}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="إغلاق"
          accessibilityState={{disabled: busy}}
          disabled={busy}
          onPress={onClose}
          style={styles.modalBackdrop}
        />
        <View
          style={[
            styles.sheet,
            {
              paddingBottom: Math.max(bottomInset, 16) + 10,
              paddingHorizontal: isTablet ? 28 : 18,
            },
          ]}>
          <View style={styles.sheetHandle} />
          <ScrollView
            bounces={false}
            contentContainerStyle={styles.sheetContent}
            keyboardShouldPersistTaps="handled"
            style={styles.sheetScroll}
            showsVerticalScrollIndicator={false}>
            {dialogStep === 'plans' && (
              <>
                <Text style={styles.sheetEyebrow}>اختر مستوى الدعم</Text>
                <Text style={styles.sheetTitle}>
                  الكورس واحد والدعم على قد احتياجك
                </Text>
                <Text style={styles.sheetDescription}>
                  ادفع مقابل الدعم الذي ستستخدمه فعلًا. التعلّم والمشروعات
                  موجودان في كل اختيار.
                </Text>
                <View style={styles.planList}>
                  {accessPlans.map(plan => (
                    <Pressable
                      accessibilityRole="button"
                      key={plan.code}
                      onPress={() => onSelectPlan(plan)}
                      style={({pressed}) => [
                        styles.planCard,
                        plan.code === selectedPlan?.code &&
                          styles.planCardSelected,
                        pressed && styles.pressed,
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
              </>
            )}
            {dialogStep === 'topup' && (
              <>
                <Text style={styles.sheetEyebrow}>دفعة واحدة</Text>
                <Text style={styles.sheetTitle}>
                  افتح {selectedPlan?.name || 'الفئة المختارة'} الآن
                </Text>
                <Text style={styles.sheetDescription}>
                  سنكمل الرصيد الناقص ونفتح الفئة تلقائيًا من نفس العملية.
                </Text>
                <View style={styles.topupSummary}>
                  <View style={styles.topupMetric}>
                    <Text style={styles.summaryLabel}>سعر الكورس</Text>
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
                    عملات الهدايا تغطي حتى {formatArabicNumber(
                      rewardContributionPercent,
                    )}٪ من قيمة هذه الفئة، والعملات المدفوعة تُستخدم كاملة. ينقصك{' '}
                    {formatArabicNumber(shortfall)} عملة.
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
                        accessibilityLabel={`اشحن ${formatArabicNumber(item.coins)} عملة مقابل ${item.displayPrice || `${formatArabicNumber(item.price)} جنيه`}`}
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
                              <Text style={styles.sheetEyebrow}>الاختيار السريع</Text>
                            )}
                          </View>
                          <Text style={styles.packageUnavailable}>
                            يتبقى {formatArabicNumber(remainingAfterPurchase)} عملة بعد فتح الفئة
                          </Text>
                        </View>
                        <Text style={styles.packagePrice}>
                          {item.displayPrice || `${formatArabicNumber(item.price)} ج.م`}
                        </Text>
                      </Pressable>
                      );
                    })
                  ) : (
                    <Text style={styles.packageUnavailable}>
                      لا توجد حاليًا قيمة شحن تغطي هذه الفئة. لم نبدأ أي دفع ولم يتغير رصيدك.
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
                        : 'التعلّم والمشروعات دون Rokn AI'}
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
                {rewardContributionLimit < purchasePrice && (
                  <Text style={styles.packageUnavailable}>
                    الحد المتاح من عملات الهدايا لهذه الفئة هو{' '}
                    {formatArabicNumber(rewardContributionLimit)} عملة ({formatArabicNumber(
                      rewardContributionPercent,
                    )}٪). رصيدك المتاح يكفي، ولن نستخدم أكثر من سعر الفئة.
                  </Text>
                )}
                <Pressable
                  accessibilityRole="button"
                  disabled={busy}
                  onPress={onConfirmPurchase}
                  style={({pressed}) => [
                    styles.sheetPrimary,
                    pressed && styles.primaryButtonPressed,
                  ]}>
                  {busy ? (
                    <ActivityIndicator color={Palette.text} />
                  ) : (
                    <Text style={styles.sheetPrimaryText}>
                      تأكيد فتح الكورس
                    </Text>
                  )}
                </Pressable>
              </>
            )}

            {dialogStep === 'success' && (
              <>
                <View style={styles.successMark}>
                  <Text style={styles.successMarkText}>✓</Text>
                </View>
                <Text style={[styles.sheetTitle, styles.centerText]}>
                  {grantActivated ? 'منحتك اتفعّلت' : 'الكورس أصبح لك'}
                </Text>
                <Text style={[styles.sheetDescription, styles.centerText]}>
                  {grantActivated
                    ? 'الكورس ومشروعاته متاحة لك كاملة\nيمكنك إضافة Rokn AI والشهادة لاحقًا'
                    : 'حفظنا مكانك، وفتحت الوحدة الأولى. ابدأ الآن أو عد إليها من الصفحة الرئيسية في أي وقت.'}
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

            {!!notice && <Text style={styles.notice}>{notice}</Text>}
            {busy && dialogStep === 'topup' && (
              <View style={styles.busyRow}>
                <ActivityIndicator color={Palette.primary} size="small" />
                <Text style={styles.busyText}>لحظة ونكمل…</Text>
              </View>
            )}
          </ScrollView>
        </View>
      </View>
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
}: CourseRetentionDialogProps) => (
  <Modal
    animationType="fade"
    onRequestClose={() => onClose()}
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
            paddingHorizontal: isTablet ? 28 : 18,
          },
        ]}>
        <View style={styles.sheetHandle} />
        <View style={styles.retentionContent}>
          <View style={styles.retentionMark}>
            <Text style={styles.retentionMarkText}>＋</Text>
          </View>
          <Text style={[styles.sheetTitle, styles.centerText]}>
            يمكنك المتابعة دون شحن الآن
          </Text>
          <Text style={[styles.sheetDescription, styles.centerText]}>
            في مهام مجانية تُنجز مرة واحدة وتضيف عملات لرصيدك. اختر ما يناسبك
            وارجع للكورس من نفس مكانك.
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
        </View>
      </View>
    </View>
  </Modal>
);
