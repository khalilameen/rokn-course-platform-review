import React, {useEffect, useRef, useState} from 'react';
import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import {
  ActivityIndicator,
  Alert,
  NativeModules,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {
  ImagePickerResponse,
  launchImageLibrary,
  MediaType,
  PhotoQuality,
} from 'react-native-image-picker';
import Svg, {Path} from 'react-native-svg';
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import {rtlRowStyle, textDirection} from '../../constants/designSystem';
import {Fonts} from '../../constants/styleConstants';
import {openCourseAttachment} from './attachmentActions';
import {CourseProject, SelectedProjectFile} from './types';
import type {ProjectSubmissionOutcome} from './courseLearningApi';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';
import {
  PROJECT_SUBMISSION_FORMATS_LABEL,
  PROJECT_SUBMISSION_MAX_LABEL,
  validateProjectFile,
} from '../../config/projects';

interface ProjectTransitionProps {
  project: CourseProject;
  moduleTitle: string;
  width: number;
  height: number;
  topInset?: number;
  bottomInset?: number;
  onSubmit: (file: SelectedProjectFile) => Promise<ProjectSubmissionOutcome>;
  onContinue?: () => void;
}

const UploadIcon = () => (
  <Svg width={27} height={27} viewBox="0 0 28 28">
    <Path
      d="M14 19V5m0 0L8.8 10.2M14 5l5.2 5.2M5.5 18.2v3.3c0 1.1.9 2 2 2h13c1.1 0 2-.9 2-2v-3.3"
      fill="none"
      stroke="#fff"
      strokeWidth={1.9}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const pickMedia = (): Promise<SelectedProjectFile | null> =>
  new Promise(resolve => {
    launchImageLibrary(
      {
        mediaType: 'mixed' as MediaType,
        quality: 0.8 as PhotoQuality,
        includeBase64: false,
        selectionLimit: 1,
      },
      (response: ImagePickerResponse) => {
        if (response.didCancel) {
          resolve(null);
          return;
        }
        if (response.errorMessage) {
          Alert.alert('تعذر اختيار الملف', response.errorMessage);
          resolve(null);
          return;
        }
        const asset = response.assets?.[0];
        if (!asset?.uri) {
          resolve(null);
          return;
        }
        resolve({
          uri: asset.uri,
          name: asset.fileName || `rokn-project-${Date.now()}`,
          type: asset.type || 'application/octet-stream',
          size: asset.fileSize,
        });
      },
    );
  });

const ProjectTransition = ({
  project,
  moduleTitle,
  width,
  height,
  topInset = 0,
  bottomInset = 0,
  onSubmit,
  onContinue,
}: ProjectTransitionProps) => {
  const navigation = useNavigation<RootNavigation>();
  const [selectedFile, setSelectedFile] = useState<SelectedProjectFile | null>(
    null,
  );
  const [status, setStatus] = useState(project.status);
  const [syncNote, setSyncNote] = useState('');
  const submissionInFlightRef = useRef(false);

  useEffect(() => {
    setStatus(project.status);
    if (project.status !== 'reviewing') {
      setSyncNote('');
    }
  }, [project.status]);

  const submitSelectedFile = async (file: SelectedProjectFile) => {
    try {
      const size = await validateProjectFile(file);
      if (size !== file.size) {
        setSelectedFile({...file, size});
      }
    } catch (error: unknown) {
      const code = error instanceof Error ? error.message : '';
      Alert.alert(
        code === 'PROJECT_FILE_TOO_LARGE'
          ? 'حجم الملف كبير'
          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
          ? 'صيغة الملف غير مدعومة'
          : 'تعذّر قراءة حجم الملف',
        code === 'PROJECT_FILE_TOO_LARGE'
          ? `اختار ملف أقل من ${PROJECT_SUBMISSION_MAX_LABEL} عشان يترفع بسهولة حتى لو الاتصال ضعيف.`
          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
          ? `اختار ${PROJECT_SUBMISSION_FORMATS_LABEL}.`
          : 'اختار الملف مرة أخرى أو جرّب نسخة أصغر منه.',
      );
      return;
    }
    if (
      Platform.OS === 'android' &&
      file.type.startsWith('image/') &&
      NativeModules.RoknMediaInspector?.inspect
    ) {
      try {
        const inspection = await NativeModules.RoknMediaInspector.inspect(
          file.uri,
        );
        if (inspection?.isBlank) {
          Alert.alert(
            'الصورة مش واضحة',
            'اختار صورة تبين شغلك. الصور السوداء أو الفارغة مش هتتحسب محاولة.',
          );
          return;
        }
      } catch {
        // Inspection is a guardrail, never a reason to block a sincere learner.
      }
    }
    setStatus('reviewing');
    setSyncNote('');
    const startedAt = Date.now();
    const provisionalFallback: ProjectSubmissionOutcome = {
      passed: false,
      synced: false,
      provisional: true,
      canContinue: false,
    };
    let result = provisionalFallback;
    try {
      result = await onSubmit(file);
    } catch (error: unknown) {
      if (
        error instanceof Error &&
        error.message === 'PROJECT_PENDING_CACHE_FULL'
      ) {
        setStatus(project.status);
        setSyncNote('');
        Alert.alert(
          'المشاريع المعلّقة أخذت المساحة المتاحة',
          'مشاريعك القديمة ما زالت محفوظة. اتصل بالإنترنت وافتح ركن حتى تُرسل، ثم جرّب تسليم هذا المشروع مرة أخرى.',
        );
        return;
      }
      result = provisionalFallback;
    }
    const elapsed = Date.now() - startedAt;
    if (elapsed < 1500) {
      await new Promise<void>(resolve =>
        setTimeout(() => resolve(), 1500 - elapsed),
      );
    }
    setStatus(
      result.passed
        ? 'passed'
        : result.provisional
        ? 'reviewing'
        : 'needs_retry',
    );
    if (result.provisional) {
      setSyncNote(
        'استلمنا مشروعك ومكانك محفوظ\nأول ما المراجعة تخلص هنفتح لك اللي بعده هنا',
      );
    }
  };

  const submit = async () => {
    if (!selectedFile) {
      Alert.alert('اختار اللي هترفعه', 'صورة أو فيديو واضح لشغلك كفاية.');
      return;
    }
    if (submissionInFlightRef.current) return;
    submissionInFlightRef.current = true;
    try {
      await submitSelectedFile(selectedFile);
    } finally {
      submissionInFlightRef.current = false;
    }
  };

  return (
    <View style={[styles.page, {width, height}]}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="العودة"
        hitSlop={10}
        style={[styles.backButton, {top: topInset + 8}]}
        onPress={() => goBackOrHome(navigation)}>
        <Text style={styles.backSymbol}>›</Text>
      </Pressable>
      <ScrollView
        showsVerticalScrollIndicator={false}
        contentContainerStyle={[
          styles.content,
          {paddingTop: topInset + 36, paddingBottom: bottomInset + 38},
        ]}>
        <View style={styles.eyebrowRow}>
          <View style={styles.eyebrowLine} />
          <Text style={styles.eyebrow}>دلوقتي دورك تطبّق</Text>
        </View>
        <Text style={styles.moduleTitle}>
          {formatArabicDisplayText(moduleTitle)}
        </Text>

        <View style={styles.card}>
          <View style={styles.projectBadge}>
            <Text style={styles.projectBadgeText}>
              {project.isGraduationProject ? 'مشروع التخرج' : 'مشروع العبور'}
            </Text>
          </View>
          <Text style={styles.title}>
            {formatArabicDisplayText(project.title)}
          </Text>
          <Text style={styles.requirements}>
            {formatArabicDisplayText(project.requirements)}
          </Text>

          {!!project.attachments.length && (
            <View style={styles.attachmentsBlock}>
              <Text style={styles.blockLabel}>ملفات قد تحتاجها</Text>
              {project.attachments.map(attachment => (
                <Pressable
                  key={attachment.id}
                  accessibilityRole="button"
                  style={styles.attachmentRow}
                  onPress={() =>
                    openCourseAttachment(attachment).catch(() => undefined)
                  }>
                  <View style={styles.attachmentCopy}>
                    <Text style={styles.attachmentTitle} numberOfLines={1}>
                      {formatArabicDisplayText(attachment.title)}
                    </Text>
                    <Text style={styles.attachmentMeta}>
                      {attachment.platform === 'computer'
                        ? 'انسخ الرابط وافتحه على الكمبيوتر'
                        : formatArabicDisplayText(
                            [attachment.fileType, attachment.fileSize]
                              .filter(Boolean)
                              .join(' · ') || 'تنزيل مباشر',
                          )}
                    </Text>
                  </View>
                  <Text style={styles.attachmentAction}>
                    {attachment.platform === 'computer'
                      ? 'نسخ الرابط'
                      : 'تنزيل'}
                  </Text>
                </Pressable>
              ))}
            </View>
          )}

          {status === 'passed' ? (
            <View style={styles.successState}>
              <View style={styles.successIcon}>
                <Text style={styles.successCheck}>✓</Text>
              </View>
              <Text style={styles.successTitle}>مشروعك عدى</Text>
              <Text style={styles.successDescription}>
                {onContinue
                  ? 'فتحنا لك الخطوة اللي بعدها'
                  : 'نجاحك اتسجل ومكانك محفوظ'}
              </Text>
              {!!syncNote && <Text style={styles.syncNote}>{syncNote}</Text>}
              {!!onContinue && (
                <Pressable
                  accessibilityRole="button"
                  style={styles.primaryButton}
                  onPress={onContinue}>
                  <Text style={styles.primaryButtonText}>كمّل الكورس</Text>
                </Pressable>
              )}
            </View>
          ) : status === 'reviewing' ? (
            <View style={styles.reviewState}>
              <View style={styles.reviewLoader}>
                <ActivityIndicator color="#76A9FF" size="large" />
              </View>
              <Text style={styles.reviewTitle}>مشروعك عندنا</Text>
              <Text style={styles.reviewDescription}>بنراجعه ومكانك محفوظ</Text>
              {!!syncNote && <Text style={styles.syncNote}>{syncNote}</Text>}
            </View>
          ) : (
            <View style={styles.uploadBlock}>
              <Pressable
                accessibilityRole="button"
                style={styles.uploadTarget}
                onPress={async () => {
                  const file = await pickMedia();
                  if (file) {
                    try {
                      const size = await validateProjectFile(file);
                      setSelectedFile({...file, size});
                    } catch (error: unknown) {
                      const code = error instanceof Error ? error.message : '';
                      Alert.alert(
                        code === 'PROJECT_FILE_TOO_LARGE'
                          ? 'حجم الملف كبير'
                          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
                          ? 'صيغة الملف غير مدعومة'
                          : 'تعذّر قراءة حجم الملف',
                        code === 'PROJECT_FILE_TOO_LARGE'
                          ? `الحد الأقصى ${PROJECT_SUBMISSION_MAX_LABEL}. اختار نسخة أصغر وحاول مرة أخرى.`
                          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
                          ? `اختار ${PROJECT_SUBMISSION_FORMATS_LABEL}.`
                          : 'اختار الملف مرة أخرى أو جرّب نسخة أصغر منه.',
                      );
                    }
                  }
                }}>
                <View style={styles.uploadIcon}>
                  <UploadIcon />
                </View>
                <View style={styles.uploadCopy}>
                  <Text style={styles.uploadTitle}>
                    {selectedFile
                      ? selectedFile.name
                      : 'ارفع صورة أو فيديو يوضح شغلك'}
                  </Text>
                  <Text style={styles.uploadHint}>
                    {selectedFile
                      ? 'اضغط لتغيير الملف'
                      : 'المهم يكون واضح إنك جرّبت ونفّذت'}
                  </Text>
                </View>
              </Pressable>
              <Pressable
                accessibilityRole="button"
                disabled={!selectedFile}
                style={[
                  styles.primaryButton,
                  !selectedFile && styles.disabledButton,
                ]}
                onPress={submit}>
                <Text style={styles.primaryButtonText}>سلّم المشروع</Text>
              </Pressable>
            </View>
          )}
        </View>
      </ScrollView>
    </View>
  );
};

export default ProjectTransition;
export {pickMedia};

const styles = StyleSheet.create({
  page: {
    backgroundColor: '#070B11',
  },
  backButton: {
    position: 'absolute',
    start: 12,
    width: 42,
    height: 42,
    borderRadius: 21,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(5,9,14,.72)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.12)',
    zIndex: 20,
  },
  backSymbol: {
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 35,
    lineHeight: 37,
    marginBottom: 3,
  },
  content: {
    direction: 'rtl',
    flexGrow: 1,
    width: '100%',
    maxWidth: 700,
    alignSelf: 'center',
    paddingHorizontal: 18,
    justifyContent: 'center',
  },
  eyebrowRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 8,
  },
  eyebrowLine: {
    width: 24,
    height: 2,
    borderRadius: 1,
    backgroundColor: '#4B8EF7',
  },
  eyebrow: {
    ...textDirection,
    color: '#76A9FF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  moduleTitle: {
    ...textDirection,
    color: 'rgba(255,255,255,.58)',
    fontFamily: Fonts.medium,
    fontSize: 13,
    marginTop: 7,
    marginBottom: 18,
  },
  card: {
    direction: 'rtl',
    borderRadius: 26,
    padding: 20,
    backgroundColor: '#111923',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
  projectBadge: {
    alignSelf: 'flex-start',
    minHeight: 27,
    paddingHorizontal: 11,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(75,142,247,.14)',
    borderWidth: 1,
    borderColor: 'rgba(91,153,251,.25)',
  },
  projectBadgeText: {
    ...textDirection,
    color: '#8BB6FA',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  title: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 23,
    lineHeight: 35,
    marginTop: 13,
  },
  requirements: {
    ...textDirection,
    color: 'rgba(255,255,255,.72)',
    fontFamily: Fonts.regular,
    fontSize: 14,
    lineHeight: 24,
    marginTop: 7,
  },
  attachmentsBlock: {
    marginTop: 20,
    gap: 8,
  },
  blockLabel: {
    ...textDirection,
    color: 'rgba(255,255,255,.52)',
    fontFamily: Fonts.medium,
    fontSize: 11,
  },
  attachmentRow: {
    minHeight: 58,
    borderRadius: 15,
    paddingHorizontal: 12,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
    backgroundColor: 'rgba(255,255,255,.045)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.07)',
  },
  attachmentCopy: {
    flex: 1,
    minWidth: 0,
  },
  attachmentTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.medium,
    fontSize: 12,
  },
  attachmentMeta: {
    ...textDirection,
    color: 'rgba(255,255,255,.43)',
    fontFamily: Fonts.regular,
    fontSize: 10,
    marginTop: 2,
  },
  attachmentAction: {
    ...textDirection,
    color: '#76A9FF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  uploadBlock: {
    marginTop: 22,
    gap: 12,
  },
  uploadTarget: {
    minHeight: 78,
    borderRadius: 18,
    padding: 13,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 12,
    backgroundColor: 'rgba(255,255,255,.035)',
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: 'rgba(118,169,255,.4)',
  },
  uploadIcon: {
    width: 48,
    height: 48,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(35,111,232,.2)',
  },
  uploadCopy: {
    flex: 1,
    minWidth: 0,
  },
  uploadTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 13,
  },
  uploadHint: {
    ...textDirection,
    color: 'rgba(255,255,255,.48)',
    fontFamily: Fonts.regular,
    fontSize: 10,
    lineHeight: 17,
    marginTop: 3,
  },
  primaryButton: {
    width: '100%',
    minHeight: 50,
    borderRadius: 17,
    paddingHorizontal: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
  },
  disabledButton: {
    opacity: 0.38,
  },
  primaryButtonText: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 14,
  },
  reviewState: {
    minHeight: 190,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 18,
  },
  reviewLoader: {
    width: 66,
    height: 66,
    borderRadius: 33,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(52,120,246,.10)',
    borderWidth: 1,
    borderColor: 'rgba(118,169,255,.24)',
  },
  reviewTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 17,
    marginTop: 15,
    textAlign: 'center',
  },
  reviewDescription: {
    direction: 'rtl',
    writingDirection: 'rtl',
    color: 'rgba(255,255,255,.55)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    marginTop: 4,
    textAlign: 'center',
  },
  successState: {
    minHeight: 215,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 18,
  },
  successIcon: {
    width: 54,
    height: 54,
    borderRadius: 27,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(70,196,135,.15)',
    borderWidth: 1,
    borderColor: 'rgba(90,218,156,.3)',
  },
  successCheck: {
    color: '#67D39B',
    fontFamily: Fonts.bold,
    fontSize: 25,
  },
  successTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 18,
    marginTop: 12,
    textAlign: 'center',
  },
  successDescription: {
    direction: 'rtl',
    writingDirection: 'rtl',
    color: 'rgba(255,255,255,.56)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    marginTop: 3,
    textAlign: 'center',
  },
  syncNote: {
    direction: 'rtl',
    writingDirection: 'rtl',
    color: '#8BB6FA',
    fontFamily: Fonts.regular,
    fontSize: 10,
    lineHeight: 16,
    textAlign: 'center',
    marginTop: 8,
  },
});
