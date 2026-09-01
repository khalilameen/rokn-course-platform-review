import {useFocusEffect, useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import React, {useCallback, useEffect, useState} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import TabBar from '../components/TabBar';
import {Container, Content} from '../components/containers/Containers';
import {
  MetaPill,
  PremiumCard,
  ResponsiveFrame,
  SectionHeading,
  StatusView,
} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {
  LearningDashboardSkeleton,
  SkeletonBlock,
} from '../components/ui/Skeleton';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../constants/designSystem';
import {
  DEMO_COURSE_ID,
  subscribeDemoExperience,
} from '../services/demoExperience';
import type {DemoExperienceState} from '../services/demoExperience';
import {getLocalLearningState} from '../components/VideoPlayer/courseLearningApi';
import {createDemoCourse} from '../components/VideoPlayer/demoCourse';
import {
  getCachedLearningDashboard,
  getLearningDashboard,
  getWatchHistory,
  hasSession,
} from '../services/roknApi';
import type {
  LearningCourse,
  LearningDashboard,
  WatchHistory,
} from '../services/roknApi';
import StreakFlame from '../components/ui/StreakFlame';
import {CourseArtwork} from '../components/ui/CourseArtwork';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../constants/arabicFormatting';
import {LOCAL_DEMO_ENABLED} from '../config/runtime';
import {friendlyNetworkMessage} from '../services/networkExperience';
import {
  roknCalendarDay,
  shiftRoknCalendarDay,
} from '../constants/roknCalendar';
import {serverNow} from '../utils/serverClock';

const juniorBadgeImage = require('../assets/images/badges/junior.png');
const midLevelBadgeImage = require('../assets/images/badges/mid-level.png');
const seniorBadgeImage = require('../assets/images/badges/senior.png');
const demoLearningSteps = createDemoCourse().modules.flatMap(
  module => module.reels,
);

type LearningBadge = LearningDashboard['badges'][number];

const localBadgeImage = (title: string) => {
  if (/senior/i.test(title)) {
    return seniorBadgeImage;
  }
  if (/mid/i.test(title)) {
    return midLevelBadgeImage;
  }
  return juniorBadgeImage;
};

const lastSevenDays = (activeDays: string[]) =>
  Array.from({length: 7}, (_, index) => {
    const key = shiftRoknCalendarDay(
      roknCalendarDay(serverNow()),
      -(6 - index),
    );
    return {
      key,
      day: new Date(`${key}T12:00:00Z`).toLocaleDateString('ar-EG', {
        weekday: 'narrow',
        timeZone: 'Africa/Cairo',
      }),
      complete: activeDays.includes(key),
    };
  });

const currentStreakFromDays = (activeDays: string[]) => {
  const active = new Set(activeDays);
  const today = roknCalendarDay(serverNow());
  let cursor = active.has(today) ? today : shiftRoknCalendarDay(today, -1);
  let count = 0;
  while (active.has(cursor)) {
    count += 1;
    cursor = shiftRoknCalendarDay(cursor, -1);
  }
  return count;
};

export default function MyCorner() {
  const navigation = useNavigation<RootNavigation>();
  const {largeText} = useResponsiveLayout();
  const [experience, setExperience] = useState<DemoExperienceState | null>(
    null,
  );
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [learningDashboard, setLearningDashboard] =
    useState<LearningDashboard | null>(null);
  const [dashboardError, setDashboardError] = useState('');
  const [dashboardLoading, setDashboardLoading] = useState(false);
  const [watchHistory, setWatchHistory] = useState<WatchHistory | null>(null);
  const [watchHistoryLoading, setWatchHistoryLoading] = useState(false);
  const [watchHistoryError, setWatchHistoryError] = useState('');
  const [learning, setLearning] = useState({
    completedSections: [] as string[],
    passedProjects: [] as string[],
    activityDays: [] as string[],
  });
  useEffect(
    () =>
      LOCAL_DEMO_ENABLED
        ? subscribeDemoExperience(setExperience)
        : () => undefined,
    [],
  );
  useFocusEffect(
    useCallback(() => {
      let active = true;
      (async () => {
        const sessionAvailable = await hasSession();
        if (!active) return;
        setServerSession(sessionAvailable);
        if (!sessionAvailable) {
          setLearningDashboard(null);
          setDashboardError('');
          setWatchHistory(null);
          setWatchHistoryError('');
          setWatchHistoryLoading(false);
          const state = await getLocalLearningState();
          if (active) {
            setLearning({
              completedSections: state.completedSections,
              passedProjects: state.passedProjects,
              activityDays: state.activityDays,
            });
          }
          return;
        }
        setWatchHistoryLoading(true);
        getWatchHistory(6)
          .then(history => {
            if (!active) return;
            setWatchHistory(history);
            setWatchHistoryError('');
          })
          .catch(error => {
            if (!active) return;
            setWatchHistoryError(friendlyNetworkMessage(error, 'سجل المشاهدة'));
          })
          .finally(() => {
            if (active) setWatchHistoryLoading(false);
          });
        const cachedDashboard = await getCachedLearningDashboard().catch(
          () => null,
        );
        if (!active) return;
        if (active && cachedDashboard) {
          setLearningDashboard(cachedDashboard);
          setLearning({
            completedSections: [],
            passedProjects: [],
            activityDays: cachedDashboard.activityDays,
          });
        }
        setDashboardLoading(!cachedDashboard);
        try {
          const dashboard = await getLearningDashboard();
          if (active) {
            setLearningDashboard(dashboard);
            setLearning({
              completedSections: [],
              passedProjects: [],
              activityDays: dashboard.activityDays,
            });
            setDashboardError(dashboard.partialError || '');
          }
        } catch (error) {
          if (active) {
            if (!cachedDashboard) {
              setLearning({
                completedSections: [],
                passedProjects: [],
                activityDays: [],
              });
            }
            setDashboardError(
              cachedDashboard
                ? 'نعرض آخر تقدم محفوظ\nسنحدّثه عند عودة الاتصال'
                : `${friendlyNetworkMessage(error, 'كورساتك')}\nتقدمك محفوظ`,
            );
          }
        } finally {
          if (active) setDashboardLoading(false);
        }
      })();
      return () => {
        active = false;
      };
    }, []),
  );
  const demoHasAccess = Boolean(
    experience?.purchasedCourseIds.includes(DEMO_COURSE_ID),
  );
  const completedCount = learning.completedSections.filter(id =>
    id.startsWith('demo-section-'),
  ).length;
  const passedProjectCount = learning.passedProjects.filter(id =>
    id.startsWith('demo-project-'),
  ).length;
  const demoProgress = Math.min(100, Math.round((completedCount / 30) * 100));
  const courses: LearningCourse[] =
    serverSession === true
      ? learningDashboard?.courses || []
      : LOCAL_DEMO_ENABLED
      ? [
          {
            id: DEMO_COURSE_ID,
            title: 'من الصفر إلى أول عميل في العمل الحر',
            progress: demoProgress,
            completedSections: completedCount,
            totalSections: 30,
            category: 'freelance',
            lastLessonId:
              completedCount > 0
                ? demoLearningSteps[completedCount - 1]?.lessonId
                : undefined,
            lastLessonTitle:
              completedCount > 0
                ? demoLearningSteps[completedCount - 1]?.title
                : undefined,
            nextLessonId: demoLearningSteps[completedCount]?.lessonId,
            nextLessonTitle: demoLearningSteps[completedCount]?.title,
          },
        ]
      : [];
  const orderedCourses = [...courses].sort((first, second) => {
    const completionOrder =
      Number(first.progress >= 100) - Number(second.progress >= 100);
    if (completionOrder !== 0) return completionOrder;
    const firstSeen = Date.parse(first.lastWatchedAt || '') || 0;
    const secondSeen = Date.parse(second.lastWatchedAt || '') || 0;
    if (firstSeen !== secondSeen) return secondSeen - firstSeen;
    return second.progress - first.progress;
  });
  const hasActiveCourses = orderedCourses.some(course => course.progress < 100);
  const primaryResumeId = orderedCourses.find(
    course => course.progress > 0 && course.progress < 100,
  )?.id;
  const professionalCourses = courses.filter(
    course =>
      course.category === 'freelance' &&
      (serverSession === true || demoHasAccess),
  );
  const professionalProgress = professionalCourses.length
    ? Math.max(...professionalCourses.map(course => course.progress))
    : 0;
  const primaryPath = learningDashboard?.paths?.[0];
  const pathProgress = primaryPath?.progress ?? professionalProgress;
  const nextPathLevel = primaryPath?.nextLevel;
  const professionalCourseIds = new Set(
    professionalCourses.map(course => String(course.id)),
  );
  const earnedBadges: LearningBadge[] =
    serverSession === true
      ? (learningDashboard?.badges || []).filter(
          badge =>
            ['professional', 'freelance'].includes(
              String(badge.track || '').toLowerCase(),
            ) ||
            (badge.courseId
              ? professionalCourseIds.has(String(badge.courseId))
              : false),
        )
      : LOCAL_DEMO_ENABLED && passedProjectCount >= 3
      ? [
          {
            id: 'demo-junior-badge',
            title: 'Junior',
            courseId: DEMO_COURSE_ID,
            courseTitle: 'من الصفر إلى أول عميل في العمل الحر',
            track: 'freelance',
          },
        ]
      : [];
  const earnedProfessionalBadge = earnedBadges.length > 0;
  const displayedBadges: LearningBadge[] = earnedProfessionalBadge
    ? earnedBadges
    : [
        {
          id: 'next-junior-badge',
          title: 'Junior',
          courseTitle: 'أول شارة في مسارك المهني',
        },
      ];
  const week = lastSevenDays(learning.activityDays);
  const currentStreak =
    serverSession === true &&
    Number.isFinite(learningDashboard?.currentStreakDays)
      ? Math.max(0, Number(learningDashboard?.currentStreakDays))
      : currentStreakFromDays(learning.activityDays);
  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame>
          <HeaderWithBack hasArrow={false} title="ركني" />
          <SectionHeading
            eyebrow={
              !orderedCourses.length
                ? 'تعلمك في مكان واحد'
                : hasActiveCourses
                ? 'آخر ما كنت تتعلمه'
                : 'الكورسات المكتملة'
            }
            title={
              !orderedCourses.length
                ? 'استكمل من مكانك'
                : hasActiveCourses
                ? 'استكمل من مكانك'
                : 'أنهيتها'
            }
          />
          {serverSession === null ||
          (dashboardLoading && !learningDashboard) ? (
            <LearningDashboardSkeleton />
          ) : serverSession === false && !LOCAL_DEMO_ENABLED ? (
            <StatusView
              actionLabel="تسجيل الدخول"
              description="سجّل الدخول لحفظ تقدمك ومتابعته من أي جهاز"
              onAction={() =>
                navigation.navigate('Login', {
                  returnTo: {name: 'MyCorner'},
                })
              }
              state="empty"
              title="ستظهر كورساتك هنا"
            />
          ) : serverSession === true && !courses.length ? (
            <StatusView
              actionLabel="فتح الرئيسية"
              description={
                dashboardError ||
                'الكورسات التي تفتحها ستظهر هنا مع آخر نقطة وصلت إليها'
              }
              onAction={() => navigation.navigate('Home')}
              state={dashboardError ? 'error' : 'empty'}
              title={
                dashboardError
                  ? 'تعذّر تحديث كورساتك'
                  : 'ابدأ أول كورس من الرئيسية'
              }
            />
          ) : (
            <View style={styles.courseGrid}>
              {!!dashboardError && (
                <View accessibilityRole="alert" style={styles.offlineNote}>
                  <Text style={styles.offlineNoteText}>{dashboardError}</Text>
                </View>
              )}
              {orderedCourses.map((course, index) => {
                const hasAccess = serverSession === true || demoHasAccess;
                const hasProgress =
                  course.progress > 0 ||
                  course.completedSections > 0 ||
                  (serverSession !== true && passedProjectCount > 0);
                const isPrimaryResume = course.id === primaryResumeId;
                const statusLabel = !hasAccess
                  ? 'لم يبدأ بعد'
                  : course.progress >= 100
                  ? 'مكتمل'
                  : isPrimaryResume
                  ? 'أكمل الآن'
                  : hasProgress
                  ? 'قيد التعلّم'
                  : 'جاهز للبدء';

                const startsCompletedShelf =
                  hasActiveCourses &&
                  course.progress >= 100 &&
                  (index === 0 || orderedCourses[index - 1].progress < 100);

                return (
                  <React.Fragment key={course.id}>
                    {startsCompletedShelf && (
                      <SectionHeading
                        eyebrow="للرجوع والمراجعة"
                        style={styles.completedHeading}
                        title="أنهيتها"
                      />
                    )}
                    <Pressable
                      accessibilityLabel={formatArabicDisplayText(
                        `${statusLabel}: ${course.title}${
                          hasAccess && course.progress > 0
                            ? `، اكتمل ${Math.round(course.progress)}٪`
                            : ''
                        }`,
                      )}
                      accessibilityRole="button"
                      onPress={() =>
                        course.nextSectionType &&
                        course.nextSectionType !== 'lesson'
                          ? navigation.navigate('CourseDetails', {
                              courseId: course.id,
                            })
                          : navigation.navigate(
                              hasAccess ? 'Reels' : 'CourseDetails',
                              {
                                courseId: course.id,
                                ...(hasAccess &&
                                (course.nextLessonId || course.lastLessonId)
                                  ? {
                                      lessonId:
                                        course.nextLessonId ||
                                        course.lastLessonId,
                                    }
                                  : {}),
                              },
                            )
                      }
                      style={({pressed}) => [
                        styles.courseCard,
                        isPrimaryResume && styles.primaryResumeCard,
                        pressed && styles.pressed,
                      ]}>
                      <CourseArtwork
                        fallback={
                          serverSession === true
                            ? require('../assets/images/courseSliderBackground.jpg')
                            : require('../assets/images/demo-course/ui-freelance-cover.jpg')
                        }
                        source={
                          course.imageUrl ? {uri: course.imageUrl} : undefined
                        }
                        style={styles.courseCover}
                      />
                      <LinearGradient
                        colors={[
                          'rgba(5,8,13,.08)',
                          'rgba(5,8,13,.45)',
                          'rgba(5,8,13,.96)',
                        ]}
                        locations={[0, 0.52, 1]}
                        pointerEvents="none"
                        style={StyleSheet.absoluteFill}
                      />
                      <View style={styles.courseCopy}>
                        <MetaPill label={statusLabel} tone="primary" />
                        <Text
                          numberOfLines={largeText ? 4 : 2}
                          style={styles.courseTitle}>
                          {formatArabicDisplayText(course.title)}
                        </Text>
                        <Text style={styles.nextLesson}>
                          {hasAccess
                            ? course.progress >= 100
                              ? 'راجع أي مقطع وقتما تريد'
                              : hasProgress
                              ? course.nextLessonTitle
                                ? `التالي\n${course.nextLessonTitle}`
                                : course.lastLessonTitle
                                ? `أكمل بعد ${course.lastLessonTitle}`
                                : 'أكمل من مكانك'
                              : 'ابدأ بالمقطع الأول'
                            : 'افتح صفحة الكورس وراجع تفاصيله'}
                        </Text>
                        {hasAccess && (
                          <>
                            <View style={styles.progressTrack}>
                              <View
                                style={[
                                  styles.progressFill,
                                  {width: `${course.progress}%`},
                                ]}
                              />
                            </View>
                            <Text style={styles.progressLabel}>
                              {course.completedSections
                                ? formatArabicDisplayText(
                                    `اكتمل ${Math.round(course.progress)}٪`,
                                  )
                                : 'جاهز للبدء'}
                            </Text>
                          </>
                        )}
                      </View>
                    </Pressable>
                  </React.Fragment>
                );
              })}
            </View>
          )}

          {serverSession === true &&
          (watchHistoryLoading ||
            Boolean(watchHistoryError) ||
            Boolean(watchHistory?.items.length)) ? (
            <>
              <SectionHeading
                eyebrow="ارجع إلى مقطع محدد"
                style={styles.section}
                title="آخر ما شاهدته"
              />
              {watchHistoryLoading && !watchHistory?.items.length ? (
                <View style={styles.historyList}>
                  {[0, 1].map(item => (
                    <View key={item} style={styles.historySkeletonRow}>
                      <SkeletonBlock
                        height={64}
                        radius={Radius.md}
                        width={96}
                      />
                      <View style={styles.historySkeletonCopy}>
                        <SkeletonBlock height={16} width="86%" />
                        <SkeletonBlock height={12} width="58%" />
                      </View>
                    </View>
                  ))}
                </View>
              ) : watchHistoryError && !watchHistory?.items.length ? (
                <View accessibilityRole="alert" style={styles.offlineNote}>
                  <Text style={styles.offlineNoteText}>
                    {watchHistoryError}
                  </Text>
                </View>
              ) : (
                <>
                  {!!watchHistoryError && (
                    <View accessibilityRole="alert" style={styles.offlineNote}>
                      <Text style={styles.offlineNoteText}>
                        {watchHistoryError}
                      </Text>
                    </View>
                  )}
                  <View style={styles.historyList}>
                    {(watchHistory?.items || []).map(item => (
                    <Pressable
                      accessibilityLabel={`استكمال ${item.lessonTitle}`}
                      accessibilityRole="button"
                      key={item.id}
                      onPress={() =>
                        navigation.navigate('Reels', {
                          courseId: item.courseId,
                          lessonId: item.lessonId,
                          initialPositionSeconds: item.positionSeconds,
                        })
                      }
                      style={({pressed}) => [
                        styles.historyRow,
                        pressed && styles.pressed,
                      ]}>
                      <CourseArtwork
                        fallback={require('../assets/images/courseSliderBackground.jpg')}
                        source={
                          item.lessonThumbnail || item.courseImage
                            ? {uri: item.lessonThumbnail || item.courseImage}
                            : undefined
                        }
                        style={styles.historyThumb}
                      />
                      <View style={styles.historyCopy}>
                        <Text
                          numberOfLines={largeText ? 4 : 2}
                          style={styles.historyTitle}>
                          {formatArabicDisplayText(item.lessonTitle)}
                        </Text>
                        <Text
                          numberOfLines={largeText ? 2 : 1}
                          style={styles.historyCourse}>
                          {formatArabicDisplayText(item.courseTitle)}
                        </Text>
                        <View style={styles.historyProgressTrack}>
                          <View
                            style={[
                              styles.historyProgressFill,
                              {width: `${item.progress}%`},
                            ]}
                          />
                        </View>
                      </View>
                      <Text style={styles.historyAction}>أكمل</Text>
                    </Pressable>
                    ))}
                  </View>
                </>
              )}
            </>
          ) : null}

          {(professionalCourses.length > 0 || Boolean(primaryPath)) && (
            <>
              <SectionHeading style={styles.section} title="شاراتك المهنية" />
              <View style={styles.badgeGrid}>
                {displayedBadges.map(badge => (
                  <PremiumCard
                    key={badge.id}
                    style={[
                      styles.badgeCard,
                      largeText && styles.badgeCardLargeText,
                      !earnedProfessionalBadge && styles.badgeCardLocked,
                    ]}>
                    <CourseArtwork
                      fallback={localBadgeImage(badge.title)}
                      source={
                        badge.imageUrl ? {uri: badge.imageUrl} : undefined
                      }
                      style={styles.badgeArtwork}
                    />
                    <Text style={styles.badgeTitle}>
                      {formatArabicDisplayText(badge.title)}
                    </Text>
                    {!!badge.courseTitle && (
                      <Text numberOfLines={2} style={styles.badgeCourse}>
                        {formatArabicDisplayText(badge.courseTitle)}
                      </Text>
                    )}
                    {!earnedProfessionalBadge && (
                      <Text style={styles.badgeLockedText}>اقتربت من الوصول</Text>
                    )}
                  </PremiumCard>
                ))}
              </View>
              {Boolean(nextPathLevel) && (
                <PremiumCard style={styles.pathCard}>
                  <View style={styles.pathProgressRow}>
                    <Text style={styles.pathTitle}>
                      {formatArabicDisplayText(
                        `تقدمك نحو ${nextPathLevel?.name || 'المستوى التالي'}`,
                      )}
                    </Text>
                    <Text style={styles.pathValue}>
                      {formatArabicDisplayText(
                        `${Math.round(pathProgress)}%`,
                      )}
                    </Text>
                  </View>
                  <View style={styles.progressTrack}>
                    <View
                      style={[
                        styles.progressFill,
                        {
                          width: `${pathProgress}%`,
                        },
                      ]}
                    />
                  </View>
                  <Text style={styles.pathHint}>
                    {formatArabicDisplayText(
                      `متبقي ${Math.round(
                        primaryPath?.remainingToNextLevel || 0,
                      )}% للوصول للهدف التالي`,
                    )}
                  </Text>
                </PremiumCard>
              )}
            </>
          )}

          <SectionHeading style={styles.section} title="إيقاع هذا الأسبوع" />
          <PremiumCard style={styles.rhythmCard}>
            <View style={styles.streakTop}>
              <View style={styles.streakIcon}>
                <StreakFlame size={38} />
              </View>
              <View style={styles.streakCopy}>
                <Text style={styles.streakTitle}>
                  {currentStreak > 0
                    ? `${formatArabicNumber(currentStreak)} ${
                        currentStreak === 1 ? 'يوم' : 'أيام'
                      } متتالية`
                    : 'ابدأ سلسلتك اليوم'}
                </Text>
                <Text style={styles.streakHint}>
                  إكمال مقطع يحسب يوم تعلم
                </Text>
              </View>
            </View>
            <View style={styles.weekRow}>
              {week.map(item => (
                <View key={item.key} style={styles.day}>
                  <View
                    style={[
                      styles.dayMark,
                      item.complete && styles.dayComplete,
                    ]}>
                    <Text
                      style={[
                        styles.dayMarkText,
                        item.complete && styles.dayMarkTextComplete,
                      ]}>
                      {item.complete ? '✓' : ''}
                    </Text>
                  </View>
                  <Text style={styles.dayLabel}>{item.day}</Text>
                </View>
              ))}
            </View>
            <Text style={styles.rhythmText}>
              {formatArabicDisplayText(
                learning.activityDays.length
                  ? `تعلمت في ${
                      week.filter(item => item.complete).length
                    } أيام من آخر ٧ أيام\nمقطع واحد اليوم يحافظ على إيقاعك`
                  : 'ابدأ أول مقطع اليوم\nواستمر بإيقاع يناسب يومك',
              )}
            </Text>
          </PremiumCard>
        </ResponsiveFrame>
      </Content>
      <TabBar />
    </Container>
  );
}

