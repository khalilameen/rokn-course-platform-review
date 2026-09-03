import {useNavigation} from '@react-navigation/native';
import React, {useEffect, useMemo, useRef, useState} from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {Fonts} from '../../constants/styleConstants';
import {rtlRowStyle, textDirection} from '../../constants/designSystem';
import {openCourseAttachment} from '../VideoPlayer/attachmentActions';
import {submitProjectAttempt} from '../VideoPlayer/courseLearningApi';
import {
  loadProjectFeedbackThread,
  loadProjectResolution,
  watchProjectResolution,
  openProjectInputAttachment,
} from '../VideoPlayer/courseLearningApi';
import {pickProjectFilesOwned} from '../VideoPlayer/ProjectTransition';
import {
  CourseLearningModule,
  CourseProject,
  ProjectFeedbackThread,
  SelectedProjectFile,
} from '../VideoPlayer/types';
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import {
  PROJECT_SUBMISSION_FORMATS_LABEL,
  validateProjectFile,
} from '../../config/projects';
import type {RootNavigation} from '../../navigation/types';
import {
  cacheProjectDraftFile,
  clearProjectSubmissionDraft,
  loadProjectSubmissionDraft,
  saveProjectSubmissionDraft,
} from '../../services/projectSubmissionDraft';
import {removeLearnerDraftFile} from '../../services/learnerDraftFiles';
import {useAppActiveState} from '../../hooks/useAppActiveState';
import {assertAccountSessionBoundary} from '../../constants/helpers';

interface ModuleProps {
  courseId: string;
  module: CourseLearningModule;
  initiallyExpanded?: boolean;
  onProjectPassed?: (projectId: string) => void;
}

