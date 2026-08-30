import {useFocusEffect, useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import React, {useCallback, useRef, useState} from 'react';
import {
  Linking,
  Modal,
  Pressable,
  ScrollView,
  Share,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSelector} from 'react-redux';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import Button from '../../components/touchables/Button';
import FullTrackUpgradeSheet from '../../components/FullTrackUpgradeSheet';
import QRCode from '../../components/ui/QRCode';
import {
  MetaPill,
  SectionHeading,
  StatusView,
} from '../../components/ui/PremiumUI';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {Fonts} from '../../constants/styleConstants';
import {
  applyLocalLearningState,
  loadCourseLearningData,
} from '../../components/VideoPlayer/courseLearningApi';
import {DEMO_COURSE_ID} from '../../services/demoExperience';
import {
  getCertificates,
  getLearningCourses,
  hasSession,
  issueCertificate,
} from '../../services/roknApi';
import type {
  Certificate as CertificateDto,
  CourseProgress,
} from '../../services/roknApi';
import {extractUserProfile} from '../../constants/helpers';
import {LOCAL_DEMO_ENABLED} from '../../config/runtime';
import type {RootState} from '../../store/store';

declare const process: {
  env: {EXPO_PUBLIC_PORTFOLIO_URL?: string};
};

const demoCredential = 'RKN-FRL-24018';
const demoCourseTitle = 'من أول مهارة إلى أول عميل';

const CertificateArtwork = ({
  name,
  url,
  courseTitle,
  credential,
  compact = false,
}: {
  name: string;
  url: string;
  courseTitle: string;
  credential: string;
  compact?: boolean;
}) => (
  <View style={[styles.artwork, compact && styles.artworkCompact]}>
    <View style={styles.artworkAccent} />
    <View style={styles.artworkHeader}>
      <Text allowFontScaling={false} style={styles.wordmark}>
        Rokn
      </Text>
      <Text allowFontScaling={false} style={styles.credential}>
        #{credential}
      </Text>
    </View>
    <Text
      allowFontScaling={false}
      style={[styles.certificateKicker, compact && styles.compactKicker]}>
      شهادة إتمام موثقة
    </Text>
    <Text
      allowFontScaling={false}
      numberOfLines={compact ? 1 : 2}
      style={[styles.studentName, compact && styles.compactStudent]}>
      {name}
    </Text>
    <Text
      allowFontScaling={false}
      numberOfLines={compact ? 2 : 3}
      style={[styles.certificateCourse, compact && styles.compactCourse]}>
      أتم بنجاح كورس «{courseTitle}» ومشروعاته العملية
    </Text>
    <View style={styles.artworkFooter}>
      <View style={styles.signature}>
        <View style={styles.signatureLine} />
        <Text allowFontScaling={false} style={styles.signatureLabel}>
          ركن للتعلّم التطبيقي
        </Text>
      </View>
      <QRCode value={url} size={compact ? 58 : 104} />
    </View>
  </View>
);

export default function Certificates({
  displayName: resolvedDisplayName,
  username: resolvedUsername,
}: {
  displayName?: string;
  username?: string;
}) {
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const {contentWidth} = useResponsiveLayout();
  const user = extractUserProfile(
    useSelector((state: RootState) => state.auth.userData),
  );
  const username =
    resolvedUsername || user?.username || user?.portfolio_slug || 'student';
  const displayName = resolvedDisplayName || user?.name || 'طالب ركن';
  const publicBase =
    process.env.EXPO_PUBLIC_PORTFOLIO_URL || 'https://rokn.app';
  const portfolioUrl = `${publicBase.replace(/\/$/, '')}/@${encodeURIComponent(
    username,
  )}`;
  const certificateLink = `${portfolioUrl}?certificate=${demoCredential}`;
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [certificates, setCertificates] = useState<CertificateDto[]>([]);
  const [grantCourses, setGrantCourses] = useState<CourseProgress[]>([]);
  const [selectedGrantId, setSelectedGrantId] = useState<string | null>(null);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const loadGeneration = useRef(0);

  const loadCertificates = useCallback(async () => {
    const generation = ++loadGeneration.current;
    const isCurrent = () => loadGeneration.current === generation;
    setLoading(true);
    setLoadError('');
    setSelectedId(null);
    setCertificates([]);
    setGrantCourses([]);
    try {
      const sessionAvailable = await hasSession();
      if (isCurrent()) setServerSession(sessionAvailable);
      if (sessionAvailable) {
        const [remoteCertificates, learningCourses] = await Promise.all([
          getCertificates(),
          getLearningCourses(),
        ]);
        const certificateByCourse = new Map(
          remoteCertificates
            .filter(item => item.courseId)
            .map(item => [item.courseId as string, item]),
        );
        const completedGrantCourses = learningCourses.filter(
          course =>
            course.accessType === 'scholarship' &&
            !course.certificateAvailable &&
            (course.progress >= 100 ||
              (course.totalSections > 0 &&
                course.completedSections >= course.totalSections)),
        );
        const recoverableCourseIds = learningCourses
          .filter(
            course =>
              course.progress >= 100 ||
              (course.totalSections > 0 &&
                course.completedSections >= course.totalSections),
          )
          .filter(course => {
            const existing = certificateByCourse.get(course.id);
            return !existing || existing.status === 'pending';
          })
          .filter(course => course.certificateAvailable)
          // Opening this screen must stay bounded even for a long-time user.
          // Remaining certificates recover on the next focus.
          .slice(0, 5)
          .map(course => course.id);
        const issued = await Promise.allSettled(
          recoverableCourseIds.map(courseId => issueCertificate(courseId)),
        );
        const merged = new Map(remoteCertificates.map(item => [item.id, item]));
        issued.forEach(result => {
          if (result.status === 'fulfilled' && result.value) {
            merged.set(result.value.id, result.value);
          }
        });
        if (isCurrent()) {
          setCertificates(
            [...merged.values()].filter(item => item.status === 'active'),
          );
          setGrantCourses(completedGrantCourses);
        }
        return;
      }
      if (!LOCAL_DEMO_ENABLED) {
        return;
      }
      const result = await loadCourseLearningData(DEMO_COURSE_ID);
      const course = await applyLocalLearningState(result.course);
      const finalProject = [...course.modules]
        .reverse()
        .find(module => module.project)?.project;
      if (isCurrent()) {
        setCertificates(
          finalProject?.status === 'passed'
            ? [
                {
                  id: demoCredential,
                  publicId: demoCredential,
                  portfolioUrl: certificateLink,
                  courseName: demoCourseTitle,
                  status: 'active',
                },
              ]
            : [],
        );
      }
    } catch {
      if (isCurrent()) {
        setLoadError(
          'تعذّر التحقق من شهاداتك الآن. لم نفقد أي شهادة أو إنجاز.',
        );
      }
    } finally {
      if (isCurrent()) setLoading(false);
    }
  }, [certificateLink]);

  useFocusEffect(
    useCallback(() => {
      loadCertificates();
      return () => {
        loadGeneration.current += 1;
      };
    }, [loadCertificates]),
  );

  const selectedCertificate =
    certificates.find(certificate => certificate.id === selectedId) || null;
  const selectedGrantCourse =
    grantCourses.find(course => course.id === selectedGrantId) || null;
  const activeCourseTitle = selectedCertificate?.courseName || demoCourseTitle;
  const activeCredential = selectedCertificate?.publicId || demoCredential;
  const activeCertificateLink =
    selectedCertificate?.portfolioUrl ||
    `${portfolioUrl}?certificate=${encodeURIComponent(activeCredential)}`;
  const destinationFor = (certificate: CertificateDto) =>
    certificate.portfolioUrl ||
    `${portfolioUrl}?certificate=${encodeURIComponent(certificate.publicId)}`;

  return (
    <View style={styles.container}>
      <SectionHeading title="شهاداتي" />

      {loading ? (
        <StatusView state="loading" title="نتحقق من آخر إنجازاتك…" />
      ) : loadError ? (
        <StatusView
          actionLabel="إعادة المحاولة"
          description={loadError}
          onAction={loadCertificates}
          state="error"
          title="تعذّر تحميل الشهادات"
        />
      ) : !certificates.length && !grantCourses.length ? (
        <StatusView
          actionLabel={
            serverSession === false && !LOCAL_DEMO_ENABLED
              ? 'تسجيل الدخول'
              : 'استكشف الكورسات'
          }
          description={
            serverSession === false && !LOCAL_DEMO_ENABLED
              ? 'سجّل دخولك علشان تشوف شهاداتك الموثقة وتشاركها.'
              : 'تُضاف شهادتك هنا بعد اجتياز مشروع التخرج.'
          }
          onAction={() => {
            if (serverSession === false && !LOCAL_DEMO_ENABLED) {
              navigation.navigate('Login', {
                returnTo: {name: 'Profile'},
              });
              return;
            }
            navigation.navigate('Home');
          }}
          state="empty"
          title={
            serverSession === false && !LOCAL_DEMO_ENABLED
              ? 'شهاداتك مرتبطة بحسابك'
              : 'لا توجد شهادات بعد'
          }
        />
      ) : (
        <>
          <View style={styles.grid}>
            {certificates.map(certificate => (
              <Pressable
                accessibilityLabel={`عرض شهادة ${certificate.courseName}`}
                accessibilityRole="button"
                key={certificate.id}
                onPress={() => setSelectedId(certificate.id)}
                style={({pressed}) => [
                  styles.card,
                  contentWidth < 700 && styles.cardNarrow,
                  pressed && styles.pressed,
                ]}>
                <CertificateArtwork
                  compact
                  name={displayName}
                  url={destinationFor(certificate)}
                  courseTitle={certificate.courseName}
                  credential={certificate.publicId}
                />
                <View style={styles.cardCopy}>
                  <MetaPill label="شهادة موثقة" tone="success" />
                  <Text numberOfLines={2} style={styles.title}>
                    {certificate.courseName}
                  </Text>
                  <View style={styles.verifiedRow}>
                    <View style={styles.verifiedDot} />
                    <Text numberOfLines={1} style={styles.verified}>
                      رقم الاعتماد · {certificate.publicId}
                    </Text>
                  </View>
                </View>
              </Pressable>
            ))}
          </View>
          {!!grantCourses.length && (
            <View style={styles.lockedSection}>
              <Text style={styles.lockedHeading}>شهادات تنتظر التفعيل</Text>
              <Text style={styles.lockedIntro}>
                أنهيت الكورس بمنحتك كاملة. لو محتاج الشهادة وRokn AI تقدر تختار
                مستوى الدعم المناسب من هنا.
              </Text>
              {grantCourses.map(course => (
                <Pressable
                  accessibilityLabel={`تفعيل شهادة ${course.title}`}
                  accessibilityRole="button"
                  key={`grant-${course.id}`}
                  onPress={() => setSelectedGrantId(course.id)}
                  style={({pressed}) => [
                    styles.lockedCard,
                    pressed && styles.pressed,
                  ]}>
                  <View style={styles.lockedIcon}>
                    <Text style={styles.lockedIconText}>◇</Text>
                  </View>
                  <View style={styles.lockedCopy}>
                    <Text numberOfLines={2} style={styles.lockedTitle}>
                      {course.title}
                    </Text>
                    <Text style={styles.lockedMeta}>
                      أنهيت الكورس · الشهادة اختيارية
                    </Text>
                  </View>
                  <Text style={styles.lockedAction}>عرض التفاصيل</Text>
                </Pressable>
              ))}
            </View>
          )}
        </>
      )}

      <FullTrackUpgradeSheet
        completed
        courseId={selectedGrantCourse?.id || ''}
        courseTitle={selectedGrantCourse?.title || ''}
        onClose={() => setSelectedGrantId(null)}
        onUpgraded={loadCertificates}
        visible={Boolean(selectedGrantCourse)}
      />

      <Modal
        animationType="slide"
        onRequestClose={() => setSelectedId(null)}
        transparent
        visible={Boolean(selectedCertificate)}>
        <View style={styles.overlay}>
          <View style={styles.sheet}>
            <ScrollView
              contentContainerStyle={[
                styles.sheetContent,
                {
                  paddingBottom: Math.max(
                    Spacing.xl,
                    insets.bottom + Spacing.md,
                  ),
                },
              ]}
              showsVerticalScrollIndicator={false}>
              <CertificateArtwork
                name={displayName}
                url={activeCertificateLink}
                courseTitle={activeCourseTitle}
                credential={activeCredential}
              />
              <View style={styles.detailCopy}>
                <View style={styles.verifiedRow}>
                  <View style={styles.verifiedDot} />
                  <Text style={styles.verified}>شهادة موثقة من ركن</Text>
                </View>
                <Text style={styles.detailTitle}>{activeCourseTitle}</Text>
                <Text style={styles.detailMeta}>
                  رقم الاعتماد: {activeCredential}
                </Text>
                <MetaPill
                  label="قابلة للتحقق والمشاركة"
                  tone="success"
                  style={styles.badge}
                />
                <View style={styles.qrDestination}>
                  <QRCode value={activeCertificateLink} size={148} />
                  <View style={styles.qrCopy}>
                    <Text style={styles.qrTitle}>امسح للتحقق</Text>
                    <Text numberOfLines={2} style={styles.qrLink}>
                      {activeCertificateLink}
                    </Text>
                    <Text style={styles.qrHint}>
                      يفتح رابط البورتفوليو غير المُدرج ويقف مباشرة على هذه الشهادة.
                    </Text>
                  </View>
                </View>
                <Button
                  onPress={() => Linking.openURL(activeCertificateLink)}
                  title="فتح رابط البورتفوليو"
                />
                <Button
                  onPress={() =>
                    Share.share({
                      message: `شهادتي الموثقة على ركن\n${activeCertificateLink}`,
                      url: activeCertificateLink,
                    })
                  }
                  title="مشاركة رابط الشهادة"
                  useGradient={false}
                />
                <Button
                  onPress={() => setSelectedId(null)}
                  title="إغلاق"
                  useGradient={false}
                />
              </View>
            </ScrollView>
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {paddingBottom: Spacing.xl},
  grid: {
    direction: 'rtl',
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.md,
  },
  card: {
    flexBasis: 320,
    flexGrow: 1,
    maxWidth: 620,
    marginTop: Spacing.md,
    alignSelf: 'flex-start',
    borderRadius: Radius.lg,
    overflow: 'hidden',
    backgroundColor: Palette.surface,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
  },
  cardNarrow: {flexBasis: '100%', maxWidth: '100%'},
  lockedSection: {marginTop: Spacing.xl},
  lockedHeading: {...Type.section, ...textDirection, color: Palette.text},
  lockedIntro: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
    marginBottom: Spacing.sm,
  },
  lockedCard: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: Spacing.sm,
    marginTop: Spacing.sm,
    padding: Spacing.md,
    borderRadius: Radius.lg,
    borderWidth: 1,
    borderColor: 'rgba(216,166,60,.22)',
    backgroundColor: Palette.coinSoft,
  },
  lockedIcon: {
    width: 44,
    height: 44,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(216,166,60,.14)',
  },
  lockedIconText: {fontSize: 24, color: '#E5BD67'},
  lockedCopy: {flex: 1},
  lockedTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  lockedMeta: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 2,
  },
  lockedAction: {...Type.caption, color: '#E5BD67'},
  cardCopy: {padding: Spacing.md},
  title: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    marginTop: Spacing.sm,
  },
  verifiedRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    marginTop: Spacing.xs,
  },
  verifiedDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: Palette.success,
    marginEnd: Spacing.xs,
  },
  verified: {...Type.caption, color: Palette.success},
  artwork: {
    width: '100%',
    aspectRatio: 1.414,
    minHeight: 280,
    padding: 26,
    backgroundColor: '#F7F4ED',
    overflow: 'hidden',
  },
  artworkCompact: {minHeight: 180, padding: 17},
  artworkAccent: {
    position: 'absolute',
    start: 0,
    top: 0,
    bottom: 0,
    width: 8,
    backgroundColor: '#1E54D9',
  },
  artworkHeader: {
    flexDirection: 'row-reverse',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  wordmark: {fontFamily: Fonts.bold, fontSize: 22, color: '#07101D'},
  credential: {fontFamily: Fonts.medium, fontSize: 9, color: '#697385'},
  certificateKicker: {
    fontFamily: Fonts.semiBold,
    fontSize: 13,
    color: '#1E54D9',
    ...textDirection,
    marginTop: 26,
  },
  compactKicker: {fontSize: 9, marginTop: 12},
  studentName: {
    fontFamily: Fonts.bold,
    fontSize: 28,
    lineHeight: 39,
    color: '#07101D',
    ...textDirection,
    marginTop: 4,
  },
  compactStudent: {fontSize: 18, lineHeight: 25},
  certificateCourse: {
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    color: '#4C5768',
    ...textDirection,
    maxWidth: '80%',
  },
  compactCourse: {fontSize: 8, lineHeight: 13, maxWidth: '74%'},
  artworkFooter: {
    flex: 1,
    minHeight: 64,
    flexDirection: 'row-reverse',
    alignItems: 'flex-end',
    justifyContent: 'space-between',
    marginTop: 8,
  },
  signature: {minWidth: 132, paddingBottom: 6},
  signatureLine: {height: 1, backgroundColor: '#B9B4AA'},
  signatureLabel: {
    fontFamily: Fonts.regular,
    fontSize: 8,
    color: '#697385',
    textAlign: 'center',
    marginTop: 5,
  },
  overlay: {
    flex: 1,
    backgroundColor: Palette.overlay,
    justifyContent: 'flex-end',
    alignItems: 'center',
  },
  sheet: {
    width: '100%',
    maxWidth: 760,
    maxHeight: '94%',
    backgroundColor: Palette.canvasSoft,
    borderTopLeftRadius: Radius.xl,
    borderTopRightRadius: Radius.xl,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    overflow: 'hidden',
  },
  sheetContent: {paddingBottom: Spacing.xl},
  detailCopy: {padding: Spacing.xl},
  detailTitle: {
    ...Type.title,
    ...textDirection,
    color: Palette.text,
    marginTop: Spacing.sm,
  },
  detailMeta: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  badge: {marginTop: Spacing.md},
  qrDestination: {
    marginTop: Spacing.lg,
    padding: Spacing.md,
    borderRadius: Radius.md,
    backgroundColor: Palette.surface,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: Spacing.md,
  },
  qrCopy: {flex: 1},
  qrTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  qrLink: {
    ...Type.caption,
    ...textDirection,
    color: '#8BB5FF',
    marginTop: Spacing.xs,
  },
  qrHint: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  pressed: {opacity: 0.8, transform: [{scale: 0.99}]},
});
