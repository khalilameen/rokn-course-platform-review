import {useRoute} from '@react-navigation/native';
import type {RootRoute} from '../../navigation/types';
import React, {useCallback, useEffect, useState} from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {
  applyLocalLearningState,
  loadCourseLearningData,
  unlockAfterProject,
} from '../../components/VideoPlayer/courseLearningApi';
import {CourseLearningData} from '../../components/VideoPlayer/types';
import {
  includesCourseCertificate,
  isGrantCourseAccess,
} from '../../components/VideoPlayer/courseEntitlements';
import Module from '../../components/view/Module';
import FullTrackUpgradeSheet from '../../components/FullTrackUpgradeSheet';
import {rtlRowStyle, textDirection} from '../../constants/designSystem';
import {Fonts} from '../../constants/styleConstants';
import {issueCertificate} from '../../services/roknApi';

export default function Lessons() {
  const route = useRoute<RootRoute<'CourseDetails'>>();
  const courseId = String(route.params?.courseId || '');
  const [course, setCourse] = useState<CourseLearningData | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [fullTrackVisible, setFullTrackVisible] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError('');
    try {
      const result = await loadCourseLearningData(courseId || undefined);
      const withLocalState = await applyLocalLearningState(result.course);
      setCourse(withLocalState);
    } catch {
      setCourse(null);
      setLoadError('تعذر تحميل خريطة الكورس الآن. مكانك محفوظ.');
    } finally {
      setLoading(false);
    }
  }, [courseId]);

  useEffect(() => {
    void load();
  }, [load]);

  const refreshAfterProjectPass = useCallback(
    async (projectId: string) => {
      // Give immediate feedback only after an authoritative pass, then fetch
      // again so URLs that were withheld while locked arrive from the API.
      setCourse(current =>
        current ? unlockAfterProject(current, projectId) : current,
      );
      try {
        const result = await loadCourseLearningData(courseId || undefined, {
          reconcilePending: false,
        });
        const refreshed = await applyLocalLearningState(result.course);
        setCourse(refreshed);
      } catch {
        // Keep the confirmed local state visible. Opening the course again
        // retries the authoritative payload without blocking the learner.
      }
    },
    [courseId],
  );

  if (loading) {
    return (
      <View style={styles.loading}>
        <ActivityIndicator color="#76A9FF" />
        <Text style={styles.loadingText}>نرتب خريطة الكورس…</Text>
      </View>
    );
  }

  if (!course) {
    return (
      <View style={styles.loading}>
        <Text style={styles.errorTitle}>تعذر فتح خريطة الكورس</Text>
        <Text style={styles.loadingText}>{loadError}</Text>
        <Pressable
          accessibilityRole="button"
          style={styles.retryButton}
          onPress={() => void load()}>
          <Text style={styles.retryText}>إعادة المحاولة</Text>
        </Pressable>
      </View>
    );
  }

  const grantAccess = isGrantCourseAccess(course.accessType);
  const certificateIncluded = includesCourseCertificate(course);
  const courseCompleted = course.modules.every(
    module =>
      module.reels.every(reel => reel.isCompleted) &&
      (!module.project || module.project.status === 'passed'),
  );
  const certificateReady = courseCompleted && certificateIncluded;
  const firstPendingModuleIndex = course.modules.findIndex(
    module =>
      !module.isLocked &&
      (module.reels.some(reel => !reel.isCompleted) ||
        (module.project && module.project.status !== 'passed')),
  );
  const lastUnlockedModuleIndex = course.modules.reduce(
    (lastIndex, module, index) => (module.isLocked ? lastIndex : index),
    0,
  );
  const expandedModuleIndex =
    firstPendingModuleIndex >= 0
      ? firstPendingModuleIndex
      : lastUnlockedModuleIndex;

  return (
    <View style={styles.container}>
      <View style={styles.intro}>
        <Text style={styles.eyebrow}>خريطة الكورس</Text>
        <Text style={styles.heading}>كل مقاطع الكورس أمامك</Text>
        <Text style={styles.introCopy}>
          افتح أي مقطع متاح
          {'\n'}يظهر المشروع بعد آخر مقطع في الوحدة
        </Text>
      </View>

      {course.modules.map((module, index) => (
        <Module
          key={module.id}
          courseId={course.id}
          module={module}
          initiallyExpanded={index === expandedModuleIndex}
          onProjectPassed={projectId => void refreshAfterProjectPass(projectId)}
        />
      ))}

      <Pressable
        accessibilityRole={grantAccess ? 'button' : undefined}
        accessibilityLabel={
          grantAccess ? 'عرض خيارات Rokn AI والشهادة' : undefined
        }
        disabled={!grantAccess}
        onPress={() => setFullTrackVisible(true)}
        style={[
          styles.certificateCard,
          certificateReady && styles.certificateCardReady,
          grantAccess && styles.certificateCardGrant,
        ]}>
        <View style={styles.certificateIcon}>
          <Text style={styles.certificateSymbol}>▣</Text>
        </View>
        <View style={styles.certificateCopy}>
          <Text style={styles.certificateTitle}>
            {grantAccess
              ? 'الشهادة ضمن أحد الاختيارات المدفوعة'
              : certificateReady
              ? 'شهادتك جاهزة'
              : 'شهادة إتمام الكورس'}
          </Text>
          <Text style={styles.certificateDescription}>
            {grantAccess
              ? 'منحتك تفتح الكورس ومشروعاته كاملة\nأضف Rokn AI والشهادة عند الحاجة'
              : certificateReady
              ? 'ستظهر في بورتفوليوك ويصل رمز QR إلى صفحة المشاركة.'
              : 'تفتح بعد اعتماد مشروع التخرج.'}
          </Text>
        </View>
        <View style={styles.certificateState}>
          <Text style={styles.certificateStateText}>
            {grantAccess ? 'اختياري' : certificateReady ? 'جاهزة' : 'مقفلة'}
          </Text>
        </View>
      </Pressable>
      <FullTrackUpgradeSheet
        completed={courseCompleted}
        courseId={course.id}
        courseTitle={course.title}
        onClose={() => setFullTrackVisible(false)}
        onUpgraded={async () => {
          await load();
          if (courseCompleted) {
            await issueCertificate(course.id).catch(() => null);
          }
        }}
        visible={fullTrackVisible}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    width: '100%',
    paddingHorizontal: 16,
    paddingBottom: 110,
  },
  loading: {
    minHeight: 260,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
  },
  loadingText: {
    color: 'rgba(255,255,255,.55)',
    fontFamily: Fonts.regular,
    fontSize: 12,
  },
  errorTitle: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 17,
    textAlign: 'center',
  },
  retryButton: {
    minWidth: 170,
    minHeight: 46,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
    marginTop: 10,
  },
  retryText: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 13,
  },
  intro: {
    width: '100%',
    maxWidth: 760,
    alignSelf: 'center',
    marginBottom: 18,
  },
  eyebrow: {
    ...textDirection,
    color: '#76A9FF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  heading: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 20,
    lineHeight: 31,
    marginTop: 4,
  },
  introCopy: {
    ...textDirection,
    color: 'rgba(255,255,255,.54)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    marginTop: 3,
  },
  note: {
    width: '100%',
    maxWidth: 760,
    minHeight: 42,
    alignSelf: 'center',
    borderRadius: 14,
    paddingHorizontal: 12,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 8,
    backgroundColor: 'rgba(75,142,247,.08)',
    borderWidth: 1,
    borderColor: 'rgba(118,169,255,.15)',
    marginBottom: 12,
  },
  noteDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    backgroundColor: '#76A9FF',
  },
  noteText: {
    ...textDirection,
    flex: 1,
    color: 'rgba(255,255,255,.7)',
    fontFamily: Fonts.regular,
    fontSize: 10,
  },
  certificateCard: {
    width: '100%',
    maxWidth: 760,
    minHeight: 86,
    alignSelf: 'center',
    borderRadius: 20,
    padding: 14,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 12,
    backgroundColor: '#0E141C',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.07)',
    opacity: 0.65,
  },
  certificateCardReady: {
    opacity: 1,
    borderColor: 'rgba(93,210,153,.2)',
  },
  certificateCardGrant: {
    opacity: 1,
    borderColor: 'rgba(118,169,255,.2)',
  },
  certificateIcon: {
    width: 46,
    height: 46,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.05)',
  },
  certificateSymbol: {
    color: '#FFFFFF',
    fontSize: 22,
  },
  certificateCopy: {
    flex: 1,
  },
  certificateTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 13,
  },
  certificateDescription: {
    ...textDirection,
    color: 'rgba(255,255,255,.43)',
    fontFamily: Fonts.regular,
    fontSize: 9,
    lineHeight: 15,
    marginTop: 2,
  },
  certificateState: {
    minHeight: 28,
    paddingHorizontal: 10,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  certificateStateText: {
    color: 'rgba(255,255,255,.63)',
    fontFamily: Fonts.medium,
    fontSize: 9,
  },
});