const Chevron = ({open}: {open: boolean}) => (
  <Svg width={18} height={18} viewBox="0 0 20 20">
    <Path
      d={open ? 'm4 12 6-6 6 6' : 'm4 8 6 6 6-6'}
      fill="none"
      stroke="rgba(255,255,255,.72)"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const MapProjectCard = ({
  project,
  onPassed,
  locked = false,
}: {
  project: CourseProject;
  onPassed?: (projectId: string) => void;
  locked?: boolean;
}) => {
  const appIsActive = useAppActiveState();
  const [files, setFiles] = useState<SelectedProjectFile[]>([]);
  const [status, setStatus] = useState(project.status);
  const [feedbackThread, setFeedbackThread] = useState<ProjectFeedbackThread | undefined>(
    project.feedbackThread,
  );
  const [note, setNote] = useState('');
  const [draftReady, setDraftReady] = useState(false);
  const [draftSaveError, setDraftSaveError] = useState(false);
  const submitFlightRef = useRef(false);
  const draftGenerationRef = useRef(0);
  const draftSnapshotRef = useRef({files, note});
  draftSnapshotRef.current = {files, note};

  useEffect(() => setStatus(project.status), [project.status]);
  useEffect(() => setFeedbackThread(project.feedbackThread), [project.feedbackThread, project.id]);
  useEffect(() => {
    if (status !== 'reviewing' || !appIsActive) return;
    return watchProjectResolution({
      projectId: project.id,
      resolve: loadProjectResolution,
      isActive: () => appIsActive,
      onResolution: resolution => {
        if (resolution.feedbackThread) setFeedbackThread(resolution.feedbackThread);
        if (resolution.status === 'passed' || resolution.status === 'needs_retry') {
          setStatus(resolution.status);
          if (resolution.status === 'passed') onPassed?.(project.id);
        }
      },
    });
  }, [appIsActive, onPassed, project.id, status]);
  useEffect(() => {
    if (!['passed', 'needs_retry'].includes(status) || !project.reportEnabled) return;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let attempts = 0;
    const refresh = async () => {
      try {
        const next = await loadProjectFeedbackThread(project.id, feedbackThread?.id);
        if (cancelled) return;
        if (next) setFeedbackThread(next);
        const pending = next?.messages.some(message =>
          ['queued', 'sent', 'streaming'].includes(message.status),
        );
        if ((pending || !next) && attempts < 15) {
          attempts += 1;
          timer = setTimeout(() => void refresh(), Math.min(10000, 2200 + attempts * 450));
        }
      } catch {
        if (!cancelled && attempts < 15) {
          attempts += 1;
          timer = setTimeout(() => void refresh(), Math.min(10000, 3000 + attempts * 500));
        }
      }
    };
    void refresh();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [feedbackThread?.id, project.id, project.reportEnabled, status]);
  useEffect(() => {
    const generation = ++draftGenerationRef.current;
    setDraftReady(false);
    setDraftSaveError(false);
    if (project.status === 'passed') {
      setFiles([]);
      setNote('');
      void clearProjectSubmissionDraft(project.id);
      setDraftReady(true);
      return;
    }
    void loadProjectSubmissionDraft(project.id)
      .then(draft => {
        if (generation !== draftGenerationRef.current || !draft) return;
        setFiles(draft.files || (draft.file ? [draft.file] : []));
        setNote(draft.note);
      })
      .catch(() => {
        if (generation === draftGenerationRef.current) {
          setDraftSaveError(true);
        }
      })
      .finally(() => {
        if (generation === draftGenerationRef.current) setDraftReady(true);
      });
    return () => {
      draftGenerationRef.current += 1;
    };
  }, [project.id, project.status]);
  useEffect(() => {
    if (project.status === 'passed' || !draftReady) return;
    const timer = setTimeout(() => {
      void saveProjectSubmissionDraft(project.id, {
        files,
        note,
        updatedAt: Date.now(),
      })
        .then(() => setDraftSaveError(false))
        .catch(() => setDraftSaveError(true));
    }, 250);
    return () => clearTimeout(timer);
  }, [draftReady, files, note, project.id, project.status]);
  useEffect(() => {
    if (appIsActive || project.status === 'passed' || !draftReady) return;
    void saveProjectSubmissionDraft(project.id, {
      ...draftSnapshotRef.current,
      updatedAt: Date.now(),
    }).catch(() => setDraftSaveError(true));
  }, [appIsActive, draftReady, project.id, project.status]);

  const submit = async () => {
    if (locked || submitFlightRef.current) return;
    if (files.length === 0 && note.trim().length < 10) {
      Alert.alert('أضف محاولتك', 'اكتب ما نفذته أو أضف ملفًا يوضحه');
      return;
    }
    submitFlightRef.current = true;
    setStatus('reviewing');
    try {
      const result = await submitProjectAttempt(project.id, files, note.trim());
      // Commit the authoritative outcome to the UI first. Draft cleanup is a
      // local maintenance operation and must not make a successful/idempotent
      // server submission appear failed.
      setFiles([]);
      setNote('');
      if (result.passed && result.canContinue) {
        setStatus('passed');
        onPassed?.(project.id);
      } else if (result.provisional) {
        setStatus('reviewing');
      } else {
        setStatus('needs_retry');
      }
      void clearProjectSubmissionDraft(project.id, files).catch(() => undefined);
    } catch (error: unknown) {
      const copyFailed =
        error instanceof Error && error.message === 'PROJECT_FILE_COPY_FAILED';
      setStatus(copyFailed ? project.status : 'needs_retry');
      Alert.alert(
        copyFailed ? 'تعذّر تجهيز الملف' : 'لم يكتمل التسليم',
        copyFailed
          ? 'اختر الملف مرة أخرى\nوتأكد من وجود مساحة كافية على الجهاز'
          : 'ملفك محفوظ\nتحقق من الاتصال ثم حاول مرة أخرى',
      );
    } finally {
      submitFlightRef.current = false;
    }
  };

  return (
    <View style={styles.projectCard}>
      <View style={styles.projectTopRow}>
        <View style={styles.projectBadge}>
          <Text style={styles.projectBadgeText}>
            {project.isGraduationProject ? 'مشروع التخرج' : 'مشروع العبور'}
          </Text>
        </View>
        {status === 'passed' && (
          <Text style={styles.passedText}>تم العبور ✓</Text>
        )}
      </View>
      <Text style={styles.projectTitle}>{project.title}</Text>
      <Text style={styles.projectRequirements}>{project.requirements}</Text>

      {status === 'needs_retry' && !!feedbackThread?.messages.length && (
        <View style={styles.projectArtifacts}>
          <Text style={styles.projectArtifactLabel}>ملاحظات مشروعك</Text>
          {feedbackThread.messages
            .filter(message => message.status === 'completed')
            .slice(-6)
            .map(message => (
              <View key={message.id} style={styles.projectReportMessage}>
                {!!message.text && (
                  <Text style={styles.projectReportText}>
                    {formatArabicDisplayText(message.text)}
                  </Text>
                )}
                {message.attachments?.map(selected => (
                  <Pressable
                    key={selected.serverId || selected.uploadId}
                    style={styles.selectedProjectFile}
                    onPress={() => void openProjectInputAttachment({
                      projectId: project.id,
                      threadId: feedbackThread.id,
                      file: selected,
                    })}>
                    <Text numberOfLines={1} style={styles.selectedProjectFileName}>
                      {selected.name}
                    </Text>
                  </Pressable>
                ))}
              </View>
            ))}
        </View>
      )}

      {status === 'passed' ? (
        <View style={styles.projectPassedState}>
          <Text style={styles.projectPassedTitle}>تم اعتماد مشروعك</Text>
          <Text style={styles.projectPassedCopy}>
            فتحنا المقطع التالي
          </Text>
          {!!project.submissionAttachments?.length && (
            <View style={styles.projectArtifacts}>
              <Text style={styles.projectArtifactLabel}>ملفات التسليم</Text>
              {project.submissionAttachments.map(selected => (
                <Pressable
                  key={selected.serverId || selected.uploadId}
                  style={styles.selectedProjectFile}
                  onPress={() => void openProjectInputAttachment({
                    projectId: project.id,
                    file: selected,
                  })}>
                  <Text numberOfLines={1} style={styles.selectedProjectFileName}>
                    {selected.name}
                  </Text>
                </Pressable>
              ))}
            </View>
          )}
          {!!feedbackThread?.messages.length && (
            <View style={styles.projectArtifacts}>
              <Text style={styles.projectArtifactLabel}>تقرير مشروعك</Text>
              {feedbackThread.messages
                .filter(message => message.status === 'completed')
                .slice(-6)
                .map(message => (
                  <View key={message.id} style={styles.projectReportMessage}>
                    {!!message.text && (
                      <Text style={styles.projectReportText}>
                        {formatArabicDisplayText(message.text)}
                      </Text>
                    )}
                    {message.attachments?.map(selected => (
                      <Pressable
                        key={selected.serverId || selected.uploadId}
                        style={styles.selectedProjectFile}
                        onPress={() => void openProjectInputAttachment({
                          projectId: project.id,
                          threadId: feedbackThread.id,
                          file: selected,
                        })}>
                        <Text numberOfLines={1} style={styles.selectedProjectFileName}>
                          {selected.name}
                        </Text>
                      </Pressable>
                    ))}
                  </View>
                ))}
            </View>
          )}
        </View>
      ) : status === 'reviewing' ? (
        <View style={styles.projectReviewingState}>
          <View style={styles.inlineReviewLoader}>
            <ActivityIndicator color="#76A9FF" />
          </View>
          <View style={styles.reviewingCopy}>
            <Text style={styles.reviewingTitle}>استلمنا مشروعك</Text>
            <Text style={styles.reviewingText}>نراجعه الآن ومكانك محفوظ</Text>
          </View>
        </View>
      ) : locked ? (
        <View style={styles.projectLockedState}>
          <Text style={styles.projectLockedTitle}>أكمل مقاطع الوحدة أولًا</Text>
          <Text style={styles.projectLockedText}>
            يظهر التسليم بعد آخر مقطع
          </Text>
        </View>
      ) : (
        <>
          <Pressable
            accessibilityRole="button"
            style={styles.filePicker}
            onPress={async () => {
              const cached: SelectedProjectFile[] = [];
              try {
                const {files: picked, ownerBoundary} =
                  await pickProjectFilesOwned(
                    project.submissionAllowedMimeTypes || [],
                  );
                assertAccountSessionBoundary(ownerBoundary);
                if (picked.length) {
                  const maximum = Math.max(1, Math.min(5, project.submissionMaxFiles || 3));
                  const available = picked.slice(0, Math.max(0, maximum - files.length));
                  for (const selected of available) {
                    const size = await validateProjectFile(selected);
                    assertAccountSessionBoundary(ownerBoundary);
                    cached.push(
                      await cacheProjectDraftFile(
                        {...selected, size},
                        ownerBoundary,
                      ),
                    );
                    assertAccountSessionBoundary(ownerBoundary);
                  }
                  setFiles(current => [...current, ...cached].slice(0, maximum));
                }
              } catch (error: unknown) {
                await Promise.all(cached.map(removeLearnerDraftFile));
                const code = error instanceof Error ? error.message : '';
                if (code === 'ACCOUNT_CHANGED_DURING_REQUEST') return;
                Alert.alert(
                  code === 'LEARNER_DRAFT_STORAGE_FULL'
                    ? 'اكتملت مساحة الملفات المعلّقة'
                    : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
                    ? 'صيغة الملف غير مدعومة'
                    : code === 'PROJECT_FILE_TOO_LARGE'
                    ? 'حجم الملف كبير'
                    : 'تعذّر قراءة الملف',
                  code === 'LEARNER_DRAFT_STORAGE_FULL'
                    ? 'اتصل بالإنترنت لإرسال الملفات المعلّقة\nثم حاول مرة أخرى'
                    : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
                    ? `اختر ${PROJECT_SUBMISSION_FORMATS_LABEL}`
                    : 'اختر نسخة أصغر أو حاول مرة أخرى',
                );
              }
            }}>
            <View style={styles.filePickerIcon}>
              <Text style={styles.filePickerSymbol}>＋</Text>
            </View>
            <View style={styles.filePickerCopy}>
              <Text style={styles.filePickerTitle} numberOfLines={2}>
                {files.length ? `${files.length} ملفات` : 'أضف ملفات مشروعك'}
              </Text>
              <Text style={styles.filePickerHint}>
                {files.length
                  ? `يمكنك إضافة ${Math.max(0, (project.submissionMaxFiles || 3) - files.length)}`
                  : 'صور أو PDF أو Word أو PowerPoint'}
              </Text>
            </View>
          </Pressable>
          {files.map((selected, index) => (
            <View key={`${selected.uri}-${index}`} style={styles.selectedProjectFile}>
              <Text numberOfLines={1} style={styles.selectedProjectFileName}>{selected.name}</Text>
              <Pressable onPress={() => {
                setFiles(current => current.filter(candidate => candidate.uri !== selected.uri));
                void removeLearnerDraftFile(selected);
              }}>
                <Text style={styles.selectedProjectFileRemove}>×</Text>
              </Pressable>
            </View>
          ))}
          <TextInput
            multiline
            maxLength={2000}
            value={note}
            onChangeText={setNote}
            placeholder="اكتب ما نفذته أو أضف ملفًا"
            placeholderTextColor="rgba(255,255,255,.38)"
            style={styles.projectNoteInput}
          />
          {draftSaveError && (
            <Text accessibilityRole="alert" style={styles.draftSaveError}>
              تعذّر حفظ المسودة على الجهاز
              {'\n'}اترك الصفحة مفتوحة حتى تسلّم المشروع
            </Text>
          )}
          <Pressable
            accessibilityRole="button"
            disabled={files.length === 0 && note.trim().length < 10}
            style={[
              styles.submitProject,
              (files.length === 0 && note.trim().length < 10) &&
                styles.disabledButton,
            ]}
            onPress={submit}>
            <Text style={styles.submitProjectText}>سلّم المشروع</Text>
          </Pressable>
        </>
      )}
    </View>
  );
};

const Module = ({
  courseId,
  module,
  initiallyExpanded = false,
  onProjectPassed,
}: ModuleProps) => {
  const navigation = useNavigation<RootNavigation>();
  const [expanded, setExpanded] = useState(initiallyExpanded);
  const completed = useMemo(
    () => module.reels.filter(reel => reel.isCompleted).length,
    [module.reels],
  );
  const percentage = Math.round(
    (completed / Math.max(1, module.reels.length)) * 100,
  );
  const projectSubmissionLocked =
    module.isLocked ||
    (module.reels.length > 0 && completed < module.reels.length) ||
    (module.quizzes || []).some(quiz => !quiz.passed);
  const projects = module.projects?.length
    ? module.projects
    : module.project
    ? [module.project]
    : [];

  return (
    <View style={[styles.container, module.isLocked && styles.lockedContainer]}>
      <Pressable
        accessibilityRole="button"
        accessibilityState={{expanded}}
        style={styles.header}
        onPress={() => setExpanded(value => !value)}>
        <View style={styles.moduleOrder}>
          <Text style={styles.moduleOrderText}>
            {formatArabicDisplayText(module.order)}
          </Text>
        </View>
        <View style={styles.headerCopy}>
          <Text style={styles.title}>
            {formatArabicDisplayText(module.title)}
          </Text>
          <Text style={styles.meta}>
            {formatArabicDisplayText(
              `${module.reels.length} مقطع · ${percentage}% مكتمل`,
            )}
          </Text>
        </View>
        <View style={styles.headerActions}>
          {module.isLocked && (
            <View style={styles.lockPill}>
              <Text style={styles.lockPillText}>مغلق</Text>
            </View>
          )}
          <Chevron open={expanded} />
        </View>
      </Pressable>

      <View style={styles.progressTrack}>
        <View style={[styles.progressFill, {width: `${percentage}%`}]} />
      </View>

      {expanded && (
        <View style={styles.content}>
          {module.isLocked && (
            <Text style={styles.lockedHint}>
              {module.lockReason === 'course_purchase_required'
                ? 'اختر فئة الكورس لفتح مقاطعها'
                : module.lockReason === 'passed_quiz_required'
                ? 'اجتز اختبار الوحدة السابقة لفتح مقاطعها'
                : 'أكمل متطلبات الوحدة السابقة لفتح مقاطعها'}
            </Text>
          )}

          {!module.isLocked && !!module.attachments.length && (
            <View style={styles.attachmentsSection}>
              <Text style={styles.sectionLabel}>مرفقات الوحدة</Text>
              {module.attachments.map(attachment => (
                <Pressable
                  key={attachment.id}
                  accessibilityRole="button"
                  style={styles.attachmentRow}
                  onPress={() => void openCourseAttachment(attachment)}>
                  <View style={styles.attachmentCopy}>
                    <Text style={styles.attachmentTitle} numberOfLines={2}>
                      {attachment.title}
                    </Text>
                    <Text style={styles.attachmentMeta}>
                      {attachment.platform === 'computer'
                        ? 'مخصص للكمبيوتر'
                        : 'تحميل مباشر على الهاتف'}
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

          <View style={styles.reelsSection}>
            <Text style={styles.sectionLabel}>مقاطع الوحدة</Text>
            {module.reels.map(reel => {
              const unavailable =
                module.isLocked || reel.isLocked;
              return (
                <Pressable
                  key={reel.id}
                  accessibilityRole="button"
                  accessibilityState={{disabled: unavailable}}
                  disabled={unavailable}
                  style={[styles.reelRow, unavailable && styles.lockedReelRow]}
                  onPress={() =>
                    navigation.navigate('Reels', {
                      courseId,
                      reelId: reel.id,
                    })
                  }>
                  <View
                    style={[
                      styles.reelNumber,
                      reel.isCompleted && styles.completedReelNumber,
                    ]}>
                    <Text style={styles.reelNumberText}>
                      {formatArabicDisplayText(reel.reelNumber)}
                    </Text>
                  </View>
                  <View style={styles.reelCopy}>
                    <Text style={styles.reelTitle} numberOfLines={2}>
                      {formatArabicDisplayText(reel.title)}
                    </Text>
                    <Text style={styles.reelMeta}>
                      {unavailable
                        ? 'تفتح مع تقدّمك'
                        : !reel.videoUrl.trim()
                        ? 'جاهز للتشغيل'
                        : reel.isCompleted
                        ? 'شوهدت'
                        : 'مقطع قصير'}
                    </Text>
                  </View>
                  {unavailable ? (
                    <View style={styles.lockedStepPill}>
                      <Text style={styles.lockedStepText}>مغلق</Text>
                    </View>
                  ) : (
                    <View style={styles.playButton}>
                      <Text style={styles.playText}>▶</Text>
                    </View>
                  )}
                </Pressable>
              );
            })}
          </View>

          {!!module.quizzes?.length && (
            <View style={styles.assessmentsSection}>
              <Text style={styles.sectionLabel}>اختبارات الوحدة</Text>
              {module.quizzes.map((quiz, quizIndex) => {
                const previousPassed = module.quizzes
                  ?.slice(0, quizIndex)
                  .every(item => item.passed);
                const unavailable =
                  module.isLocked ||
                  completed < module.reels.length ||
                  !previousPassed ||
                  quiz.isLocked ||
                  quiz.passed;
                return (
                  <Pressable
                    key={quiz.id}
                    accessibilityRole="button"
                    accessibilityState={{disabled: unavailable}}
                    disabled={unavailable}
                    style={[
                      styles.reelRow,
                      unavailable && !quiz.passed && styles.lockedReelRow,
                    ]}
                    onPress={() => navigation.navigate('Reels', {courseId})}>
                    <View
                      style={[
                        styles.reelNumber,
                        quiz.passed && styles.completedReelNumber,
                      ]}>
                      <Text style={styles.reelNumberText}>؟</Text>
                    </View>
                    <View style={styles.reelCopy}>
                      <Text style={styles.reelTitle} numberOfLines={2}>
                        {formatArabicDisplayText(quiz.title)}
                      </Text>
                      <Text style={styles.reelMeta}>
                        {quiz.passed ? 'تم الاجتياز' : 'يفتح بعد المقاطع'}
                      </Text>
                    </View>
                    {quiz.passed ? (
                      <Text style={styles.passedText}>✓</Text>
                    ) : unavailable ? (
                      <View style={styles.lockedStepPill}>
                        <Text style={styles.lockedStepText}>مغلق</Text>
                      </View>
                    ) : (
                      <View style={styles.playButton}>
                        <Text style={styles.playText}>ابدأ</Text>
                      </View>
                    )}
                  </Pressable>
                );
              })}
            </View>
          )}

          {projects.map(project =>
            module.isLocked || project.isLocked ? (
            <View key={project.id} style={[styles.projectCard, styles.lockedProjectPreview]}>
              <View style={styles.projectTopRow}>
                <View style={styles.projectBadge}>
                  <Text style={styles.projectBadgeText}>
                    {project.isGraduationProject
                      ? 'مشروع التخرج'
                      : 'مشروع العبور'}
                  </Text>
                </View>
                <View style={styles.lockPill}>
                  <Text style={styles.lockPillText}>مغلق</Text>
                </View>
              </View>
              <Text style={styles.projectTitle}>{project.title}</Text>
              <Text style={styles.lockedProjectHint}>
                تظهر التفاصيل عند الوصول إلى هذه الوحدة
              </Text>
            </View>
          ) : (
            <MapProjectCard
              key={project.id}
              project={project}
              onPassed={onProjectPassed}
              locked={projectSubmissionLocked}
            />
          ))}
        </View>
      )}
    </View>
  );
};

export default React.memo(Module);
export {MapProjectCard};

const styles = StyleSheet.create({
  container: {
    direction: 'rtl',
    width: '100%',
    maxWidth: 760,
    alignSelf: 'center',
    borderRadius: 22,
    marginBottom: 13,
    backgroundColor: '#101720',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.075)',
    overflow: 'hidden',
  },
  lockedContainer: {
    opacity: 0.78,
  },
  header: {
    minHeight: 82,
    paddingHorizontal: 15,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 12,
  },
  moduleOrder: {
    width: 42,
    height: 42,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(75,142,247,.14)',
    borderWidth: 1,
    borderColor: 'rgba(91,153,251,.22)',
  },
  moduleOrderText: {
    color: '#8BB6FA',
    fontFamily: Fonts.bold,
    fontSize: 15,
  },
  headerCopy: {
    flex: 1,
    minWidth: 0,
  },
  title: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 15,
    lineHeight: 23,
    ...textDirection,
  },
  meta: {
    color: 'rgba(255,255,255,.47)',
    fontFamily: Fonts.regular,
    fontSize: 10,
    marginTop: 2,
    ...textDirection,
  },
  lockPill: {
    minHeight: 27,
    paddingHorizontal: 10,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  lockPillText: {
    color: 'rgba(255,255,255,.57)',
    fontFamily: Fonts.medium,
    fontSize: 10,
  },
  headerActions: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 9,
    flexShrink: 0,
  },
  progressTrack: {
    height: 2,
    backgroundColor: 'rgba(255,255,255,.07)',
  },
  progressFill: {
    height: '100%',
    backgroundColor: '#4B8EF7',
  },
  lockedHint: {
    color: 'rgba(255,255,255,.48)',
    fontFamily: Fonts.regular,
    fontSize: 11,
    lineHeight: 19,
    marginBottom: 12,
    ...textDirection,
  },
  content: {
    padding: 14,
  },
  sectionLabel: {
    color: 'rgba(255,255,255,.48)',
    fontFamily: Fonts.medium,
    fontSize: 10,
    marginBottom: 8,
    ...textDirection,
  },
  attachmentsSection: {
    marginBottom: 18,
    gap: 7,
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
    color: '#FFFFFF',
    fontFamily: Fonts.medium,
    fontSize: 12,
    ...textDirection,
  },
  attachmentMeta: {
    color: 'rgba(255,255,255,.42)',
    fontFamily: Fonts.regular,
    fontSize: 9,
    marginTop: 2,
    ...textDirection,
  },
  attachmentAction: {
    color: '#76A9FF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  reelsSection: {
    gap: 5,
  },
  assessmentsSection: {
    gap: 5,
    marginTop: 18,
  },
  reelRow: {
    minHeight: 60,
    borderRadius: 15,
    paddingHorizontal: 9,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
    backgroundColor: 'rgba(255,255,255,.025)',
  },
  lockedReelRow: {
    backgroundColor: 'rgba(255,255,255,.018)',
  },
  reelNumber: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  completedReelNumber: {
    backgroundColor: 'rgba(65,192,132,.13)',
  },
  reelNumberText: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  reelCopy: {
    flex: 1,
    minWidth: 0,
  },
  reelTitle: {
    color: 'rgba(255,255,255,.9)',
    fontFamily: Fonts.medium,
    fontSize: 12,
    ...textDirection,
  },
  reelMeta: {
    color: 'rgba(255,255,255,.38)',
    fontFamily: Fonts.regular,
    fontSize: 9,
    marginTop: 2,
    ...textDirection,
  },
  playButton: {
    width: 32,
    height: 32,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.07)',
  },
  playText: {
    color: '#FFFFFF',
    fontSize: 10,
    marginLeft: 2,
  },
  lockedStepPill: {
    minHeight: 28,
    paddingHorizontal: 9,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.055)',
  },
  lockedStepText: {
    color: 'rgba(255,255,255,.52)',
    fontFamily: Fonts.medium,
    fontSize: 9,
  },
  projectCard: {
    direction: 'rtl',
    marginTop: 18,
    borderRadius: 19,
    padding: 16,
    backgroundColor: '#151E29',
    borderWidth: 1,
    borderColor: 'rgba(118,169,255,.16)',
  },
  lockedProjectPreview: {
    borderColor: 'rgba(255,255,255,.08)',
    backgroundColor: 'rgba(255,255,255,.025)',
  },
  lockedProjectHint: {
    ...textDirection,
    color: 'rgba(255,255,255,.42)',
    fontFamily: Fonts.regular,
    fontSize: 10,
    marginTop: 4,
  },
  projectTopRow: {
    ...rtlRowStyle,
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  projectBadge: {
    minHeight: 25,
    paddingHorizontal: 9,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(75,142,247,.14)',
  },
  projectBadgeText: {
    color: '#8BB6FA',
    fontFamily: Fonts.semiBold,
    fontSize: 10,
  },
  passedText: {
    color: '#67D39B',
    fontFamily: Fonts.semiBold,
    fontSize: 10,
  },
  projectTitle: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 17,
    lineHeight: 26,
    marginTop: 10,
    ...textDirection,
  },
  projectRequirements: {
    color: 'rgba(255,255,255,.62)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    marginTop: 5,
    ...textDirection,
  },
  filePicker: {
    minHeight: 66,
    borderRadius: 16,
    padding: 10,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
    marginTop: 14,
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: 'rgba(118,169,255,.32)',
  },
  filePickerIcon: {
    width: 40,
    height: 40,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(35,111,232,.16)',
  },
  filePickerSymbol: {
    color: '#8BB6FA',
    fontFamily: Fonts.light,
    fontSize: 23,
  },
  filePickerCopy: {
    flex: 1,
    minWidth: 0,
  },
  filePickerTitle: {
    color: '#FFFFFF',
    fontFamily: Fonts.medium,
    fontSize: 11,
    ...textDirection,
  },
  filePickerHint: {
    color: 'rgba(255,255,255,.4)',
    fontFamily: Fonts.regular,
    fontSize: 9,
    marginTop: 2,
    ...textDirection,
  },
  selectedProjectFile: {
    minHeight: 38,
    marginTop: 7,
    paddingHorizontal: 11,
    borderRadius: 12,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 8,
    backgroundColor: 'rgba(255,255,255,.045)',
  },
  selectedProjectFileName: {
    flex: 1,
    minWidth: 0,
    color: 'rgba(255,255,255,.78)',
    fontFamily: Fonts.medium,
    fontSize: 10,
    ...textDirection,
  },
  selectedProjectFileRemove: {
    color: 'rgba(255,255,255,.68)',
    fontFamily: Fonts.regular,
    fontSize: 18,
  },
  projectNoteInput: {
    minHeight: 58,
    maxHeight: 110,
    borderRadius: 14,
    paddingHorizontal: 12,
    paddingVertical: 9,
    marginTop: 9,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 11,
    lineHeight: 18,
    backgroundColor: 'rgba(255,255,255,.045)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
    ...textDirection,
  },
  draftSaveError: {
    color: '#F3A3A3',
    fontFamily: Fonts.medium,
    fontSize: 10,
    lineHeight: 16,
    marginTop: 8,
    ...textDirection,
  },
  submitProject: {
    minHeight: 48,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
    marginTop: 10,
  },
  disabledButton: {
    opacity: 0.38,
  },
  submitProjectText: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 12,
  },
  projectReviewingState: {
    minHeight: 72,
    borderRadius: 15,
    padding: 12,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 12,
    backgroundColor: 'rgba(75,142,247,.08)',
    marginTop: 14,
  },
  inlineReviewLoader: {
    width: 42,
    height: 42,
    borderRadius: 21,
    alignItems: 'center',
    justifyContent: 'center',
    flexShrink: 0,
    backgroundColor: 'rgba(52,120,246,.10)',
    borderWidth: 1,
    borderColor: 'rgba(118,169,255,.22)',
  },
  projectLockedState: {
    minHeight: 72,
    borderRadius: 15,
    padding: 12,
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.045)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.075)',
    marginTop: 14,
  },
  projectLockedTitle: {
    color: 'rgba(255,255,255,.78)',
    fontFamily: Fonts.semiBold,
    fontSize: 12,
    ...textDirection,
  },
  projectLockedText: {
    color: 'rgba(255,255,255,.42)',
    fontFamily: Fonts.regular,
    fontSize: 9,
    marginTop: 2,
    ...textDirection,
  },
  reviewingCopy: {
    flex: 1,
  },
  reviewingTitle: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 12,
    ...textDirection,
  },
  reviewingText: {
    color: 'rgba(255,255,255,.45)',
    fontFamily: Fonts.regular,
    fontSize: 9,
    marginTop: 2,
    ...textDirection,
  },
  projectPassedState: {
    minHeight: 72,
    borderRadius: 15,
    padding: 12,
    justifyContent: 'center',
    backgroundColor: 'rgba(65,192,132,.08)',
    marginTop: 14,
  },
  projectPassedTitle: {
    color: '#67D39B',
    fontFamily: Fonts.semiBold,
    fontSize: 12,
    ...textDirection,
  },
  projectPassedCopy: {
    color: 'rgba(255,255,255,.45)',
    fontFamily: Fonts.regular,
    fontSize: 9,
    marginTop: 2,
    ...textDirection,
  },
  projectArtifacts: {
    width: '100%',
    marginTop: 10,
    gap: 6,
  },
  projectArtifactLabel: {
    color: 'rgba(255,255,255,.56)',
    fontFamily: Fonts.medium,
    fontSize: 10,
    ...textDirection,
  },
  projectReportText: {
    color: 'rgba(255,255,255,.82)',
    fontFamily: Fonts.regular,
    fontSize: 11,
    lineHeight: 18,
    ...textDirection,
  },
  projectReportMessage: {
    gap: 5,
  },
});
