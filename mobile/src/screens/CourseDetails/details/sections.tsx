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
import {CAN_START_EXTERNAL_CHECKOUT} from '../../../constants/distribution';
import {Palette, useResponsiveLayout} from '../../../constants/designSystem';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../../../constants/arabicFormatting';
import {createDemoCourse} from '../../../components/VideoPlayer/demoCourse';
import type {CourseDetails as CourseDetailsDto} from '../../../services/roknApi';
import Lessons from '../Lessons';
import styles from './styles';

export const CourseAbout = ({details}: {details?: CourseDetailsDto | null}) => {
  const {isTablet} = useResponsiveLayout();
  const outcomes = details
    ? [
        `${details?.reelCount || 0} خطوة قصيرة يمكن استكمالها دون جلسات طويلة`,
        details?.projectCount
          ? `${details.projectCount} مشروع عبور يثبت المحاولة العملية`
          : 'تقدّم محفوظ والعودة من نفس موضع المشاهدة',
        'خريطة واضحة ومرفقات تظهر في وقتها داخل الرحلة',
      ]
    : [
        'عرض خدمة واضح يمكن إرساله للعميل',
        'قالب نطاق عمل وخطة تسليم تقلل التعديلات',
        'مشروع تخرج يظهر تلقائيًا في بورتفوليو ركن',
      ];
  return (
    <View style={styles.aboutWrap}>
      <View style={[styles.aboutGrid, isTablet && styles.aboutGridTablet]}>
        <View style={styles.aboutMain}>
          <Text style={styles.sectionEyebrow}>ما الذي ستخرج به؟</Text>
          <Text style={styles.sectionTitle}>
            {formatArabicDisplayText(
              details?.title ||
                'نظام عملي لتحويل مهارتك إلى خدمة يشتريها عميل حقيقي',
            )}
          </Text>
          <Text style={styles.bodyCopy}>
            {formatArabicDisplayText(
              details?.description ||
                'ثلاثون خطوة قصيرة تنقلك من تحديد خدمتك، إلى كتابة العرض، ثم إدارة المشروع والتسليم وبناء دراسة حالة محترمة. كل وحدة تنتهي بمشروع عبور بسيط يقيس المحاولة الجادة، لا الإجابة المثالية.',
            )}
          </Text>
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

        <View style={styles.instructorCard}>
          <Image
            source={
              details?.instructorImage
                ? {uri: details.instructorImage}
                : details
                ? require('../../../assets/images/avatar.png')
                : require('../../../assets/images/demo-course/instructor-karim.jpg')
            }
            style={styles.instructorImage}
          />
          <View style={styles.instructorCopy}>
            <Text style={styles.instructorLabel}>مدرب الكورس</Text>
            <Text style={styles.instructorName}>
              {formatArabicDisplayText(details?.instructor || 'كريم منصور')}
            </Text>
            <Text style={styles.instructorBio}>
              {formatArabicDisplayText(
                details
                  ? details.instructorBio || 'مدرب هذا الكورس على ركن.'
                  : 'مصمم منتجات مستقل. يركز على تقديم العمل وإدارة العميل وبناء مشروع يمكن عرضه، لا على حفظ الأدوات.',
              )}
            </Text>
          </View>
        </View>
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
        {formatArabicDisplayText(
          details
            ? `${details.modules.length} وحدات · ${details.reelCount} خطوة · ${details.projectCount} مشروعات عبور`
            : '٣ وحدات · ٣٠ خطوة · ٣ مشروعات عبور',
        )}
      </Text>
      {details && !modules.length && (
        <Text style={styles.lockedNote}>لم تُنشر خريطة هذا الكورس بعد.</Text>
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
                  {formatArabicNumber(module.reelCount)} خطوة
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
        يمكنك رؤية الخريطة قبل الشراء، وتفتح الخطوات والمرفقات بعد فتح الكورس.
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
  projectCount: number;
  reelCount: number;
  remoteCourse: CourseDetailsDto | null;
  remoteLoading: boolean;
  topInset: number;
};

export const CourseHero = ({
  courseTitle,
  gutter,
  heroHeight,
  isDemoCourse,
  maxContentWidth,
  onBack,
  projectCount,
  reelCount,
  remoteCourse,
  remoteLoading,
  topInset,
}: CourseHeroProps) => (
  <View style={[styles.hero, {height: heroHeight}]}>
    <Image
      source={
        !isDemoCourse && remoteCourse?.imageUrl
          ? {uri: remoteCourse.imageUrl}
          : isDemoCourse
          ? require('../../../assets/images/demo-course/ui-freelance-cover.jpg')
          : require('../../../assets/images/courseSliderBackground.jpg')
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
      <View style={styles.heroMetaRow}>
        <View style={styles.categoryPill}>
          <Text style={styles.categoryPillText}>
            {isDemoCourse ? 'عمل حر' : 'تعلّم تطبيقي'}
          </Text>
        </View>
        <Text style={styles.heroMeta}>
          {!isDemoCourse && remoteLoading
            ? 'نجهّز تفاصيل الكورس…'
            : formatArabicDisplayText(
                `${reelCount} خطوة${
                  projectCount ? ` · ${projectCount} مشروعات` : ''
                }`,
              )}
        </Text>
      </View>
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
  previewReelCount: number;
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
  previewReelCount,
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
    {pageReady &&
      ((ratingsCount > 0 && ratingAverage !== null) ||
        studentsCount > 0 ||
        durationMinutes !== null) && (
        <View style={styles.socialProofRow}>
          {ratingsCount > 0 && ratingAverage !== null && (
            <Text style={styles.socialProofText}>
              <Text style={styles.ratingText}>
                ★{' '}
                {formatArabicNumber(ratingAverage, {
                  minimumFractionDigits: 1,
                  maximumFractionDigits: 1,
                })}
              </Text>{' '}
              {formatArabicNumber(ratingsCount)} تقييم
            </Text>
          )}
          {studentsCount > 0 && (
            <>
              {ratingsCount > 0 && ratingAverage !== null && (
                <View style={styles.socialProofDot} />
              )}
              <Text style={styles.socialProofText}>
                {formatArabicNumber(studentsCount)} طالب
              </Text>
            </>
          )}
          {durationMinutes !== null && (
            <>
              {((ratingsCount > 0 && ratingAverage !== null) ||
                studentsCount > 0) && <View style={styles.socialProofDot} />}
              <Text style={styles.socialProofText}>
                {formatArabicNumber(durationMinutes)} دقيقة
              </Text>
            </>
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
      {!owned && hasPreview && pageReady && CAN_START_EXTERNAL_CHECKOUT && (
        <Pressable
          accessibilityLabel={
            previewReelCount === 1
              ? 'مشاهدة الخطوة المجانية'
              : 'مشاهدة الخطوات المجانية'
          }
          accessibilityRole="button"
          onPress={onPreview}
          style={({pressed}) => [
            styles.previewButton,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.previewButtonText}>
            {previewReelCount === 1
              ? 'شاهد الخطوة المجانية'
              : `شاهد أول ${formatArabicNumber(previewReelCount)} خطوات مجانًا`}
          </Text>
        </Pressable>
      )}
    </View>
  </View>
);

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
