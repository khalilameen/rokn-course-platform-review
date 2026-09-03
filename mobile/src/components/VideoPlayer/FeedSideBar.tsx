import {
  BottomSheetBackdrop,
  type BottomSheetBackdropProps,
  BottomSheetModal,
  BottomSheetScrollView,
} from '@gorhom/bottom-sheet';
import React, {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../../constants/arabicFormatting';
import {
  Accessibility,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {Fonts} from '../../constants/styleConstants';
import {
  createSavedFolderOption,
  getSavedFolderOptions,
  SavedFolderOption,
} from './courseLearningApi';
import {CourseLearningData, CourseLearningModule, CourseReel} from './types';
import {openCourseAttachment} from './attachmentActions';
import {
  hasSeenAttachmentPrompt,
  markAttachmentPromptSeen,
} from './attachmentPrompt';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {useReducedMotion} from '../../hooks/useReducedMotion';

interface FeedSideBarProps {
  course: CourseLearningData;
  currentReel: CourseReel;
  currentFeedKey: string;
  isSaved: boolean;
  savePending: boolean;
  bottomInset?: number;
  onToggleSave: (folder?: SavedFolderOption | null) => void;
  onBeforeOpenSave: () => boolean;
  onOpenChat: () => void;
  onOverlayVisibilityChange?: (visible: boolean) => void;
  onSelectFeedItem: (key: string) => void;
  currentTime: number;
}

const ChatIcon = () => (
  <Svg width={28} height={28} viewBox="0 0 28 28">
    <Path
      d="M5.1 5.5h17.8c1.3 0 2.3 1 2.3 2.3v10c0 1.3-1 2.3-2.3 2.3h-8L8 24.5v-4.4H5.1c-1.3 0-2.3-1-2.3-2.3v-10c0-1.3 1-2.3 2.3-2.3Z"
      fill="none"
      stroke="#fff"
      strokeWidth={1.8}
      strokeLinejoin="round"
    />
    <Path
      d="M8.5 12.9h.1m5.3 0h.1m5.3 0h.1"
      stroke="#fff"
      strokeWidth={2.6}
      strokeLinecap="round"
    />
  </Svg>
);

const BookmarkIcon = ({filled}: {filled: boolean}) => (
  <Svg width={28} height={28} viewBox="0 0 28 28">
    <Path
      d="M7 5.2c0-1 .8-1.8 1.8-1.8h10.4c1 0 1.8.8 1.8 1.8v19.4l-7-4.3-7 4.3V5.2Z"
      fill={filled ? '#4B8EF7' : 'rgba(0,0,0,0)'}
      stroke="#fff"
      strokeWidth={1.8}
      strokeLinejoin="round"
    />
  </Svg>
);

const AttachmentIcon = () => (
  <Svg width={27} height={27} viewBox="0 0 28 28">
    <Path
      d="m10.1 14.8 7.7-7.7a4 4 0 0 1 5.7 5.7L13.2 23.1a6 6 0 0 1-8.5-8.5L15.3 4"
      fill="none"
      stroke="#fff"
      strokeWidth={1.9}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const LockIcon = () => (
  <Svg width={15} height={15} viewBox="0 0 18 18">
    <Path
      d="M5.2 8V5.8a3.8 3.8 0 0 1 7.6 0V8m-8.5 0h9.4c.7 0 1.3.6 1.3 1.3v5c0 .8-.6 1.4-1.3 1.4H4.3c-.7 0-1.3-.6-1.3-1.3v-5C3 8.6 3.6 8 4.3 8Z"
      fill="none"
      stroke="rgba(255,255,255,.52)"
      strokeWidth={1.4}
      strokeLinecap="round"
    />
  </Svg>
);

const IndexChevron = ({open}: {open: boolean}) => (
  <Svg width={16} height={16} viewBox="0 0 20 20">
    <Path
      d={open ? 'm4 12 6-6 6 6' : 'm4 8 6 6 6-6'}
      fill="none"
      stroke="rgba(255,255,255,.62)"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const ActionButton = ({
  label,
  onPress,
  children,
  active,
  compact,
  disabled,
}: {
  label: string;
  onPress: () => void;
  children: React.ReactNode;
  active?: boolean;
  compact?: boolean;
  disabled?: boolean;
}) => (
  <Pressable
    accessibilityRole="button"
    accessibilityLabel={label}
    accessibilityState={{disabled, busy: disabled}}
    disabled={disabled}
    hitSlop={7}
    style={[
      styles.action,
      compact && styles.actionCompact,
      disabled && styles.actionDisabled,
    ]}
    onPress={onPress}>
    <View
      style={[
        styles.actionIcon,
        compact && styles.actionIconCompact,
        active && styles.actionIconActive,
      ]}>
      {children}
    </View>
    <Text
      maxFontSizeMultiplier={1.35}
      numberOfLines={1}
      style={styles.actionLabel}>
      {label}
    </Text>
  </Pressable>
);

const CourseIndexModule = ({
  module,
  currentFeedKey,
  onSelect,
}: {
  module: CourseLearningModule;
  currentFeedKey: string;
  onSelect: (key: string) => void;
}) => {
  const [expanded, setExpanded] = useState(!module.isLocked);
  const lastReel = module.reels[module.reels.length - 1];
  const quizzes = module.quizzes || [];
  const projects = module.projects?.length
    ? module.projects
    : module.project
    ? [module.project]
    : [];

  return (
    <View style={[styles.moduleCard, module.isLocked && styles.lockedModule]}>
      <Pressable
        accessibilityRole="button"
        accessibilityState={{expanded}}
        onPress={() => setExpanded(value => !value)}
        style={styles.moduleHeader}>
        <View style={styles.moduleHeading}>
          <Text style={styles.moduleTitle}>
            {formatArabicDisplayText(module.title)}
          </Text>
          <Text style={styles.moduleMeta}>
            {formatArabicNumber(module.reels.length)} مقطع
          </Text>
        </View>
        <View style={styles.moduleHeaderActions}>
          {module.isLocked && <LockIcon />}
          <IndexChevron open={expanded} />
        </View>
      </Pressable>
      {expanded && (
        <View style={styles.reelsList}>
          {module.reels.map(reel => {
            const key = `reel-${reel.id}`;
            const unavailable =
              module.isLocked || reel.isLocked;
            const active = !unavailable && currentFeedKey === key;
            return (
              <Pressable
                key={key}
                accessibilityRole="button"
                accessibilityState={{disabled: unavailable}}
                disabled={unavailable}
                style={[
                  styles.reelRow,
                  unavailable && styles.lockedReelRow,
                  active && styles.activeReelRow,
                ]}
                onPress={() => onSelect(key)}>
                <View
                  style={[
                    styles.reelNumber,
                    active && styles.activeReelNumber,
                  ]}>
                  <Text style={styles.reelNumberText}>
                    {formatArabicNumber(reel.reelNumber)}
                  </Text>
                </View>
                <Text style={styles.reelTitle} numberOfLines={1}>
                  {formatArabicDisplayText(reel.title)}
                </Text>
                {reel.isCompleted && (
                  <Text style={styles.completedMark}>✓</Text>
                )}
                {unavailable && <LockIcon />}
              </Pressable>
            );
          })}
          {quizzes.map((quiz, quizIndex) => {
            const key = `quiz-${quiz.id}`;
            const priorQuizzesPassed = quizzes
              .slice(0, quizIndex)
              .every(item => item.passed);
            const unavailable =
              module.isLocked ||
              Boolean(lastReel && !lastReel.isCompleted) ||
              !priorQuizzesPassed ||
              quiz.isLocked;
            const active = !unavailable && currentFeedKey === key;

            return (
              <Pressable
                key={key}
                accessibilityRole="button"
                accessibilityState={{disabled: unavailable}}
                disabled={unavailable}
                style={[
                  styles.projectRow,
                  unavailable && styles.projectRowDisabled,
                  active && styles.activeReelRow,
                ]}
                onPress={() => onSelect(key)}>
                <View style={styles.projectGlyph}>
                  <Text style={styles.projectGlyphText}>؟</Text>
                </View>
                <View style={styles.projectCopy}>
                  <Text style={styles.projectTitle} numberOfLines={1}>
                    {formatArabicDisplayText(quiz.title || 'اختبار الوحدة')}
                  </Text>
                  <Text style={styles.projectStatus}>
                    {quiz.passed
                      ? 'تم الاجتياز'
                      : unavailable
                      ? 'يفتح بعد إكمال ما قبله'
                      : 'ابدأ الاختبار'}
                  </Text>
                </View>
                {quiz.passed && <Text style={styles.completedMark}>✓</Text>}
                {unavailable && <LockIcon />}
              </Pressable>
            );
          })}
          {projects.map(project => {
            const projectUnavailable =
              module.isLocked ||
              project.isLocked ||
              Boolean(lastReel && !lastReel.isCompleted) ||
              quizzes.some(quiz => !quiz.passed);
            return (
            <Pressable
              key={project.id}
              accessibilityRole="button"
              accessibilityState={{disabled: projectUnavailable}}
              disabled={projectUnavailable}
              style={[
                styles.projectRow,
                projectUnavailable && styles.projectRowDisabled,
                currentFeedKey === `project-${project.id}` &&
                  styles.activeReelRow,
              ]}
              onPress={() => onSelect(`project-${project.id}`)}>
              <View style={styles.projectGlyph}>
                <Text style={styles.projectGlyphText}>◆</Text>
              </View>
              <View style={styles.projectCopy}>
                <Text style={styles.projectTitle} numberOfLines={1}>
                  {formatArabicDisplayText(project.title)}
                </Text>
                <Text style={styles.projectStatus}>
                  {module.isLocked
                    ? 'يفتح بعد عبور الوحدة السابقة'
                    : project.status === 'passed'
                    ? 'تم العبور'
                    : project.status === 'reviewing'
                    ? 'قيد المراجعة'
                    : 'بعد آخر مقطع في الوحدة'}
                </Text>
              </View>
              {projectUnavailable && <LockIcon />}
            </Pressable>
            );
          })}
        </View>
      )}
    </View>
  );
};

const FeedSideBar = ({
  course,
  currentReel,
  currentFeedKey,
  isSaved,
  savePending,
  bottomInset = 0,
  onToggleSave,
  onBeforeOpenSave,
  onOpenChat,
  onOverlayVisibilityChange,
  onSelectFeedItem,
  currentTime,
}: FeedSideBarProps) => {
  const {height, fontScale} = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const compact = height < 620 || fontScale > 1.25;
  const indexSheetRef = useRef<BottomSheetModal>(null);
  const saveSheetRef = useRef<BottomSheetModal>(null);
  const attachmentSheetRef = useRef<BottomSheetModal>(null);
  const attachmentPromptCheckRef = useRef('');
  const openSheetsRef = useRef(new Set<'index' | 'save' | 'attachment'>());
  const folderLoadGenerationRef = useRef(0);
  const folderLoadInFlightRef = useRef(false);
  const mountedRef = useRef(true);
  const snapPoints = useMemo(() => ['78%', '94%'], []);
  const saveSnapPoints = useMemo(() => ['52%', '72%'], []);
  const attachmentSnapPoints = useMemo(() => ['48%', '72%'], []);
  const [folders, setFolders] = useState<SavedFolderOption[]>([]);
  const [foldersLoading, setFoldersLoading] = useState(false);
  const [newFolderName, setNewFolderName] = useState('');
  const [folderBusy, setFolderBusy] = useState(false);
  const [folderError, setFolderError] = useState('');

  const reportSheetState = useCallback(
    (sheet: 'index' | 'save' | 'attachment', visible: boolean) => {
      if (visible) {
        openSheetsRef.current.add(sheet);
      } else {
        openSheetsRef.current.delete(sheet);
      }
      onOverlayVisibilityChange?.(openSheetsRef.current.size > 0);
    },
    [onOverlayVisibilityChange],
  );

  useEffect(() => {
    const openSheets = openSheetsRef.current;
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      folderLoadGenerationRef.current += 1;
      folderLoadInFlightRef.current = false;
      openSheets.clear();
      onOverlayVisibilityChange?.(false);
    };
  }, [onOverlayVisibilityChange]);
  const completed = course.modules.reduce(
    (total, module) =>
      total + module.reels.filter(reel => reel.isCompleted).length,
    0,
  );
  const currentModule = course.modules.find(
    module => module.id === currentReel.moduleId,
  );
  const attachments = currentModule?.attachments || [];
  const attachmentPromptScope =
    course.attachmentPrompt?.frequency === 'once_per_course'
      ? 'course'
      : currentModule?.id;

  const openAttachments = useCallback(() => {
    if (!currentModule || !attachmentPromptScope || !attachments.length) return;
    void markAttachmentPromptSeen(course.id, attachmentPromptScope).catch(
      () => undefined,
    );
    attachmentSheetRef.current?.present();
  }, [attachmentPromptScope, attachments.length, course.id, currentModule]);

  useEffect(() => {
    const prompt = course.attachmentPrompt;
    if (
      !prompt?.enabled ||
      !currentModule ||
      !attachmentPromptScope ||
      !attachments.length ||
      currentTime < prompt.atSeconds
    ) {
      return;
    }

    const checkId = `${course.id}:${attachmentPromptScope}`;
    if (attachmentPromptCheckRef.current === checkId) return;
    attachmentPromptCheckRef.current = checkId;
    let cancelled = false;
    void hasSeenAttachmentPrompt(course.id, attachmentPromptScope)
      .then(seen => {
        if (seen || cancelled) return;
        attachmentSheetRef.current?.present();
        void markAttachmentPromptSeen(course.id, attachmentPromptScope).catch(
          () => undefined,
        );
      })
      .catch(() => {
        // Storage unavailability must not interrupt playback.
      });

    return () => {
      cancelled = true;
    };
  }, [
    attachments.length,
    attachmentPromptScope,
    course.attachmentPrompt,
    course.id,
    currentModule,
    currentTime,
  ]);

  const renderBackdrop = useCallback(
    (props: BottomSheetBackdropProps) => (
      <BottomSheetBackdrop
        {...props}
        appearsOnIndex={0}
        disappearsOnIndex={-1}
        opacity={0.55}
        pressBehavior="close"
      />
    ),
    [],
  );

  const handleSelect = (key: string) => {
    onSelectFeedItem(key);
    indexSheetRef.current?.dismiss();
  };

  const openSaveSheet = () => {
    if (!onBeforeOpenSave()) {
      return;
    }
    saveSheetRef.current?.present();
    if (folderLoadInFlightRef.current) return;
    folderLoadInFlightRef.current = true;
    const generation = ++folderLoadGenerationRef.current;
    setFoldersLoading(true);
    setFolderError('');
    getSavedFolderOptions()
      .then(nextFolders => {
        if (
          mountedRef.current &&
          generation === folderLoadGenerationRef.current
        ) {
          setFolders(nextFolders);
        }
      })
      .catch(() => {
        if (
          !mountedRef.current ||
          generation !== folderLoadGenerationRef.current
        ) {
          return;
        }
        setFolders([]);
        setFolderError('تعذّر تحميل قوائمك الآن\nحاول مرة أخرى');
      })
      .finally(() => {
        if (generation === folderLoadGenerationRef.current) {
          folderLoadInFlightRef.current = false;
        }
        if (
          mountedRef.current &&
          generation === folderLoadGenerationRef.current
        ) {
          setFoldersLoading(false);
        }
      });
  };

  const saveInFolder = (folder: SavedFolderOption) => {
    onToggleSave(folder);
    saveSheetRef.current?.dismiss();
  };

  const createAndSave = async () => {
    if (!newFolderName.trim() || folderBusy) return;
    setFolderBusy(true);
    setFolderError('');
    try {
      const created = await createSavedFolderOption(newFolderName);
      if (!mountedRef.current) return;
      setFolders(current => [...current, created]);
      setNewFolderName('');
      saveInFolder(created);
    } catch {
      if (mountedRef.current) {
        setFolderError('تعذّر إنشاء القائمة\nتحقق من الاتصال ثم حاول مرة أخرى');
      }
    } finally {
      if (mountedRef.current) setFolderBusy(false);
    }
  };

  return (
    <>
      <View
        style={[
          styles.container,
          compact && styles.containerCompact,
          {bottom: (compact ? 42 : 56) + bottomInset},
        ]}>
        <ActionButton label="اسأل" compact={compact} onPress={onOpenChat}>
          <ChatIcon />
        </ActionButton>
        <ActionButton
          label={savePending ? 'جارٍ الحفظ' : isSaved ? 'محفوظ' : 'احفظ'}
          active={isSaved}
          compact={compact}
          disabled={savePending}
          onPress={openSaveSheet}>
          {savePending ? (
            <ActivityIndicator color="#FFFFFF" size="small" />
          ) : (
            <BookmarkIcon filled={isSaved} />
          )}
        </ActionButton>
        {!!attachments.length && (
          <ActionButton
            label="ملفات"
            compact={compact}
            onPress={openAttachments}>
            <AttachmentIcon />
          </ActionButton>
        )}
        <ActionButton
          label="الفهرس"
          compact={compact}
          onPress={() => indexSheetRef.current?.present()}>
          <Text style={styles.counter} maxFontSizeMultiplier={1.1}>
            {formatArabicNumber(currentReel.reelNumber)}/
            {formatArabicNumber(course.totalReels)}
          </Text>
        </ActionButton>
      </View>

      <BottomSheetModal
        ref={indexSheetRef}
        snapPoints={snapPoints}
        animateOnMount={!reducedMotion}
        bottomInset={bottomInset}
        enableDynamicSizing={false}
        enablePanDownToClose
        topInset={insets.top}
        backdropComponent={renderBackdrop}
        onChange={index => reportSheetState('index', index >= 0)}
        onDismiss={() => reportSheetState('index', false)}
        backgroundStyle={styles.sheetBackground}
        handleIndicatorStyle={styles.sheetIndicator}>
        <BottomSheetScrollView
          accessibilityViewIsModal
          showsVerticalScrollIndicator={false}
          contentContainerStyle={styles.sheetContent}>
          <View style={styles.sheetHeader}>
            <View style={styles.sheetHeaderCopy}>
              <Text style={styles.sheetEyebrow}>فهرس الكورس</Text>
              <Text accessibilityRole="header" style={styles.sheetTitle}>
                {course.title}
              </Text>
            </View>
            <View style={styles.progressPill}>
              <Text style={styles.progressText}>
                {formatArabicNumber(completed)}/
                {formatArabicNumber(course.totalReels)}
              </Text>
            </View>
          </View>
          <View style={styles.progressTrack}>
            <View
              style={[
                styles.progressFill,
                {
                  width: `${Math.min(
                    100,
                    (completed / Math.max(1, course.totalReels)) * 100,
                  )}%`,
                },
              ]}
            />
          </View>

          {course.modules.map(module => (
            <CourseIndexModule
              key={module.id}
              module={module}
              currentFeedKey={currentFeedKey}
              onSelect={handleSelect}
            />
          ))}
        </BottomSheetScrollView>
      </BottomSheetModal>

      <BottomSheetModal
        ref={attachmentSheetRef}
        snapPoints={attachmentSnapPoints}
        animateOnMount={!reducedMotion}
        bottomInset={bottomInset}
        enableDynamicSizing={false}
        enablePanDownToClose
        topInset={insets.top}
        backdropComponent={renderBackdrop}
        onChange={index => reportSheetState('attachment', index >= 0)}
        onDismiss={() => reportSheetState('attachment', false)}
        backgroundStyle={styles.sheetBackground}
        handleIndicatorStyle={styles.sheetIndicator}>
        <BottomSheetScrollView
          accessibilityViewIsModal
          showsVerticalScrollIndicator={false}
          contentContainerStyle={styles.attachmentSheetContent}>
          <Text style={styles.sheetEyebrow}>ملفات الوحدة</Text>
          <Text accessibilityRole="header" style={styles.attachmentTitle}>
            {course.attachmentPrompt?.title || 'مرفقات تساعدك في التطبيق'}
          </Text>
          <Text style={styles.attachmentBody}>
            {course.attachmentPrompt?.body ||
              'حمّل الملفات واستخدمها مع محتوى هذه الوحدة'}
          </Text>
          <View style={styles.attachmentList}>
            {attachments.map(attachment => (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={`تنزيل ${attachment.title}`}
                key={attachment.id}
                onPress={() => void openCourseAttachment(attachment)}
                style={({pressed}) => [
                  styles.attachmentRow,
                  pressed && styles.pressed,
                ]}>
                <View style={styles.attachmentGlyph}>
                  <AttachmentIcon />
                </View>
                <View style={styles.attachmentCopy}>
                  <Text style={styles.attachmentName}>{attachment.title}</Text>
                  <Text style={styles.attachmentMeta}>
                    {attachment.platform === 'computer'
                      ? 'يُفتح من الكمبيوتر'
                      : attachment.fileSize ||
                        attachment.fileType ||
                        'ملف مرفق'}
                  </Text>
                </View>
                <Text style={styles.attachmentAction}>
                  {course.attachmentPrompt?.buttonText || 'تحميل'}
                </Text>
              </Pressable>
            ))}
          </View>
        </BottomSheetScrollView>
      </BottomSheetModal>

      <BottomSheetModal
        ref={saveSheetRef}
        snapPoints={saveSnapPoints}
        android_keyboardInputMode="adjustResize"
        animateOnMount={!reducedMotion}
        bottomInset={bottomInset}
        enableBlurKeyboardOnGesture
        enableDynamicSizing={false}
        enablePanDownToClose
        keyboardBehavior="interactive"
        keyboardBlurBehavior="restore"
        topInset={insets.top}
        backdropComponent={renderBackdrop}
        onChange={index => reportSheetState('save', index >= 0)}
        onDismiss={() => reportSheetState('save', false)}
        backgroundStyle={styles.sheetBackground}
        handleIndicatorStyle={styles.sheetIndicator}>
        <BottomSheetScrollView
          accessibilityViewIsModal
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
          contentContainerStyle={styles.saveSheetContent}>
          <Text style={styles.sheetEyebrow}>المحفوظات</Text>
          <Text accessibilityRole="header" style={styles.saveSheetTitle}>
            أين تريد حفظ المقطع
          </Text>
          {foldersLoading ? (
            <ActivityIndicator color="#76A9FF" style={styles.folderLoader} />
          ) : (
            <View style={styles.folderList}>
              {folders.map(folder => (
                <Pressable
                  accessibilityRole="button"
                  key={folder.id}
                  accessibilityState={{disabled: savePending}}
                  disabled={savePending}
                  onPress={() => saveInFolder(folder)}
                  style={({pressed}) => [
                    styles.folderRow,
                    pressed && styles.pressed,
                  ]}>
                  <Text style={styles.folderRowText}>{folder.name}</Text>
                  <Text style={styles.folderRowAction}>
                    {isSaved ? 'إضافة' : 'حفظ'}
                  </Text>
                </Pressable>
              ))}
            </View>
          )}
          {!!folderError && (
            <Text accessibilityRole="alert" style={styles.folderError}>
              {folderError}
            </Text>
          )}
          <View style={styles.newFolderRow}>
            <TextInput
              accessibilityLabel="اسم القائمة الجديدة"
              maxLength={60}
              onChangeText={setNewFolderName}
              onSubmitEditing={() => void createAndSave()}
              placeholder="اسم قائمة جديدة"
              placeholderTextColor="rgba(255,255,255,.38)"
              returnKeyType="done"
              style={styles.folderInput}
              value={newFolderName}
            />
            <Pressable
              accessibilityRole="button"
              accessibilityState={{
                busy: folderBusy,
                disabled: savePending || !newFolderName.trim() || folderBusy,
              }}
              disabled={savePending || !newFolderName.trim() || folderBusy}
              onPress={() => void createAndSave()}
              style={({pressed}) => [
                styles.createFolderButton,
                (!newFolderName.trim() || folderBusy) &&
                  styles.createFolderButtonDisabled,
                pressed && styles.pressed,
              ]}>
              {folderBusy ? (
                <ActivityIndicator color="#FFFFFF" size="small" />
              ) : (
                <Text style={styles.createFolderButtonText}>إنشاء وحفظ</Text>
              )}
            </Pressable>
          </View>
          {isSaved && (
            <Pressable
              accessibilityRole="button"
              accessibilityState={{disabled: savePending}}
              disabled={savePending}
              onPress={() => {
                onToggleSave(null);
                saveSheetRef.current?.dismiss();
              }}
              style={({pressed}) => [
                styles.removeSaveButton,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.removeSaveText}>إزالة من المحفوظات</Text>
            </Pressable>
          )}
        </BottomSheetScrollView>
      </BottomSheetModal>
    </>
  );
};

export default FeedSideBar;

const styles = StyleSheet.create({
  container: {
    position: 'absolute',
    right: 10,
    zIndex: 35,
    alignItems: 'center',
    gap: 17,
  },
  containerCompact: {
    gap: 10,
  },
  action: {
    width: 70,
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
  },
  actionDisabled: {
    opacity: 0.62,
  },
  actionCompact: {
    width: 62,
  },
  actionIcon: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    borderRadius: 24,
    backgroundColor: 'rgba(4,8,13,.48)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.14)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  actionIconCompact: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    borderRadius: Accessibility.minTouchTarget / 2,
  },
  actionIconActive: {
    backgroundColor: 'rgba(35,111,232,.24)',
    borderColor: 'rgba(95,153,247,.45)',
  },
  actionLabel: {
    color: '#FFFFFF',
    fontFamily: Fonts.medium,
    fontSize: 11,
    marginTop: 4,
    textShadowColor: 'rgba(0,0,0,.9)',
    textShadowRadius: 5,
    width: '100%',
    textAlign: 'center',
  },
  counter: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 12,
    fontVariant: ['tabular-nums'],
  },
  sheetBackground: {
    backgroundColor: '#0B1017',
    borderTopLeftRadius: 26,
    borderTopRightRadius: 26,
  },
  sheetIndicator: {
    backgroundColor: 'rgba(255,255,255,.28)',
    width: 42,
  },
  sheetContent: {
    paddingHorizontal: 16,
    paddingBottom: 42,
    width: '100%',
    maxWidth: 760,
    alignSelf: 'center',
  },
  sheetHeader: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 14,
    paddingTop: 6,
  },
  sheetHeaderCopy: {
    flex: 1,
  },
  sheetEyebrow: {
    ...textDirection,
    color: '#76A9FF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  sheetTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 20,
    lineHeight: 30,
  },
  progressPill: {
    minWidth: 58,
    height: 38,
    paddingHorizontal: 10,
    borderRadius: 19,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.07)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.1)',
  },
  progressText: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 12,
    fontVariant: ['tabular-nums'],
  },
  progressTrack: {
    height: 3,
    borderRadius: 2,
    backgroundColor: 'rgba(255,255,255,.12)',
    overflow: 'hidden',
    marginTop: 14,
    marginBottom: 20,
  },
  progressFill: {
    height: '100%',
    backgroundColor: '#4B8EF7',
  },
  moduleCard: {
    borderRadius: 19,
    padding: 14,
    marginBottom: 12,
    backgroundColor: '#121923',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.07)',
  },
  lockedModule: {
    opacity: 0.78,
  },
  moduleHeader: {
    minHeight: 44,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  moduleHeading: {
    flex: 1,
  },
  moduleHeaderActions: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 9,
    flexShrink: 0,
  },
  moduleTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 15,
    lineHeight: 23,
  },
  moduleMeta: {
    ...textDirection,
    color: 'rgba(255,255,255,.46)',
    fontFamily: Fonts.regular,
    fontSize: 11,
    marginTop: 1,
  },
  reelsList: {
    marginTop: 8,
    gap: 5,
  },
  reelRow: {
    minHeight: 48,
    borderRadius: 13,
    paddingHorizontal: 8,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
  },
  lockedReelRow: {
    opacity: 0.7,
  },
  activeReelRow: {
    backgroundColor: 'rgba(35,111,232,.15)',
  },
  reelNumber: {
    width: 32,
    height: 32,
    borderRadius: 10,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  activeReelNumber: {
    backgroundColor: '#236FE8',
  },
  reelNumberText: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
    fontVariant: ['tabular-nums'],
  },
  reelTitle: {
    ...textDirection,
    flex: 1,
    color: 'rgba(255,255,255,.86)',
    fontFamily: Fonts.regular,
    fontSize: 13,
  },
  completedMark: {
    color: '#67D39B',
    fontFamily: Fonts.bold,
    fontSize: 15,
  },
  projectRow: {
    minHeight: 58,
    borderRadius: 14,
    paddingHorizontal: 8,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
    marginTop: 3,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
  projectRowDisabled: {
    opacity: 0.5,
  },
  projectGlyph: {
    width: 34,
    height: 34,
    borderRadius: 11,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(75,142,247,.14)',
  },
  projectGlyphText: {
    color: '#76A9FF',
    fontSize: 13,
  },
  projectCopy: {
    flex: 1,
  },
  projectTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.medium,
    fontSize: 13,
  },
  projectStatus: {
    ...textDirection,
    color: 'rgba(255,255,255,.45)',
    fontFamily: Fonts.regular,
    fontSize: 10,
    marginTop: 1,
  },
  saveSheetContent: {
    paddingHorizontal: 18,
    paddingBottom: 38,
    width: '100%',
    maxWidth: 680,
    alignSelf: 'center',
  },
  attachmentSheetContent: {
    paddingHorizontal: 18,
    paddingBottom: 38,
    width: '100%',
    maxWidth: 680,
    alignSelf: 'center',
  },
  attachmentTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 22,
    lineHeight: 32,
    marginTop: 2,
  },
  attachmentBody: {
    ...textDirection,
    color: 'rgba(255,255,255,.68)',
    fontFamily: Fonts.regular,
    fontSize: 13,
    lineHeight: 21,
    marginTop: 5,
    marginBottom: 16,
  },
  attachmentList: {gap: 8},
  attachmentRow: {
    minHeight: 64,
    borderRadius: 16,
    paddingHorizontal: 12,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
    backgroundColor: '#121923',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
  attachmentGlyph: {
    width: 40,
    height: 40,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(75,142,247,.18)',
  },
  attachmentCopy: {flex: 1},
  attachmentName: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.medium,
    fontSize: 13,
  },
  attachmentMeta: {
    ...textDirection,
    color: 'rgba(255,255,255,.46)',
    fontFamily: Fonts.regular,
    fontSize: 10,
    marginTop: 2,
  },
  attachmentAction: {
    color: '#76A9FF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
    maxWidth: 90,
  },
  saveSheetTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 23,
    lineHeight: 34,
    marginTop: 2,
    marginBottom: 16,
  },
  folderLoader: {marginVertical: 24},
  folderList: {gap: 7},
  folderError: {
    ...textDirection,
    color: '#FF9A9A',
    fontFamily: Fonts.medium,
    fontSize: 12,
    lineHeight: 19,
    marginTop: 10,
  },
  folderRow: {
    minHeight: 52,
    borderRadius: 14,
    paddingHorizontal: 14,
    ...rtlRowStyle,
    alignItems: 'center',
    backgroundColor: '#121923',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.07)',
  },
  folderRowText: {
    ...textDirection,
    flex: 1,
    color: '#FFFFFF',
    fontFamily: Fonts.medium,
    fontSize: 14,
  },
  folderRowAction: {color: '#76A9FF', fontFamily: Fonts.semiBold, fontSize: 12},
  newFolderRow: {...rtlRowStyle, alignItems: 'center', gap: 8, marginTop: 14},
  folderInput: {
    ...textDirection,
    flex: 1,
    minHeight: 52,
    borderRadius: 14,
    paddingHorizontal: 14,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 14,
    backgroundColor: '#121923',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.1)',
  },
  createFolderButton: {
    minHeight: 52,
    minWidth: 110,
    paddingHorizontal: 14,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
  },
  createFolderButtonDisabled: {opacity: 0.45},
  createFolderButtonText: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 12,
  },
  removeSaveButton: {
    alignSelf: 'flex-end',
    paddingVertical: 13,
    paddingHorizontal: 4,
    marginTop: 5,
  },
  removeSaveText: {color: '#F28B91', fontFamily: Fonts.medium, fontSize: 12},
  pressed: {opacity: 0.75},
});
