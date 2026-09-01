import React, {useMemo, useState} from 'react';
import {
  ActivityIndicator,
  Image,
  LayoutChangeEvent,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import {CourseDetailsSkeleton} from '../../../components/ui/Skeleton';
import {StatusView} from '../../../components/ui/PremiumUI';
import {CourseArtwork} from '../../../components/ui/CourseArtwork';
import {CAN_START_COIN_CHECKOUT} from '../../../constants/distribution';
import {Palette, useResponsiveLayout} from '../../../constants/designSystem';
import {
  formatArabicDisplayText,
  formatArabicMinutes,
  formatArabicNumber,
  formatArabicRatings,
  formatArabicStudents,
} from '../../../constants/arabicFormatting';
import {createDemoCourse} from '../../../components/VideoPlayer/demoCourse';
import type {CourseDetails as CourseDetailsDto} from '../../../services/roknApi';
import Lessons from '../Lessons';
import styles from './styles';

export const CourseAbout = ({details}: {details?: CourseDetailsDto | null}) => {
  const {isTablet} = useResponsiveLayout();
  const isLocalDemo = !details;
  const title = details
    ? details.title
    : 'نظام عملي لتحويل مهارتك إلى خدمة يشتريها عميل حقيقي';
  const description = details
    ? details.description
    : 'ثلاثون مقطعًا قصيرًا من تحديد خدمتك إلى إدارة المشروع والتسليم\nتنتهي كل وحدة بمشروع عبور عند الحاجة';
  const instructorName = details ? details.instructor : 'كريم منصور';
  const instructorBio = details
    ? details.instructorBio
    : 'مصمم منتجات مستقل\nيركز على تقديم العمل وإدارة العميل وبناء مشروع يمكن عرضه';
  const outcomes = details
    ? []
    : [
        'عرض خدمة واضح يمكن إرساله للعميل',
        'قالب نطاق عمل وخطة تسليم تقلل التعديلات',
        'مشروع تخرج يظهر تلقائيًا في بورتفوليو ركن',
      ];
  return (
    <View style={styles.aboutWrap}>
      <View style={[styles.aboutGrid, isTablet && styles.aboutGridTablet]}>
        <View style={styles.aboutMain}>
          <Text style={styles.sectionEyebrow}>عن الكورس</Text>
          {!!title && (
            <Text style={styles.sectionTitle}>
              {formatArabicDisplayText(title)}
            </Text>
          )}
          {!!description && (
            <Text style={styles.bodyCopy}>
              {formatArabicDisplayText(description)}
            </Text>
          )}
          <View style={styles.outcomes}>
            {outcomes.map((item, index) => (
              <View key={item} style={styles.outcomeRow}>
                <View style={styles.outcomeNumber}>
                  <Text style={styles.outcomeNumberText}>
                    {formatArabicNumber(index + 1)}
                  </Text>
                </View>
                <Text style={styles.outcomeText}>
                  {formatArabicDisplayText(item)}
                </Text>
              </View>
            ))}
          </View>
        </View>

        {(isLocalDemo || !!instructorName) && (
          <View style={styles.instructorCard}>
            <Image
              source={
                details?.instructorImage
                  ? {uri: details.instructorImage}
                  : details
                  ? require('../../../assets/images/default-avatar.png')
                  : require('../../../assets/images/demo-course/instructor-karim.jpg')
              }
              style={styles.instructorImage}
            />
            <View style={styles.instructorCopy}>
              <Text style={styles.instructorLabel}>مدرب الكورس</Text>
              <Text style={styles.instructorName}>
                {formatArabicDisplayText(instructorName)}
              </Text>
              {(isLocalDemo || !!instructorBio) && (
                <Text style={styles.instructorBio}>
                  {formatArabicDisplayText(instructorBio)}
                </Text>
              )}
            </View>
          </View>
        )}
      </View>
    </View>
  );
};

export const LockedOutline = ({
  details,
  onPreviewSelect,
}: {
  details?: CourseDetailsDto | null;
  onPreviewSelect: (reelId: string) => void;
}) => {
  const [expandedModuleId, setExpandedModuleId] = useState<string | null>(null);
  const modules = useMemo(() => {
    if (details) return details.modules;
    return createDemoCourse().modules.map(module => ({
      id: module.id,
      title: module.title,
      reelCount: module.reels.length,
      projectCount: module.project ? 1 : 0,
      previewReelCount: module.reels.filter(reel => reel.isPreview).length,
      items: [
        ...module.reels.map(reel => ({
          id: reel.id,
          title: reel.title,
          type: 'reel' as const,
          isPreview: reel.isPreview,
          reelNumber: reel.reelNumber,
          reelId: reel.id,
        })),
        ...(module.project
          ? [
              {
                id: module.project.id,
                title: module.project.title,
                type: 'project' as const,
                isPreview: false,
              },
            ]
          : []),
      ],
    }));
  }, [details]);
  return (
    <View style={styles.lockedOutline}>
      <Text style={styles.sectionEyebrow}>خريطة الكورس</Text>
      <Text style={styles.sectionTitle}>
        الوحدات والمقاطع
      </Text>
      {details && !modules.length && (
        <Text style={styles.lockedNote}>لم تُنشر خريطة هذا الكورس بعد</Text>
      )}
      {modules.map((module, index) => {
        const expanded = expandedModuleId === module.id;
        return (
          <View key={module.id} style={styles.modulePreview}>
            <Pressable
              accessibilityRole="button"
              accessibilityState={{expanded}}
              onPress={() => setExpandedModuleId(expanded ? null : module.id)}
              style={({pressed}) => [
                styles.moduleHeader,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.moduleNumber}>
                {formatArabicNumber(index + 1, {
                  minimumIntegerDigits: 2,
                  useGrouping: false,
                })}
              </Text>
              <View style={styles.moduleCopy}>
                <Text style={styles.moduleTitle}>
                  {formatArabicDisplayText(module.title)}
                </Text>
                <Text style={styles.moduleMeta}>
                  {formatArabicNumber(module.reelCount)} مقطع
                  {module.projectCount ? ' · مشروع عبور' : ''}
                </Text>
              </View>
              <Text
                style={[
                  styles.expandSymbol,
                  expanded && styles.expandSymbolOpen,
                ]}>
                ⌄
              </Text>
            </Pressable>
            {expanded && (
              <View style={styles.outlineItems}>
                {module.items.map(item => {
                  const canPreview = item.type === 'reel' && item.isPreview;
                  return (
                    <Pressable
                      accessibilityRole={canPreview ? 'button' : undefined}
                      disabled={!canPreview}
                      key={item.id}
                      onPress={() =>
                        onPreviewSelect(
                          'reelId' in item && item.reelId
                            ? item.reelId
                            : item.id,
                        )
                      }
                      style={({pressed}) => [
                        styles.outlineItem,
                        canPreview && styles.outlineItemPreview,
                        pressed && styles.pressed,
                      ]}>
                      <View style={styles.outlineItemCopy}>
                        <Text style={styles.outlineItemTitle}>
                          {formatArabicDisplayText(item.title)}
                        </Text>
                        <Text style={styles.outlineItemMeta}>
                          {item.type === 'project'
                            ? 'مشروع عبور · يُفتح بعد إكمال الوحدة'
                            : item.type === 'quiz'
                            ? 'اختبار قصير · يُفتح بعد إكمال المقاطع'
                            : canPreview
                            ? 'مفتوح للمشاهدة الآن'
                            : 'يُفتح مع الكورس'}
                        </Text>
                      </View>
                      <View
                        style={[
                          styles.itemStatus,
                          canPreview && styles.itemStatusOpen,
                        ]}>
                        <Text
                          style={[
                            styles.itemStatusText,
                            canPreview && styles.itemStatusTextOpen,
                          ]}>
                          {item.type === 'project'
                            ? 'مشروع'
                            : item.type === 'quiz'
                            ? 'اختبار'
                            : canPreview
                            ? 'شاهد'
                            : 'مغلق'}
                        </Text>
                      </View>
                    </Pressable>
                  );
                })}
              </View>
            )}
          </View>
        );
      })}
      <Text style={styles.lockedNote}>
        يمكنك رؤية الخريطة قبل الشراء
        {'\n'}تُفتح المقاطع والمرفقات بعد شراء الكورس
      </Text>
    </View>
  );
};

type CourseHeroProps = {
  courseTitle: string;
  gutter: number;
  heroHeight: number;
  isDemoCourse: boolean;
  maxContentWidth: number;
  onBack: () => void;
  remoteCourse: CourseDetailsDto | null;
  topInset: number;
};

export const CourseHero = ({
  courseTitle,
  gutter,
  heroHeight,
  isDemoCourse,
  maxContentWidth,
  onBack,
  remoteCourse,
  topInset,
}: CourseHeroProps) => (
  <View style={[styles.hero, {height: heroHeight}]}>
    <CourseArtwork
      fallback={
        isDemoCourse
          ? require('../../../assets/images/demo-course/ui-freelance-cover.jpg')
          : require('../../../assets/images/courseSliderBackground.jpg')
      }
      source={
        !isDemoCourse && remoteCourse?.imageUrl
          ? {uri: remoteCourse.imageUrl}
          : undefined
      }
      style={styles.heroImage}
    />
    <LinearGradient
      colors={['rgba(7,10,16,0.1)', 'rgba(7,10,16,0.54)', Palette.canvas]}
      locations={[0, 0.5, 1]}
      style={StyleSheet.absoluteFill}
    />
    <Pressable
      accessibilityLabel="العودة"
      accessibilityRole="button"
      hitSlop={8}
      onPress={onBack}
      style={({pressed}) => [
        styles.backButton,
        {top: topInset + 10},
        pressed && styles.pressed,
      ]}>
      <Text style={styles.backIcon}>›</Text>
    </Pressable>

    <View
      style={[
        styles.heroContent,
        {
          paddingHorizontal: gutter,
          maxWidth: maxContentWidth,
        },
      ]}>
      <Text style={styles.heroTitle}>
        {formatArabicDisplayText(courseTitle)}
      </Text>
    </View>
  </View>
);

type CourseIntroProps = {
  courseDescription: string;
  durationMinutes: number | null;
  hasPreview: boolean;
  onPrimaryAction: () => void;
  onPrimaryActionLayout: (event: LayoutChangeEvent) => void;
  onPreview: () => void;
  owned: boolean;
  pageReady: boolean;
  primaryActionLabel: string;
  ratingAverage: number | null;
  ratingsCount: number;
  remoteError: string;
  studentsCount: number;
};

export const CourseIntro = ({
  courseDescription,
  durationMinutes,
  hasPreview,
  onPrimaryAction: handlePrimaryAction,
  onPrimaryActionLayout,
  onPreview,
  owned,
  pageReady,
  primaryActionLabel,
  ratingAverage,
  ratingsCount,
  remoteError,
  studentsCount,
}: CourseIntroProps) => (
  <View style={styles.courseIntro}>
    <Text style={styles.heroSubtitle}>
      {formatArabicDisplayText(courseDescription)}
    </Text>
    {pageReady && (
      <View style={styles.socialProofRow}>
        {durationMinutes !== null && (
          <Text style={styles.socialProofText}>
            {formatArabicMinutes(durationMinutes)}
          </Text>
        )}
        {durationMinutes !== null && <View style={styles.socialProofDot} />}
        {ratingsCount > 0 && ratingAverage !== null ? (
          <Text style={styles.socialProofText}>
            <Text style={styles.ratingText}>
              ★{' '}
              {formatArabicNumber(ratingAverage, {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1,
              })}
            </Text>{' '}
            {formatArabicRatings(ratingsCount)}
          </Text>
        ) : (
          <Text style={styles.socialProofText}>جديد</Text>
        )}
        {studentsCount > 0 && <View style={styles.socialProofDot} />}
        {studentsCount > 0 && (
          <Text style={styles.socialProofText}>
            {formatArabicStudents(studentsCount)}
          </Text>
        )}
      </View>
    )}
    <View onLayout={onPrimaryActionLayout} style={styles.priceAndAction}>
      <Pressable
        accessibilityRole="button"
        disabled={!pageReady}
        onPress={handlePrimaryAction}
        style={({pressed}) => [
          styles.primaryButton,
          pressed && styles.primaryButtonPressed,
          !pageReady && styles.disabled,
        ]}>
        {remoteError ? (
          <Text style={styles.primaryButtonText}>تعذّر تحميل التفاصيل</Text>
        ) : !pageReady ? (
          <ActivityIndicator color={Palette.text} />
        ) : (
          <Text style={styles.primaryButtonText}>{primaryActionLabel}</Text>
        )}
      </Pressable>
      {!owned && hasPreview && pageReady && CAN_START_COIN_CHECKOUT && (
        <Pressable
          accessibilityLabel="شاهد مجانًا"
          accessibilityRole="button"
          onPress={onPreview}
          style={({pressed}) => [
            styles.previewButton,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.previewButtonText}>
            شاهد مجانًا
          </Text>
        </Pressable>
      )}
    </View>
  </View>
);

type CourseRatingActionProps = {
  busy: boolean;
  editable: boolean;
  onDelete: () => void;
  onRate: (rating: number) => void;
  rating: number | null;
  visible: boolean;
};

export const CourseRatingAction = ({
  busy,
  editable,
  onDelete,
  onRate,
  rating,
  visible,
}: CourseRatingActionProps) => {
  if (!visible) return null;

  return (
    <View style={styles.ratingAction}>
      <Text style={styles.ratingActionTitle}>
        {rating ? 'تقييمك للكورس' : 'قيّم الكورس'}
      </Text>
      <View style={styles.ratingStars}>
        {[1, 2, 3, 4, 5].map(value => (
          <Pressable
            accessibilityLabel={`${value} من 5`}
            accessibilityRole="button"
            accessibilityState={{
              selected: rating === value,
              disabled: busy || !editable,
            }}
            disabled={busy || !editable}
            key={value}
            onPress={() => onRate(value)}
            style={({pressed}) => [
              styles.ratingStarButton,
              !editable && styles.disabled,
              pressed && styles.pressed,
            ]}>
            <Text
              style={[
                styles.ratingStar,
                value <= (rating ?? 0) && styles.ratingStarSelected,
              ]}>
              ★
            </Text>
          </Pressable>
        ))}
        {busy && <ActivityIndicator color={Palette.primary} size="small" />}
      </View>
      {rating && !busy ? (
        <Pressable
          accessibilityLabel="حذف تقييمي"
          accessibilityRole="button"
          onPress={onDelete}
          style={({pressed}) => [
            styles.ratingDeleteButton,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.ratingDeleteText}>حذف تقييمي</Text>
        </Pressable>
      ) : null}
    </View>
  );
};

type CourseBodyProps = {
  activeTab: 'about' | 'outline';
  isDemoCourse: boolean;
  onPreviewSelect: (reelId?: string) => void;
  onRetry: () => void;
  onTabChange: (tab: 'about' | 'outline') => void;
  owned: boolean;
  remoteCourse: CourseDetailsDto | null;
  remoteError: string;
  remoteLoading: boolean;
};

export const CourseBody = ({
  activeTab,
  isDemoCourse,
  onPreviewSelect: startPreview,
  onRetry,
  onTabChange,
  owned,
  remoteCourse,
  remoteError,
  remoteLoading,
}: CourseBodyProps) => (
  <>
    {!isDemoCourse && remoteLoading ? (
      <CourseDetailsSkeleton />
    ) : !isDemoCourse && remoteError ? (
      <StatusView
        actionLabel="إعادة المحاولة"
        description={remoteError}
        onAction={onRetry}
        state="error"
        title="تعذّر فتح تفاصيل الكورس"
      />
    ) : (
      <>
        <View style={styles.tabs} accessibilityRole="tablist">
          <Pressable
            accessibilityRole="tab"
            accessibilityState={{selected: activeTab === 'about'}}
            onPress={() => onTabChange('about')}
            style={[styles.tab, activeTab === 'about' && styles.tabActive]}>
            <Text
              style={[
                styles.tabText,
                activeTab === 'about' && styles.tabTextActive,
              ]}>
              عن الكورس
            </Text>
          </Pressable>
          <Pressable
            accessibilityRole="tab"
            accessibilityState={{selected: activeTab === 'outline'}}
            onPress={() => onTabChange('outline')}
            style={[styles.tab, activeTab === 'outline' && styles.tabActive]}>
            <Text
              style={[
                styles.tabText,
                activeTab === 'outline' && styles.tabTextActive,
              ]}>
              خريطة الكورس
            </Text>
          </Pressable>
        </View>
        {activeTab === 'about' ? (
          <CourseAbout details={isDemoCourse ? null : remoteCourse} />
        ) : owned ? (
          <Lessons />
        ) : (
          <LockedOutline
            details={isDemoCourse ? null : remoteCourse}
            onPreviewSelect={startPreview}
          />
        )}
      </>
    )}
  </>
);

type StickyCourseActionProps = {
  bottomInset: number;
  label: string;
  onPress: () => void;
  visible: boolean;
};

export const StickyCourseAction = ({
  bottomInset,
  label: primaryActionLabel,
  onPress: handlePrimaryAction,
  visible,
}: StickyCourseActionProps) => {
  if (!visible) return null;
  return (
    <View
      style={[styles.stickyAction, {paddingBottom: Math.max(bottomInset, 10)}]}>
      <Pressable
        accessibilityRole="button"
        onPress={handlePrimaryAction}
        style={({pressed}) => [
          styles.stickyButton,
          pressed && styles.primaryButtonPressed,
        ]}>
        <Text style={styles.primaryButtonText}>{primaryActionLabel}</Text>
      </Pressable>
    </View>
  );
};
