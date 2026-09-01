import React, {useEffect, useRef, useState} from 'react';
import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import {
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  NativeModules,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
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
import {
  CourseProject,
  ProjectFeedbackThread,
  SelectedProjectFile,
} from './types';
import type {ProjectSubmissionOutcome} from './courseLearningApi';
import {
  loadProjectFeedbackThread,
  sendProjectFeedbackMessage,
} from './courseLearningApi';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';
import {secureRandomUuid} from '../../utils/secureRandom';
import {
  PROJECT_SUBMISSION_FORMATS_LABEL,
  PROJECT_SUBMISSION_MAX_LABEL,
  validateProjectFile,
} from '../../config/projects';
import {learnerErrorMessage} from '../../utils/errorPayload';
import {cleanUnicodeText, truncateGraphemes} from '../../utils/unicodeText';
import {
  cacheProjectDraftFile,
  clearProjectSubmissionDraft,
  loadProjectSubmissionDraft,
  saveProjectSubmissionDraft,
} from '../../services/projectSubmissionDraft';
import {removeLearnerDraftFile} from '../../services/learnerDraftFiles';
import {useAppActiveState} from '../../hooks/useAppActiveState';
import {showMediaPickerFailure} from '../../services/mediaPickerErrors';

interface ProjectTransitionProps {
  active: boolean;
  project: CourseProject;
  moduleTitle: string;
  width: number;
  height: number;
  topInset?: number;
  bottomInset?: number;
  onSubmit: (
    file: SelectedProjectFile,
    note?: string,
  ) => Promise<ProjectSubmissionOutcome>;
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
        if (response.errorCode || response.errorMessage) {
          // Native picker diagnostics vary by vendor and are often technical
          // English strings. Keep them in telemetry rather than product copy.
          showMediaPickerFailure(response.errorCode);
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
  active,
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
  const appIsActive = useAppActiveState();
  const pickerFlightRef = useRef(false);
  const [selectedFile, setSelectedFile] = useState<SelectedProjectFile | null>(
    null,
  );
  const [status, setStatus] = useState(project.status);
  const [submissionNote, setSubmissionNote] = useState('');
  const [submissionDraftReady, setSubmissionDraftReady] = useState(false);
  const [submissionDraftSaveError, setSubmissionDraftSaveError] =
    useState(false);
  const [syncNote, setSyncNote] = useState('');
  const [feedbackThread, setFeedbackThread] = useState<
    ProjectFeedbackThread | undefined
  >(project.feedbackThread);
  const [feedbackDraft, setFeedbackDraft] = useState('');
  const normalizedSubmissionNote = cleanUnicodeText(submissionNote);
  const normalizedFeedbackDraft = cleanUnicodeText(feedbackDraft);
  const [feedbackSending, setFeedbackSending] = useState(false);
  const [feedbackError, setFeedbackError] = useState('');
  const feedbackPending = Boolean(
    feedbackThread?.messages.some(message =>
      ['queued', 'sent', 'streaming'].includes(message.status),
    ),
  );
  const submissionInFlightRef = useRef(false);
  const feedbackRequestRef = useRef<{text: string; id: string} | null>(null);
  const feedbackSendFlightRef = useRef<symbol | null>(null);
  const feedbackGenerationRef = useRef(0);
  const draftGenerationRef = useRef(0);
  const submissionDraftSnapshotRef = useRef({
    file: selectedFile,
    note: submissionNote,
  });
  submissionDraftSnapshotRef.current = {
    file: selectedFile,
    note: submissionNote,
  };

  useEffect(() => {
    setStatus(project.status);
    if (project.status !== 'reviewing') {
      setSyncNote('');
    }
  }, [project.status]);

  useEffect(() => {
    const generation = ++draftGenerationRef.current;
    setSubmissionDraftReady(false);
    setSubmissionDraftSaveError(false);
    if (project.status === 'passed') {
      setSelectedFile(null);
      setSubmissionNote('');
      void clearProjectSubmissionDraft(project.id);
      setSubmissionDraftReady(true);
      return;
    }
    void loadProjectSubmissionDraft(project.id)
      .then(draft => {
        if (generation !== draftGenerationRef.current || !draft) return;
        setSelectedFile(draft.file || null);
        setSubmissionNote(draft.note);
      })
      .catch(() => {
        if (generation === draftGenerationRef.current) {
          setSubmissionDraftSaveError(true);
        }
      })
      .finally(() => {
        if (generation === draftGenerationRef.current) {
          setSubmissionDraftReady(true);
        }
      });
    return () => {
      draftGenerationRef.current += 1;
    };
  }, [project.id, project.status]);

  useEffect(() => {
    if (project.status === 'passed' || !submissionDraftReady) return;
    const timer = setTimeout(() => {
      void saveProjectSubmissionDraft(project.id, {
        file: selectedFile,
        note: submissionNote,
        updatedAt: Date.now(),
      })
        .then(() => setSubmissionDraftSaveError(false))
        .catch(() => setSubmissionDraftSaveError(true));
    }, 250);
    return () => clearTimeout(timer);
  }, [
    project.id,
    project.status,
    selectedFile,
    submissionDraftReady,
    submissionNote,
  ]);

  useEffect(() => {
    if (
      appIsActive ||
      project.status === 'passed' ||
      !submissionDraftReady
    ) {
      return;
    }
    void saveProjectSubmissionDraft(project.id, {
      ...submissionDraftSnapshotRef.current,
      updatedAt: Date.now(),
    }).catch(() => setSubmissionDraftSaveError(true));
  }, [appIsActive, project.id, project.status, submissionDraftReady]);

  useEffect(() => {
    feedbackGenerationRef.current += 1;
    feedbackRequestRef.current = null;
    feedbackSendFlightRef.current = null;
    setFeedbackSending(false);
    setFeedbackError('');
  }, [project.id]);

  useEffect(() => {
    setFeedbackThread(project.feedbackThread);
  }, [project.feedbackThread, project.id]);

  useEffect(() => {
    if (status !== 'passed' || !active || !appIsActive) return;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let missingAttempts = 0;

    const refresh = async () => {
      try {
        const next = await loadProjectFeedbackThread(
          project.id,
          feedbackThread?.id,
        );
        if (cancelled) return;
        if (next) {
          setFeedbackThread(next);
          setFeedbackError('');
        } else {
          missingAttempts += 1;
        }
        const waiting = next?.messages.some(message =>
          ['queued', 'sent', 'streaming'].includes(message.status),
        );
        if (waiting || (!next && missingAttempts < 30)) {
          timer = setTimeout(() => void refresh(), 2200);
        }
      } catch {
        if (!cancelled && feedbackThread?.id) {
          timer = setTimeout(() => void refresh(), 3500);
        }
      }
    };
    void refresh();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [
    active,
    appIsActive,
    feedbackPending,
    feedbackThread?.id,
    project.id,
    status,
  ]);

  const sendFeedback = async (
    text = feedbackDraft,
    clientRequestId?: string,
    forceNewRequest = false,
  ) => {
    const value = cleanUnicodeText(text);
    if (
      !feedbackThread?.canReply ||
      !value ||
      feedbackSending ||
      feedbackSendFlightRef.current
    )
      return;
    const flight = Symbol('project-feedback-send');
    const generation = feedbackGenerationRef.current;
    feedbackSendFlightRef.current = flight;
    setFeedbackSending(true);
    setFeedbackError('');
    const requestId =
      clientRequestId ||
      (!forceNewRequest && feedbackRequestRef.current?.text === value
        ? feedbackRequestRef.current.id
        : secureRandomUuid());
    feedbackRequestRef.current = {text: value, id: requestId};
    try {
      const next = await sendProjectFeedbackMessage(
        feedbackThread.id,
        value,
        requestId,
      );
      if (generation !== feedbackGenerationRef.current) return;
      setFeedbackThread(next);
      setFeedbackDraft('');
      feedbackRequestRef.current = null;
    } catch (error: unknown) {
      if (generation !== feedbackGenerationRef.current) return;
      setFeedbackError(
        learnerErrorMessage(error, 'لم تُرسل الرسالة\nحاول مرة أخرى'),
      );
    } finally {
      if (
        generation === feedbackGenerationRef.current &&
        feedbackSendFlightRef.current === flight
      ) {
        feedbackSendFlightRef.current = null;
        setFeedbackSending(false);
      }
    }
  };

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
          ? `اختر ملفًا أصغر من ${PROJECT_SUBMISSION_MAX_LABEL}`
          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
          ? `اختر ${PROJECT_SUBMISSION_FORMATS_LABEL}`
          : 'اختر الملف مرة أخرى أو نسخة أصغر',
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
            'الصورة غير واضحة',
            'اختر صورة واضحة لعملك\nالصور الفارغة لا تُقبل',
          );
          return;
        }
      } catch {
        // Inspection is a guardrail, never a reason to block a sincere learner.
      }
    }
    setStatus('reviewing');
    setSyncNote('');
    const provisionalFallback: ProjectSubmissionOutcome = {
      passed: false,
      synced: false,
      provisional: true,
      canContinue: false,
    };
    let result = provisionalFallback;
    try {
      result = await onSubmit(file, normalizedSubmissionNote);
      await clearProjectSubmissionDraft(project.id, file);
      setSelectedFile(null);
      setSubmissionNote('');
    } catch (error: unknown) {
      if (
        error instanceof Error &&
        error.message === 'PROJECT_PENDING_CACHE_FULL'
      ) {
        setStatus(project.status);
        setSyncNote('');
        Alert.alert(
          'اكتملت مساحة المشروعات المعلّقة',
          'اتصل بالإنترنت لإرسالها\nثم حاول تسليم هذا المشروع',
        );
        return;
      }
      if (
        error instanceof Error &&
        error.message === 'PROJECT_FILE_COPY_FAILED'
      ) {
        setStatus(project.status);
        setSyncNote('');
        Alert.alert(
          'تعذّر تجهيز الملف',
          'اختر الملف مرة أخرى\nوتأكد من وجود مساحة كافية على الجهاز',
        );
        return;
      }
      const responseStatus = Number(
        error && typeof error === 'object'
          ? (error as {status?: unknown; response?: {status?: unknown}})
              .status ??
              (error as {response?: {status?: unknown}}).response?.status
          : 0,
      );
      setStatus('needs_retry');
      setSyncNote('');
      Alert.alert(
        'لم يكتمل التسليم',
        responseStatus === 401
          ? 'سجّل الدخول ثم حاول مرة أخرى'
          : responseStatus === 403
          ? 'لم يعد هذا المشروع متاحًا لحسابك'
          : responseStatus === 409
          ? 'أكمل المحتوى السابق ثم حاول مرة أخرى'
          : responseStatus === 422
          ? 'راجع الملف المختار ثم حاول مرة أخرى'
          : 'حاول تسليم المشروع مرة أخرى',
      );
      return;
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
        result.synced
          ? 'استلمنا مشروعك\nسنفتح المقطع التالي بعد المراجعة'
          : 'نحفظ محاولتك\nوسنرسلها عند استقرار الاتصال',
      );
    }
  };

  const submit = async () => {
    if (
      !selectedFile ||
      (project.reportEnabled && normalizedSubmissionNote.length < 10)
    ) {
      Alert.alert(
        !selectedFile ? 'اختر ملف المشروع' : 'اشرح ما نفذته',
        !selectedFile
          ? 'صورة أو فيديو واضح لعملك'
          : 'اكتب سطرًا واضحًا عن محاولتك',
      );
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

  const chooseProjectFile = async () => {
    if (pickerFlightRef.current || submissionInFlightRef.current) return;
    pickerFlightRef.current = true;
    try {
      const file = await pickMedia();
      if (!file) return;
      const size = await validateProjectFile(file);
      const cached = await cacheProjectDraftFile({...file, size});
      const previous = selectedFile;
      setSelectedFile(cached);
      await removeLearnerDraftFile(previous);
    } catch (error: unknown) {
      const code = error instanceof Error ? error.message : '';
      Alert.alert(
        code === 'PROJECT_FILE_TOO_LARGE'
          ? 'حجم الملف كبير'
          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
          ? 'صيغة الملف غير مدعومة'
          : 'تعذّر قراءة الملف',
        code === 'PROJECT_FILE_TOO_LARGE'
          ? `الحد الأقصى ${PROJECT_SUBMISSION_MAX_LABEL}\nاختر نسخة أصغر`
          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
          ? `اختر ${PROJECT_SUBMISSION_FORMATS_LABEL}`
          : 'اختر الملف مرة أخرى أو نسخة أصغر',
      );
    } finally {
      pickerFlightRef.current = false;
    }
  };

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      keyboardVerticalOffset={topInset}
      style={[styles.page, {width, height}]}>
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
          <Text style={styles.eyebrow}>حان وقت التطبيق</Text>
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
              <Text style={styles.successTitle}>تم اعتماد مشروعك</Text>
              <Text style={styles.successDescription}>
                {onContinue
                  ? 'فتحنا لك المقطع التالي'
                  : 'تم اعتماد النتيجة وحفظ تقدمك'}
              </Text>
              {!!syncNote && <Text style={styles.syncNote}>{syncNote}</Text>}
              {!!onContinue && (
                <Pressable
                  accessibilityRole="button"
                  style={styles.primaryButton}
                  onPress={onContinue}>
                  <Text style={styles.primaryButtonText}>أكمل الكورس</Text>
                </Pressable>
              )}
              {!!feedbackThread && (
                <View style={styles.feedbackThread}>
                  <View style={styles.feedbackHeader}>
                    <Text style={styles.feedbackTitle}>شات ركن</Text>
                    <Text style={styles.feedbackAvailability}>
                      {feedbackThread.canReply ? 'متصل الآن' : 'تقرير مشروعك'}
                    </Text>
                  </View>
                  {feedbackThread.messages.map(message => (
                    <View
                      key={message.id}
                      style={[
                        styles.feedbackBubble,
                        message.role === 'user'
                          ? styles.feedbackBubbleUser
                          : styles.feedbackBubbleAssistant,
                      ]}>
                      {!!message.text && (
                        <Text style={styles.feedbackMessage}>
                          {formatArabicDisplayText(message.text)}
                        </Text>
                      )}
                      {message.role === 'assistant' &&
                        message.status === 'streaming' && (
                          <Text style={styles.feedbackState}>يكتب الآن</Text>
                        )}
                      {message.role === 'assistant' &&
                        message.status === 'failed' && (
                          <Text style={styles.feedbackState}>
                            {message.errorCode === 'plan_limit_reached'
                              ? 'اكتملت رسائل متابعة المشروع في هذه الفئة'
                              : 'تعذّر الرد الآن\nأرسل رسالتك مرة أخرى'}
                          </Text>
                        )}
                      {message.role === 'user' &&
                        message.status === 'queued' && (
                          <Text style={styles.feedbackState}>جارٍ الإرسال</Text>
                        )}
                      {message.status === 'failed' &&
                        message.role === 'user' &&
                        message.errorCode !== 'plan_limit_reached' && (
                          <Pressable
                            accessibilityRole="button"
                            disabled={feedbackSending}
                            onPress={() =>
                              void sendFeedback(
                                message.text || '',
                                undefined,
                                true,
                              )
                            }>
                            <Text style={styles.feedbackRetry}>
                              إرسال مرة أخرى
                            </Text>
                          </Pressable>
                        )}
                    </View>
                  ))}
                  {feedbackThread.canReply &&
                    feedbackThread.remainingMessages > 0 &&
                    !feedbackPending && (
                      <View style={styles.feedbackComposer}>
                        <TextInput
                          multiline
                          value={feedbackDraft}
                          onChangeText={value =>
                            setFeedbackDraft(truncateGraphemes(value, 2000))
                          }
                          placeholder="اسأل عن مشروعك"
                          placeholderTextColor="rgba(255,255,255,.38)"
                          style={styles.feedbackInput}
                        />
                        <Pressable
                          accessibilityRole="button"
                          disabled={!normalizedFeedbackDraft || feedbackSending}
                          onPress={() => void sendFeedback()}
                          style={[
                            styles.feedbackSend,
                            (!normalizedFeedbackDraft || feedbackSending) &&
                              styles.disabledButton,
                          ]}>
                          {feedbackSending ? (
                            <ActivityIndicator color="#FFFFFF" size="small" />
                          ) : (
                            <Text style={styles.feedbackSendText}>إرسال</Text>
                          )}
                        </Pressable>
                      </View>
                    )}
                  {!!feedbackError && (
                    <Text
                      accessibilityRole="alert"
                      style={styles.feedbackError}>
                      {feedbackError}
                    </Text>
                  )}
                </View>
              )}
            </View>
          ) : status === 'reviewing' ? (
            <View style={styles.reviewState}>
              <View style={styles.reviewLoader}>
                <ActivityIndicator color="#76A9FF" size="large" />
              </View>
              <Text style={styles.reviewTitle}>مشروعك محفوظ</Text>
              <Text style={styles.reviewDescription}>سنحدّث النتيجة هنا</Text>
              {!!syncNote && <Text style={styles.syncNote}>{syncNote}</Text>}
            </View>
          ) : (
            <View style={styles.uploadBlock}>
              <Pressable
                accessibilityRole="button"
                style={styles.uploadTarget}
                onPress={() => void chooseProjectFile()}>
                <View style={styles.uploadIcon}>
                  <UploadIcon />
                </View>
                <View style={styles.uploadCopy}>
                  <Text style={styles.uploadTitle}>
                    {selectedFile
                      ? selectedFile.name
                      : 'ارفع صورة أو فيديو يوضح عملك'}
                  </Text>
                  <Text style={styles.uploadHint}>
                    {selectedFile ? 'اضغط لتغيير الملف' : 'أظهر ما نفذته بوضوح'}
                  </Text>
                </View>
              </Pressable>
              {project.reportEnabled && (
                <TextInput
                  multiline
                  value={submissionNote}
                  onChangeText={value =>
                    setSubmissionNote(truncateGraphemes(value, 2000))
                  }
                  placeholder="اشرح ما نفذته باختصار"
                  placeholderTextColor="rgba(255,255,255,.38)"
                  style={styles.submissionNoteInput}
                />
              )}
              {submissionDraftSaveError && (
                <Text accessibilityRole="alert" style={styles.draftSaveError}>
                  تعذّر حفظ المسودة على الجهاز
                  {'\n'}اترك الصفحة مفتوحة حتى تسلّم المشروع
                </Text>
              )}
              <Pressable
                accessibilityRole="button"
                disabled={
                  !selectedFile ||
                  (project.reportEnabled && normalizedSubmissionNote.length < 10)
                }
                style={[
                  styles.primaryButton,
                  (!selectedFile ||
                    (project.reportEnabled &&
                      normalizedSubmissionNote.length < 10)) &&
                    styles.disabledButton,
                ]}
                onPress={submit}>
                <Text style={styles.primaryButtonText}>سلّم المشروع</Text>
              </Pressable>
            </View>
          )}
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
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
  submissionNoteInput: {
    ...textDirection,
    minHeight: 64,
    maxHeight: 120,
    borderRadius: 16,
    paddingHorizontal: 13,
    paddingVertical: 11,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    backgroundColor: 'rgba(255,255,255,.045)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.09)',
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
  draftSaveError: {
    ...textDirection,
    color: '#F3A3A3',
    fontFamily: Fonts.medium,
    fontSize: 12,
    lineHeight: 19,
    marginTop: 10,
  },
  feedbackThread: {
    width: '100%',
    marginTop: 18,
    padding: 12,
    borderRadius: 18,
    gap: 8,
    backgroundColor: '#0B111A',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
  feedbackHeader: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 2,
  },
  feedbackTitle: {color: '#FFFFFF', fontFamily: Fonts.bold, fontSize: 14},
  feedbackAvailability: {
    color: '#67D39B',
    fontFamily: Fonts.regular,
    fontSize: 10,
  },
  feedbackBubble: {
    maxWidth: '90%',
    borderRadius: 15,
    paddingHorizontal: 12,
    paddingVertical: 9,
  },
  feedbackBubbleAssistant: {
    alignSelf: 'flex-end',
    backgroundColor: '#17202C',
    borderTopRightRadius: 5,
  },
  feedbackBubbleUser: {
    alignSelf: 'flex-start',
    backgroundColor: '#236FE8',
    borderTopLeftRadius: 5,
  },
  feedbackMessage: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
  },
  feedbackState: {
    ...textDirection,
    color: 'rgba(255,255,255,.58)',
    fontFamily: Fonts.regular,
    fontSize: 10,
  },
  feedbackRetry: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 10,
    marginTop: 4,
  },
  feedbackComposer: {
    ...rtlRowStyle,
    alignItems: 'flex-end',
    gap: 7,
    marginTop: 3,
  },
  feedbackInput: {
    ...textDirection,
    flex: 1,
    minHeight: 44,
    maxHeight: 110,
    borderRadius: 14,
    paddingHorizontal: 12,
    paddingVertical: 10,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 12,
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  feedbackSend: {
    minWidth: 64,
    height: 44,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
  },
  feedbackSendText: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  feedbackError: {
    ...textDirection,
    color: '#FF9A9A',
    fontFamily: Fonts.regular,
    fontSize: 10,
    lineHeight: 16,
  },
});