const styles = StyleSheet.create({
  courseGrid: {gap: Spacing.md},
  completedHeading: {marginTop: Spacing.lg},
  courseCard: {
    marginTop: Spacing.md,
    width: '100%',
    minHeight: 244,
    backgroundColor: Palette.surface,
    borderRadius: Radius.lg,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    overflow: 'hidden',
  },
  primaryResumeCard: {
    minHeight: 284,
    borderColor: 'rgba(75,142,247,0.58)',
    borderWidth: 1.5,
  },
  offlineNote: {
    minHeight: 44,
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
  },
  offlineNoteText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
  },
  courseCover: {
    ...StyleSheet.absoluteFillObject,
    width: '100%',
    height: '100%',
    resizeMode: 'cover',
  },
  courseCopy: {flex: 1, padding: Spacing.lg, justifyContent: 'flex-end'},
  courseTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    marginTop: Spacing.sm,
    maxWidth: 520,
  },
  nextLesson: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  progressTrack: {
    height: 6,
    backgroundColor: Palette.surfacePressed,
    borderRadius: 3,
    overflow: 'hidden',
    marginTop: Spacing.md,
  },
  progressFill: {
    height: '100%',
    backgroundColor: Palette.primary,
    borderRadius: 3,
  },
  progressLabel: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    marginTop: Spacing.xs,
  },
  section: {marginTop: Spacing.xl},
  historyList: {
    marginTop: Spacing.sm,
    borderRadius: Radius.lg,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
    overflow: 'hidden',
  },
  historyRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    minHeight: 92,
    padding: Spacing.md,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.lineSoft,
  },
  historyThumb: {
    width: 96,
    height: 64,
    borderRadius: Radius.md,
    backgroundColor: Palette.surfaceRaised,
  },
  historyCopy: {flex: 1, minWidth: 0, marginHorizontal: Spacing.md},
  historyTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  historyCourse: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 2,
  },
  historyProgressTrack: {
    height: 4,
    marginTop: Spacing.sm,
    borderRadius: 2,
    overflow: 'hidden',
    backgroundColor: Palette.surfacePressed,
  },
  historyProgressFill: {
    height: '100%',
    borderRadius: 2,
    backgroundColor: Palette.primary,
  },
  historyAction: {...Type.caption, color: '#9ABFFF'},
  historySkeletonRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    padding: Spacing.md,
    gap: Spacing.md,
  },
  historySkeletonCopy: {flex: 1, gap: Spacing.sm},
  badgeGrid: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    gap: Spacing.md,
    marginTop: Spacing.sm,
  },
  badgeCard: {
    width: '47.8%',
    minHeight: 196,
    padding: Spacing.md,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeCardLargeText: {width: '100%'},
  badgeCardLocked: {opacity: 0.62},
  badgeArtwork: {width: 108, height: 108, resizeMode: 'contain'},
  badgeTitle: {...Type.section, color: Palette.text, marginTop: Spacing.xs},
  badgeCourse: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: 2,
  },
  badgeLockedText: {
    ...Type.caption,
    color: Palette.textFaint,
    marginTop: Spacing.xs,
  },
  pathCard: {padding: Spacing.lg, marginTop: Spacing.md},
  pathProgressRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  pathTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  pathValue: {...Type.bodyStrong, color: '#E9C66F'},
  pathHint: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.sm,
  },
  rhythmCard: {
    padding: Spacing.lg,
    marginTop: Spacing.sm,
    marginBottom: Spacing.xl,
  },
  streakTop: {...rtlRowStyle, alignItems: 'center', marginBottom: Spacing.lg},
  streakIcon: {
    width: 58,
    height: 58,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 20,
    backgroundColor: Palette.primarySoft,
  },
  streakCopy: {flex: 1, marginStart: Spacing.md},
  streakTitle: {...Type.section, ...textDirection, color: Palette.text},
  streakHint: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 2,
  },
  weekRow: {...rtlRowStyle, justifyContent: 'space-between'},
  day: {alignItems: 'center'},
  dayMark: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: Palette.surfacePressed,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dayComplete: {backgroundColor: Palette.action},
  dayMarkText: {...Type.caption, color: Palette.textFaint},
  dayMarkTextComplete: {color: Palette.text},
  dayLabel: {...Type.caption, color: Palette.textMuted, marginTop: Spacing.xs},
  rhythmText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.lg,
  },
  pressed: {opacity: 0.76},
});
