import {useNavigation} from '@react-navigation/native';
import React, {useEffect, useMemo, useState} from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {Fonts} from '../../constants/styleConstants';
import {rtlRowStyle, textDirection} from '../../constants/designSystem';
import {openCourseAttachment} from '../VideoPlayer/attachmentActions';
import {submitProjectAttempt} from '../VideoPlayer/courseLearningApi';
import {pickMedia} from '../VideoPlayer/ProjectTransition';
import {
  CourseLearningModule,
  CourseProject,
  SelectedProjectFile,
} from '../VideoPlayer/types';
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import {
  PROJECT_SUBMISSION_FORMATS_LABEL,
  validateProjectFile,
} from '../../config/projects';
import type {RootNavigation} from '../../navigation/types';

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
  const [file, setFile] = useState<SelectedProjectFile | null>(null);
  const [status, setStatus] = useState(project.status);

  useEffect(() => setStatus(project.status), [project.status]);

  const submit = async () => {
    if (locked) return;
    if (!file) {
      Alert.alert('اختار اللي هترفعه', 'صورة أو فيديو واضح لشغلك كفاية.');
      return;
    }
    setStatus('reviewing');
    try {
      const startedAt = Date.now();
      const result = await submitProjectAttempt(project.id, file);
      const elapsed = Date.now() - startedAt;
      if (elapsed < 1200) {
        await new Promise<void>(resolve =>
          setTimeout(() => resolve(), 1200 - elapsed),
        );
      }
      if (result.passed && result.canContinue) {
        setStatus('passed');
        onPassed?.(project.id);
      } else if (result.provisional) {
        setStatus('reviewing');
      } else {
        setStatus('needs_retry');
      }
    } catch {
      setStatus('needs_retry');
      Alert.alert(
        'التسليم ما اكتملش',
        'ملفك لسه عندك. تأكد من الاتصال واضغط تسليم مرة ثانية.',
      );
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

      {status === 'passed' ? (
        <View style={styles.projectPassedState}>
          <Text style={styles.projectPassedTitle}>مشروعك عدى</Text>
          <Text style={styles.projectPassedCopy}>
            فتحنا لك الخطوة اللي بعدها
          </Text>
        </View>
      ) : status === 'reviewing' ? (
        <View style={styles.projectReviewingState}>
          <View style={styles.inlineReviewLoader}>
            <ActivityIndicator color="#76A9FF" />
          </View>
          <View style={styles.reviewingCopy}>
            <Text style={styles.reviewingTitle}>مشروعك عندنا</Text>
            <Text style={styles.reviewingText}>بنراجعه ومكانك محفوظ</Text>
          </View>
        </View>
      ) : locked ? (
        <View style={styles.projectLockedState}>
          <Text style={styles.projectLockedTitle}>كمّل خطوات الوحدة الأول</Text>
          <Text style={styles.projectLockedText}>
            بعد آخر خطوة هتلاقي التسليم هنا
          </Text>
        </View>
      ) : (
        <>
          <Pressable
            accessibilityRole="button"
            style={styles.filePicker}
            onPress={async () => {
              const picked = await pickMedia();
              if (picked) {
                try {
                  const size = await validateProjectFile(picked);
                  setFile({...picked, size});
                } catch (error: unknown) {
                  const code = error instanceof Error ? error.message : '';
                  Alert.alert(
                    code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
                      ? 'صيغة الملف غير مدعومة'
                      : code === 'PROJECT_FILE_TOO_LARGE'
                      ? 'حجم الملف كبير'
                      : 'تعذّر قراءة الملف',
                    code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
                      ? `اختار ${PROJECT_SUBMISSION_FORMATS_LABEL}.`
                      : 'اختار نسخة أصغر أو جرّب الملف مرة أخرى.',
                  );
                }
              }
            }}>
            <View style={styles.filePickerIcon}>
              <Text style={styles.filePickerSymbol}>＋</Text>
            </View>
            <View style={styles.filePickerCopy}>
              <Text style={styles.filePickerTitle} numberOfLines={1}>
                {file?.name || 'ارفع صورة أو فيديو يوضح شغلك'}
              </Text>
              <Text style={styles.filePickerHint}>
                {file
                  ? 'تقدر تغيّر الملف قبل التسليم'
                  : 'المهم يكون واضح إنك جرّبت ونفذت'}
              </Text>
            </View>
          </Pressable>
          <Pressable
            accessibilityRole="button"
            disabled={!file}
            style={[styles.submitProject, !file && styles.disabledButton]}
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
    (module.reels.length > 0 && completed < module.reels.length);

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
              `${module.reels.length} خطوة · ${percentage}% مكتمل`,
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
              كمّل مشروع الوحدة اللي قبلها عشان تفتح خطواتها.
            </Text>
          )}

          {!module.isLocked && !!module.description && (
            <Text style={styles.description}>{module.description}</Text>
          )}

          {!module.isLocked && !!module.attachments.length && (
            <View style={styles.attachmentsSection}>
              <Text style={styles.sectionLabel}>مرفقات الوحدة</Text>
              {module.attachments.map(attachment => (
                <Pressable
                  key={attachment.id}
                  accessibilityRole="button"
                  style={styles.attachmentRow}
                  onPress={() =>
                    openCourseAttachment(attachment).catch(() => undefined)
                  }>
                  <View style={styles.attachmentCopy}>
                    <Text style={styles.attachmentTitle} numberOfLines={1}>
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
            <Text style={styles.sectionLabel}>خطوات الوحدة</Text>
            {module.reels.map(reel => {
              const unavailable =
                module.isLocked || reel.isLocked || !reel.videoUrl.trim();
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
                    <Text style={styles.reelTitle} numberOfLines={1}>
                      {formatArabicDisplayText(reel.title)}
                    </Text>
                    <Text style={styles.reelMeta}>
                      {unavailable
                        ? 'تفتح مع تقدّمك'
                        : reel.isCompleted
                        ? 'شوهدت'
                        : 'خطوة قصيرة'}
                    </Text>
                  </View>
                  {unavailable ? (
                    <View style={styles.lockedStepPill}>
                      <Text style={styles.lockedStepText}>مقفولة</Text>
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

          {module.project && module.isLocked ? (
            <View style={[styles.projectCard, styles.lockedProjectPreview]}>
              <View style={styles.projectTopRow}>
                <View style={styles.projectBadge}>
                  <Text style={styles.projectBadgeText}>
                    {module.project.isGraduationProject
                      ? 'مشروع التخرج'
                      : 'مشروع العبور'}
                  </Text>
                </View>
                <View style={styles.lockPill}>
                  <Text style={styles.lockPillText}>مغلق</Text>
                </View>
              </View>
              <Text style={styles.projectTitle}>{module.project.title}</Text>
              <Text style={styles.lockedProjectHint}>
                تفاصيله تفتح لما توصل للوحدة دي
              </Text>
            </View>
          ) : module.project ? (
            <MapProjectCard
              project={module.project}
              onPassed={onProjectPassed}
              locked={projectSubmissionLocked}
            />
          ) : null}
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
  description: {
    color: 'rgba(255,255,255,.6)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    marginBottom: 14,
    ...textDirection,
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
  submitProject: {
    minHeight: 46,
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
});
