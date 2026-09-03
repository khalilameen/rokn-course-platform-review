import {useFocusEffect, useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import React, {useCallback, useEffect, useRef, useState} from 'react';
import {
  Alert,
  Image,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import {useSelector} from 'react-redux';
import {openExternalUrlOnce, shareOnce} from '../../services/systemActions';
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
  getCachedCertificates,
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
import {certificateUrlFor} from '../../services/publicLinks';
import {isolateBidirectionalText} from '../../constants/arabicFormatting';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import {learnerErrorMessage} from '../../utils/errorPayload';
import {openCourseAttachment} from '../../components/VideoPlayer/attachmentActions';
import {useAppActiveState} from '../../hooks/useAppActiveState';

const demoCredential = 'RKN-FRL-24018';
const demoCourseTitle = 'من أول مهارة إلى أول عميل';

const CertificateArtwork = ({
  name,
  url,
  courseTitle,
  credential,
  reviewedProject = false,
  compact = false,
  officialAspect = false,
}: {
  name: string;
  url: string;
  courseTitle: string;
  credential: string;
  reviewedProject?: boolean;
  compact?: boolean;
  officialAspect?: boolean;
}) => (
  <View
    style={[
      styles.artwork,
      officialAspect && styles.artworkOfficial,
      compact && styles.artworkCompact,
    ]}>
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
      {reviewedProject
        ? `أتم كورس «${courseTitle}» واجتاز مشروعه`
        : `أتم بنجاح كورس «${courseTitle}»`}
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

/**
 * The backend artifact is the certificate the learner will actually save.
 * Keep the local artwork underneath as an immediate, offline-safe placeholder,
 * then replace it with the authoritative artifact once it loads.
 */
const CertificateArtifactPreview = ({
  certificateUrl,
  name,
  verificationUrl,
  courseTitle,
  credential,
  reviewedProject,
}: {
  certificateUrl?: string;
  name: string;
  verificationUrl: string;
  courseTitle: string;
  credential: string;
  reviewedProject: boolean;
}) => {
  const [artifactFailed, setArtifactFailed] = useState(false);

  React.useEffect(() => setArtifactFailed(false), [certificateUrl]);

  return (
    <View style={styles.artifactPreview}>
      <CertificateArtwork
        name={name}
        url={verificationUrl}
        courseTitle={courseTitle}
        credential={credential}
        reviewedProject={reviewedProject}
        officialAspect
      />
      {!!certificateUrl && !artifactFailed && (
        <Image
          accessibilityLabel={`شهادة ${courseTitle}`}
          accessibilityRole="image"
          onError={() => setArtifactFailed(true)}
          resizeMode="contain"
          source={{uri: certificateUrl}}
          style={styles.artifactImage}
        />
      )}
    </View>
  );
};

export default function Certificates({
  displayName: resolvedDisplayName,
  username: resolvedUsername,
}: {
  displayName?: string;
  username?: string;
}) {
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const appIsActive = useAppActiveState();
  const {contentWidth} = useResponsiveLayout();
  const user = extractUserProfile(
    useSelector((state: RootState) => state.auth.userData),
  );
  const username =
    resolvedUsername || user?.username || user?.portfolio_slug || 'student';
  const displayName = resolvedDisplayName || user?.name || 'طالب ركن';
  const certificateLink = certificateUrlFor(String(username), demoCredential);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [certificatePending, setCertificatePending] = useState(false);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [certificates, setCertificates] = useState<CertificateDto[]>([]);
  const [readyCourses, setReadyCourses] = useState<CourseProgress[]>([]);
  const [grantCourses, setGrantCourses] = useState<CourseProgress[]>([]);
  const [selectedGrantId, setSelectedGrantId] = useState<string | null>(null);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [issueCourseId, setIssueCourseId] = useState<string | null>(null);
  const [issueName, setIssueName] = useState('');
  const [issuing, setIssuing] = useState(false);
  const loadGeneration = useRef(0);
  const issueFlight = useRef(false);
  const pendingPollAttempts = useRef(0);

  const loadCertificates = useCallback(async () => {
    const generation = ++loadGeneration.current;
    const isCurrent = () => loadGeneration.current === generation;
    setLoading(true);
    setLoadError('');
    setCertificatePending(false);
    try {
      const sessionAvailable = await hasSession();
      if (isCurrent()) setServerSession(sessionAvailable);
      if (sessionAvailable) {
        const cachedCertificates = await getCachedCertificates().catch(
          () => [],
        );
        if (isCurrent() && cachedCertificates.length) {
          setCertificates(
            cachedCertificates.filter(item => item.status !== 'revoked'),
          );
          setCertificatePending(
            cachedCertificates.some(item => item.status === 'pending'),
          );
        }
        const [certificatesResult, learningResult] = await Promise.allSettled([
          getCertificates(),
          getLearningCourses(),
        ]);
        if (
          certificatesResult.status === 'rejected' &&
          learningResult.status === 'rejected'
        ) {
          throw certificatesResult.reason;
        }
        if (
          certificatesResult.status === 'rejected' ||
          learningResult.status === 'rejected'
        ) {
          setLoadError('نعرض المتاح الآن وسنحدّث الباقي عند عودة الاتصال');
        }
        if (learningResult.status === 'fulfilled' && isCurrent()) {
          setGrantCourses(
            learningResult.value.filter(
              course =>
                course.accessType === 'scholarship' &&
                !course.certificateAvailable &&
                (course.progress >= 100 ||
                  (course.totalSections > 0 &&
                    course.completedSections >= course.totalSections)),
            ),
          );
        }
        if (certificatesResult.status === 'fulfilled') {
          const remoteCertificates = certificatesResult.value;
          const hasPendingCertificate = remoteCertificates.some(
            item => item.status === 'pending',
          );
          if (learningResult.status === 'fulfilled') {
            const certificateByCourse = new Map(
              remoteCertificates
                .filter(item => item.courseId)
                .map(item => [item.courseId as string, item]),
            );
            if (isCurrent()) {
              // certificate_available is the server-side eligibility verdict;
              // progress alone does not include every quiz/project/evidence gate.
              setReadyCourses(
                learningResult.value
                  .filter(course => course.certificateAvailable)
                  .filter(course => !certificateByCourse.has(course.id)),
              );
            }
          }
          if (isCurrent()) {
            setCertificatePending(hasPendingCertificate);
            if (!hasPendingCertificate) pendingPollAttempts.current = 0;
            setCertificates(
              remoteCertificates.filter(item => item.status !== 'revoked'),
            );
          }
        } else if (isCurrent()) {
          setReadyCourses([]);
        }
        return;
      }
      if (!LOCAL_DEMO_ENABLED) {
        if (isCurrent()) {
          setCertificates([]);
          setReadyCourses([]);
          setGrantCourses([]);
          setCertificatePending(false);
        }
        return;
      }
      const result = await loadCourseLearningData(DEMO_COURSE_ID);
      const course = await applyLocalLearningState(result.course);
      const finalProject = course.modules
        .flatMap(module =>
          module.projects?.length
            ? module.projects
            : module.project
            ? [module.project]
            : [],
        )
        .at(-1);
      if (isCurrent()) {
        setGrantCourses([]);
        setReadyCourses([]);
        setCertificatePending(false);
        setCertificates(
          finalProject?.status === 'passed'
            ? [
                {
                  id: demoCredential,
                  publicId: demoCredential,
                  portfolioUrl: certificateLink,
                  holderName: displayName,
                  courseName: demoCourseTitle,
                  status: 'active',
                  verificationLevel: 'reviewed_project',
                  verificationLabel: 'إتمام الكورس ومراجعة المشروع',
                },
              ]
            : [],
        );
      }
    } catch {
      if (isCurrent()) {
        setLoadError('تعذّر التحقق من شهاداتك الآن\nشهاداتك محفوظة');
      }
    } finally {
      if (isCurrent()) setLoading(false);
    }
  }, [certificateLink, displayName]);

  useFocusEffect(
    useCallback(() => {
      if (!appIsActive) return () => undefined;
      loadCertificates();
      return () => {
        loadGeneration.current += 1;
      };
    }, [appIsActive, loadCertificates]),
  );

  useEffect(() => {
    if (!certificatePending || loading || pendingPollAttempts.current >= 5) {
      return undefined;
    }
    const delayMs = Math.min(
      20000,
      3000 * Math.pow(1.7, pendingPollAttempts.current),
    );
    const timer = setTimeout(() => {
      pendingPollAttempts.current += 1;
      void loadCertificates();
    }, delayMs);
    return () => clearTimeout(timer);
  }, [certificatePending, loadCertificates, loading]);

  const selectedCertificate =
    certificates.find(certificate => certificate.id === selectedId) || null;
  const selectedGrantCourse =
    grantCourses.find(course => course.id === selectedGrantId) || null;
  const issueCourse =
    readyCourses.find(course => course.id === issueCourseId) || null;
  const activeCourseTitle = selectedCertificate?.courseName || '';
  const activeHolderName = selectedCertificate?.holderName || '';
  const activeCredential = selectedCertificate?.publicId || '';
  const activeCertificateLink = selectedCertificate
    ? selectedCertificate.portfolioUrl ||
      certificateUrlFor(String(username), selectedCertificate.publicId)
    : '';
  const destinationFor = (certificate: CertificateDto) =>
    certificate.portfolioUrl ||
    certificateUrlFor(String(username), certificate.publicId);

  const openCertificate = async () => {
    if (!selectedCertificate || !activeCertificateLink) return;
    try {
      await openExternalUrlOnce(activeCertificateLink);
    } catch {
      Alert.alert('تعذّر فتح الشهادة', 'حاول مرة أخرى');
    }
  };

  const shareCertificate = async () => {
    if (!selectedCertificate || !activeCertificateLink) return;
    try {
      await shareOnce(`certificate:${activeCredential}`, {
        message: `شهادتي الموثقة على ركن\n${activeCertificateLink}`,
        url: activeCertificateLink,
      });
    } catch {
      Alert.alert('تعذّرت المشاركة', 'حاول مرة أخرى');
    }
  };

  const saveCertificate = () => {
    if (!selectedCertificate?.certificateUrl) return;
    void openCourseAttachment({
      id: `certificate-${selectedCertificate.publicId}`,
      title: `شهادة ${selectedCertificate.courseName}`,
      url: selectedCertificate.certificateUrl,
      fileType: 'image/png',
      mimeType: 'image/png',
      downloadVersion: selectedCertificate.publicId,
      external: false,
      platform: 'mobile',
      temporary: false,
    });
  };

  const openIssueCertificate = (course: CourseProgress) => {
    setIssueName(displayName);
    setIssueCourseId(course.id);
  };

  const closeIssueCertificate = () => {
    if (issuing) return;
    setIssueCourseId(null);
    setIssueName('');
  };

  const confirmIssueCertificate = async () => {
    if (!issueCourse || issuing || issueFlight.current) return;
    const holderName = issueName.trim().replace(/\s+/g, ' ');
    if (Array.from(holderName).length < 2) {
      Alert.alert('اكتب اسمك', 'هذا الاسم سيظهر على الشهادة');
      return;
    }
    issueFlight.current = true;
    setIssuing(true);
    try {
      const issued = await issueCertificate(issueCourse.id, holderName);
      setReadyCourses(current =>
        current.filter(course => course.id !== issueCourse.id),
      );
      setIssueCourseId(null);
      setIssueName('');
      if (issued?.status === 'active') {
        setCertificates(current => [
          issued,
          ...current.filter(certificate => certificate.id !== issued.id),
        ]);
        setSelectedId(issued.id);
      } else {
        pendingPollAttempts.current = 0;
        setCertificatePending(true);
        // Keep ownership until the read endpoint has observed the accepted
        // issue. Otherwise a fast second tap can POST the same issue again.
        await loadCertificates();
      }
    } catch (error: unknown) {
      Alert.alert(
        'تعذّر إصدار الشهادة',
        learnerErrorMessage(error, 'حاول مرة أخرى'),
      );
      // A timed-out POST has an unknown outcome. Keep the issue single-flight
      // until the authoritative list has been reconciled so a fast second tap
      // cannot request the same credential again.
      await loadCertificates();
    } finally {
      issueFlight.current = false;
      setIssuing(false);
    }
  };

  return (
    <View style={styles.container}>
      <SectionHeading title="شهاداتي" />

      {loading &&
      !certificates.length &&
      !readyCourses.length &&
      !grantCourses.length ? (
        <StatusView state="loading" title="جارٍ تحميل شهاداتك" />
      ) : loadError &&
        !certificates.length &&
        !readyCourses.length &&
        !grantCourses.length ? (
        <StatusView
          actionLabel="إعادة المحاولة"
          description={loadError}
          onAction={loadCertificates}
          state="error"
          title="تعذّر تحميل الشهادات"
        />
      ) : certificatePending &&
        !certificates.length &&
        !readyCourses.length &&
        !grantCourses.length ? (
        <StatusView
          actionLabel="إعادة المحاولة"
          description="سنحدّث حالتها تلقائيًا"
          onAction={loadCertificates}
          state="loading"
          title="شهادتك قيد التجهيز"
        />
      ) : !certificates.length &&
        !readyCourses.length &&
        !grantCourses.length ? (
        <StatusView
          actionLabel={
            serverSession === false && !LOCAL_DEMO_ENABLED
              ? 'تسجيل الدخول'
              : 'استكشف الكورسات'
          }
          description={
            serverSession === false && !LOCAL_DEMO_ENABLED
              ? 'سجّل الدخول لعرض شهاداتك ومشاركتها'
              : 'تظهر شهادتك هنا بعد إكمال الكورس واستيفاء شروطها'
          }
          onAction={() => {
            if (serverSession === false && !LOCAL_DEMO_ENABLED) {
              openGuestLogin(navigation, {
                name: 'Profile',
                params: {tab: 'certificates'},
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
          {!!loadError && (
            <Text accessibilityRole="alert" style={styles.partialNotice}>
              {loadError}
            </Text>
          )}
          {certificatePending && (
            <Pressable
              accessibilityRole="button"
              onPress={loadCertificates}
              style={styles.pendingNotice}>
              <Text accessibilityRole="alert" style={styles.partialNotice}>
                هناك شهادة قيد التجهيز
              </Text>
              <Text style={styles.pendingAction}>إعادة المحاولة</Text>
            </Pressable>
          )}
          <View style={styles.grid}>
            {certificates.map(certificate => (
              <Pressable
                accessibilityLabel={
                  certificate.status === 'pending'
                    ? `تحديث حالة شهادة ${certificate.courseName}`
                    : `عرض شهادة ${certificate.courseName}`
                }
                accessibilityRole="button"
                key={certificate.id}
                onPress={() =>
                  certificate.status === 'pending'
                    ? void loadCertificates()
                    : setSelectedId(certificate.id)
                }
                style={({pressed}) => [
                  styles.card,
                  contentWidth < 700 && styles.cardNarrow,
                  pressed && styles.pressed,
                ]}>
                <CertificateArtwork
                  compact
                  name={certificate.holderName}
                  url={destinationFor(certificate)}
                  courseTitle={certificate.courseName}
                  credential={certificate.publicId}
                  reviewedProject={
                    certificate.verificationLevel === 'reviewed_project'
                  }
                />
                <View style={styles.cardCopy}>
                  <MetaPill
                    label={
                      certificate.status === 'pending'
                        ? 'قيد التجهيز'
                        : 'شهادة موثقة'
                    }
                    tone={certificate.status === 'pending' ? 'neutral' : 'success'}
                  />
                  <Text numberOfLines={2} style={styles.title}>
                    {certificate.courseName}
                  </Text>
                  <View style={styles.verifiedRow}>
                    <View style={styles.verifiedDot} />
                    <Text numberOfLines={1} style={styles.verified}>
                      {certificate.status === 'pending' ? (
                        'اضغط لتحديث الحالة'
                      ) : (
                        <>
                          رقم الاعتماد ·{' '}
                          {isolateBidirectionalText(certificate.publicId)}
                        </>
                      )}
                    </Text>
                  </View>
                </View>
              </Pressable>
            ))}
          </View>
          {!!readyCourses.length && (
            <View style={styles.lockedSection}>
              <Text style={styles.lockedHeading}>جاهزة للإصدار</Text>
              {readyCourses.map(course => (
                <Pressable
                  accessibilityLabel={`إصدار شهادة ${course.title}`}
                  accessibilityRole="button"
                  key={`ready-${course.id}`}
                  onPress={() => openIssueCertificate(course)}
                  style={({pressed}) => [
                    styles.lockedCard,
                    styles.readyCard,
                    pressed && styles.pressed,
                  ]}>
                  <View style={[styles.lockedIcon, styles.readyIcon]}>
                    <Text style={styles.lockedIconText}>◇</Text>
                  </View>
                  <View style={styles.lockedCopy}>
                    <Text numberOfLines={2} style={styles.lockedTitle}>
                      {course.title}
                    </Text>
                    <Text style={styles.lockedMeta}>
                      اختر الاسم ثم أصدر الشهادة
                    </Text>
                  </View>
                  <Text style={styles.readyAction}>إصدار</Text>
                </Pressable>
              ))}
            </View>
          )}
          {!!grantCourses.length && (
            <View style={styles.lockedSection}>
              <Text style={styles.lockedHeading}>شهادات تنتظر التفعيل</Text>
              <Text style={styles.lockedIntro}>
                أنهيت الكورس بمنحتك كاملة
                {'\n'}يمكنك إضافة الشهادة وRokn AI من هنا
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
        animationType={reducedMotion ? 'none' : 'fade'}
        onRequestClose={closeIssueCertificate}
        statusBarTranslucent
        transparent
        visible={Boolean(issueCourse)}>
        <View style={styles.overlay}>
          <View
            accessibilityLabel="إصدار الشهادة"
            accessibilityViewIsModal
            style={[styles.sheet, styles.issueSheet]}>
            <View
              style={[
                styles.detailCopy,
                {
                  paddingBottom: Math.max(
                    Spacing.xl,
                    insets.bottom + Spacing.md,
                  ),
                  paddingLeft: Math.max(Spacing.xl, insets.left + Spacing.md),
                  paddingRight: Math.max(Spacing.xl, insets.right + Spacing.md),
                },
              ]}>
              <Text style={styles.detailTitle}>الاسم على الشهادة</Text>
              <Text style={styles.issueHint}>
                راجعه قبل الإصدار
                {'\n'}لن يتغير بعد ذلك
              </Text>
              <TextInput
                accessibilityLabel="الاسم على الشهادة"
                autoCapitalize="words"
                editable={!issuing}
                maxLength={120}
                onChangeText={setIssueName}
                placeholder="اسمك الكامل"
                placeholderTextColor={Palette.textFaint}
                style={styles.issueInput}
                value={issueName}
              />
              <Button
                disable={Array.from(issueName.trim()).length < 2 || issuing}
                loader={issuing}
                onPress={() => void confirmIssueCertificate()}
                title="إصدار الشهادة"
              />
              <Button
                disable={issuing}
                onPress={closeIssueCertificate}
                title="إلغاء"
                useGradient={false}
              />
            </View>
          </View>
        </View>
      </Modal>

      <Modal
        animationType={reducedMotion ? 'none' : 'slide'}
        onRequestClose={() => setSelectedId(null)}
        statusBarTranslucent
        transparent
        visible={Boolean(selectedCertificate)}>
        <View style={styles.overlay}>
          <View
            accessibilityLabel="تفاصيل الشهادة"
            accessibilityViewIsModal
            style={styles.sheet}>
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
              <CertificateArtifactPreview
                certificateUrl={selectedCertificate?.certificateUrl}
                name={activeHolderName}
                verificationUrl={activeCertificateLink}
                courseTitle={activeCourseTitle}
                credential={activeCredential}
                reviewedProject={
                  selectedCertificate?.verificationLevel === 'reviewed_project'
                }
              />
              <View
                style={[
                  styles.detailCopy,
                  {
                    paddingLeft: Math.max(Spacing.xl, insets.left + Spacing.md),
                    paddingRight: Math.max(
                      Spacing.xl,
                      insets.right + Spacing.md,
                    ),
                  },
                ]}>
                <View style={styles.verifiedRow}>
                  <View style={styles.verifiedDot} />
                  <Text style={styles.verified}>شهادة موثقة من ركن</Text>
                </View>
                <Text style={styles.detailTitle}>{activeCourseTitle}</Text>
                <Text style={styles.detailMeta}>
                  رقم الاعتماد {'\n'}
                  {isolateBidirectionalText(activeCredential)}
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
                      {isolateBidirectionalText(activeCertificateLink)}
                    </Text>
                    <Text style={styles.qrHint}>
                      يفتح صفحة التحقق من الشهادة
                    </Text>
                  </View>
                </View>
                <Button
                  onPress={() => void openCertificate()}
                  title="فتح صفحة التحقق"
                />
                <Button
                  onPress={() => void shareCertificate()}
                  title="مشاركة رابط الشهادة"
                  useGradient={false}
                />
                {selectedCertificate?.certificateUrl && (
                  <Button
                    onPress={saveCertificate}
                    title="حفظ الشهادة"
                    useGradient={false}
                  />
                )}
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
  partialNotice: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginBottom: Spacing.sm,
  },
  pendingNotice: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: Spacing.sm,
    marginBottom: Spacing.sm,
  },
  pendingAction: {...Type.caption, color: Palette.primary},
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
  readyCard: {
    borderColor: 'rgba(72,185,138,.24)',
    backgroundColor: 'rgba(72,185,138,.1)',
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
  readyIcon: {backgroundColor: 'rgba(72,185,138,.14)'},
  lockedCopy: {flex: 1},
  lockedTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  lockedMeta: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 2,
  },
  lockedAction: {...Type.caption, color: '#E5BD67'},
  readyAction: {...Type.caption, color: Palette.success},
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
  artworkOfficial: {aspectRatio: 4 / 3},
  artifactPreview: {
    width: '100%',
    aspectRatio: 4 / 3,
    overflow: 'hidden',
    backgroundColor: '#F7F4ED',
  },
  artifactImage: {
    ...StyleSheet.absoluteFillObject,
    width: '100%',
    height: '100%',
    backgroundColor: '#F7F4ED',
  },
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
  issueSheet: {maxWidth: 560},
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
  issueHint: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  issueInput: {
    ...Type.body,
    ...textDirection,
    minHeight: 52,
    color: Palette.text,
    backgroundColor: Palette.surface,
    borderColor: Palette.line,
    borderWidth: 1,
    borderRadius: Radius.md,
    paddingHorizontal: Spacing.md,
    marginTop: Spacing.lg,
    marginBottom: Spacing.sm,
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
    color: '#8BB5FF',
    marginTop: Spacing.xs,
    writingDirection: 'ltr',
    textAlign: 'left',
  },
  qrHint: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  pressed: {opacity: 0.8, transform: [{scale: 0.99}]},
});
