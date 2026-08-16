import {useFocusEffect, useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import React, {useCallback, useState} from 'react';
import {
  Alert,
  Image,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import {Swipeable} from 'react-native-gesture-handler';
import {StatusView, SectionHeading} from '../../components/ui/PremiumUI';
import {SavedLibrarySkeleton} from '../../components/ui/Skeleton';
import {
  formatArabicDisplayText,
  formatArabicNumber,
  toArabicDigits,
} from '../../constants/arabicFormatting';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {
  getSavedFolderOptions,
  getLocalLearningState,
  createSavedFolderOption,
  deleteSavedFolderOption,
  removeLessonFromSavedFolder,
  type SavedFolderOption,
  toggleWatchLater,
} from '../../components/VideoPlayer/courseLearningApi';
import {createDemoCourse} from '../../components/VideoPlayer/demoCourse';
import {
  deleteSavedLesson,
  getSavedLessonsPage,
  hasSession,
  type SavedLesson,
} from '../../services/roknApi';
import {LOCAL_DEMO_ENABLED} from '../../config/runtime';
import {friendlyNetworkMessage} from '../../services/networkExperience';

type SavedReel = {
  id: string;
  courseId: string;
  reelId: string;
  title: string;
  courseTitle: string;
  duration: string;
  folderId?: string;
  folderName: string;
  imageUrl?: string;
  remote: boolean;
};

const remoteLessonsToRows = (lessons: SavedLesson[]): SavedReel[] =>
  lessons.map(lesson => ({
    id: lesson.id,
    folderId: lesson.folderId,
    folderName: lesson.folderName,
    courseId: lesson.courseId,
    reelId: lesson.id,
    title: lesson.title,
    courseTitle: lesson.courseTitle,
    duration: lesson.duration,
    imageUrl: lesson.imageUrl,
    remote: true,
  }));

export default function SavedVideos() {
  const navigation = useNavigation<RootNavigation>();
  const [saved, setSaved] = useState<SavedReel[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [reload, setReload] = useState(0);
  const [nextPage, setNextPage] = useState<number | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [loadMoreError, setLoadMoreError] = useState('');
  const [actionError, setActionError] = useState('');
  const [folders, setFolders] = useState<SavedFolderOption[]>([]);
  const [activeFolderId, setActiveFolderId] = useState('all');
  const [showCreateFolder, setShowCreateFolder] = useState(false);
  const [newFolderName, setNewFolderName] = useState('');
  const [creatingFolder, setCreatingFolder] = useState(false);
  const [deletingFolder, setDeletingFolder] = useState(false);
  const [folderError, setFolderError] = useState('');
  const [serverSession, setServerSession] = useState<boolean | null>(null);

  useFocusEffect(
    useCallback(() => {
      let active = true;
      setLoading(true);
      setNextPage(null);
      setLoadMoreError('');
      if (reload > 0) setError('');
      void (async () => {
        try {
          const sessionAvailable = await hasSession();
          if (active) setServerSession(sessionAvailable);
          if (sessionAvailable) {
            const [result, folderOptions] = await Promise.all([
              getSavedLessonsPage(1),
              getSavedFolderOptions().catch(() => []),
            ]);
            if (!active) return;
            setSaved(remoteLessonsToRows(result.lessons));
            setFolders(folderOptions);
            setNextPage(result.hasMore ? result.page + 1 : null);
            setError('');
            return;
          }
          if (!LOCAL_DEMO_ENABLED) {
            if (active) {
              setSaved([]);
              setFolders([]);
              setError('');
            }
            return;
          }
          const [state, folderOptions] = await Promise.all([
            getLocalLearningState(),
            getSavedFolderOptions(),
          ]);
          if (!active) return;
          const savedIds = new Set(state.savedLessons);
          const folderNames = new Map(
            folderOptions.map(folder => [folder.id, folder.name]),
          );
          setFolders(folderOptions);
          const course = createDemoCourse();
          const reels = course.modules.flatMap(module => module.reels);
          setSaved(
            reels
              .filter(reel => savedIds.has(reel.lessonId))
              .flatMap(reel => {
                const memberships = Object.entries(
                  state.savedFolderLessons || {},
                )
                  .filter(([, lessonIds]) => lessonIds.includes(reel.lessonId))
                  .map(([folderId]) => folderId);
                const targetFolders = memberships.length
                  ? memberships
                  : ['local-watch-later'];
                return targetFolders.map(folderId => ({
                  id: reel.lessonId,
                  folderId,
                  folderName: folderNames.get(folderId) || 'المشاهدة لاحقًا',
                  courseId: course.id,
                  reelId: reel.id,
                  title: reel.title,
                  courseTitle: course.title,
                  duration: `${String(
                    Math.floor((reel.durationSeconds || 0) / 60),
                  ).padStart(2, '0')}:${String(
                    (reel.durationSeconds || 0) % 60,
                  ).padStart(2, '0')}`,
                  remote: false,
                }));
              }),
          );
          setError('');
        } catch (requestError) {
          if (active)
            setError(
              `${friendlyNetworkMessage(
                requestError,
                'المحفوظات',
              )} مكانك وكل ما حفظته موجود.`,
            );
        }
      })().finally(() => active && setLoading(false));
      return () => {
        active = false;
      };
    }, [reload]),
  );

  const loadMore = useCallback(async () => {
    if (!nextPage || loadingMore || serverSession !== true) return;
    setLoadingMore(true);
    setLoadMoreError('');
    try {
      const result = await getSavedLessonsPage(nextPage);
      const rows = remoteLessonsToRows(result.lessons);
      setSaved(current => {
        const existing = new Set(
          current.map(item => `${item.folderId || ''}:${item.id}`),
        );
        return [
          ...current,
          ...rows.filter(
            item => !existing.has(`${item.folderId || ''}:${item.id}`),
          ),
        ];
      });
      setNextPage(result.hasMore ? result.page + 1 : null);
    } catch {
      setLoadMoreError('تعذّر تحميل باقي المحفوظات');
    } finally {
      setLoadingMore(false);
    }
  }, [loadingMore, nextPage, serverSession]);

  const createFolder = useCallback(async () => {
    const name = newFolderName.trim();
    if (!name || creatingFolder) return;
    const existing = folders.find(
      folder =>
        folder.name.trim().toLocaleLowerCase('ar') ===
        name.toLocaleLowerCase('ar'),
    );
    if (existing) {
      setActiveFolderId(existing.id);
      setNewFolderName('');
      setShowCreateFolder(false);
      return;
    }
    setCreatingFolder(true);
    setFolderError('');
    try {
      const created = await createSavedFolderOption(name);
      setFolders(current => [
        ...current.filter(folder => folder.id !== created.id),
        created,
      ]);
      setActiveFolderId(created.id);
      setNewFolderName('');
      setShowCreateFolder(false);
    } catch {
      setFolderError('لم تُنشأ القائمة. تأكد من الاتصال وحاول مرة أخرى.');
    } finally {
      setCreatingFolder(false);
    }
  }, [creatingFolder, folders, newFolderName]);

  const removeSaved = useCallback(async (item: SavedReel) => {
    setActionError('');
    setSaved(current =>
      current.filter(
        video => !(video.id === item.id && video.folderId === item.folderId),
      ),
    );
    try {
      if (item.remote && item.folderId) {
        await deleteSavedLesson(item.folderId, item.id);
      } else if (item.folderId) {
        await removeLessonFromSavedFolder(item.id, item.folderId);
      } else {
        await toggleWatchLater(item.id, true);
      }
    } catch {
      setSaved(current =>
        current.some(
          video => video.id === item.id && video.folderId === item.folderId,
        )
          ? current
          : [...current, item],
      );
      setActionError('لم نتمكن من إزالة الخطوة. جرّب مرة أخرى.');
    }
  }, []);

  const deleteActiveFolder = useCallback(() => {
    const folder = folders.find(item => item.id === activeFolderId);
    if (!folder || deletingFolder) return;
    Alert.alert(
      'حذف القائمة؟',
      `سيتم حذف قائمة «${folder.name}». المحتوى المحفوظ في قوائم أخرى سيظل موجودًا.`,
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'حذف',
          style: 'destructive',
          onPress: () => {
            setDeletingFolder(true);
            setFolderError('');
            void deleteSavedFolderOption(folder.id)
              .then(() => {
                setFolders(current =>
                  current.filter(item => item.id !== folder.id),
                );
                setSaved(current =>
                  current.filter(item => item.folderId !== folder.id),
                );
                setActiveFolderId('all');
              })
              .catch(() =>
                setFolderError(
                  'تعذّر حذف القائمة الآن. تأكد من الاتصال وحاول مرة أخرى.',
                ),
              )
              .finally(() => setDeletingFolder(false));
          },
        },
      ],
    );
  }, [activeFolderId, deletingFolder, folders]);

  const folderMap = new Map<string, SavedFolderOption>();
  folders.forEach(folder => folderMap.set(folder.id, folder));
  saved.forEach(item => {
    const id = item.folderId || 'watch-later';
    if (!folderMap.has(id)) {
      folderMap.set(id, {id, name: item.folderName});
    }
  });
  const folderOptions = Array.from(folderMap.values());
  const visibleSaved =
    activeFolderId === 'all'
      ? saved
      : saved.filter(
          item => (item.folderId || 'watch-later') === activeFolderId,
        );
  const groupedSaved = Array.from(
    visibleSaved.reduce((groups, item) => {
      const key = item.folderId || 'watch-later';
      const group = groups.get(key) || {
        name: item.folderName,
        items: [] as SavedReel[],
      };
      group.items.push(item);
      groups.set(key, group);
      return groups;
    }, new Map<string, {name: string; items: SavedReel[]}>()),
  );

  if (loading) {
    return <SavedLibrarySkeleton />;
  }

  if (error) {
    return (
      <StatusView
        actionLabel="إعادة المحاولة"
        description={error}
        onAction={() => setReload(value => value + 1)}
        state="error"
        title="تعذّر تحميل المحفوظات"
      />
    );
  }

  if (serverSession === false && !LOCAL_DEMO_ENABLED) {
    return (
      <StatusView
        actionLabel="تسجيل الدخول"
        description="سجّل دخولك علشان تلاقي كل اللي حفظته على أي جهاز."
        onAction={() =>
          navigation.navigate('Login', {
            returnTo: {name: 'Profile'},
          })
        }
        state="empty"
        title="محفوظاتك مرتبطة بحسابك"
      />
    );
  }

  return (
    <View style={styles.container}>
      <SectionHeading
        actionLabel={showCreateFolder ? 'إلغاء' : 'قائمة جديدة'}
        onAction={() => {
          setShowCreateFolder(value => !value);
          setFolderError('');
        }}
        title="محفوظاتك"
      />
      {showCreateFolder ? (
        <View style={styles.createFolderCard}>
          <Text style={styles.createFolderTitle}>اسم القائمة الجديدة</Text>
          <View style={styles.createFolderRow}>
            <TextInput
              accessibilityLabel="اسم القائمة الجديدة"
              autoFocus
              maxLength={60}
              onChangeText={setNewFolderName}
              onSubmitEditing={createFolder}
              placeholder="مثلاً: أراجعها هذا الأسبوع"
              placeholderTextColor={Palette.textFaint}
              returnKeyType="done"
              selectionColor={Palette.primary}
              style={styles.folderInput}
              value={newFolderName}
            />
            <Pressable
              accessibilityRole="button"
              disabled={!newFolderName.trim() || creatingFolder}
              onPress={createFolder}
              style={({pressed}) => [
                styles.createButton,
                (!newFolderName.trim() || creatingFolder) &&
                  styles.createButtonDisabled,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.createButtonText}>
                {creatingFolder ? 'لحظة…' : 'إنشاء'}
              </Text>
            </Pressable>
          </View>
          {!!folderError && (
            <Text accessibilityRole="alert" style={styles.inlineError}>
              {folderError}
            </Text>
          )}
        </View>
      ) : null}

      <ScrollView
        contentContainerStyle={styles.folderChips}
        horizontal
        showsHorizontalScrollIndicator={false}>
        <Pressable
          accessibilityRole="button"
          accessibilityState={{selected: activeFolderId === 'all'}}
          onPress={() => setActiveFolderId('all')}
          style={[
            styles.folderChip,
            activeFolderId === 'all' && styles.folderChipActive,
          ]}>
          <Text
            style={[
              styles.folderChipText,
              activeFolderId === 'all' && styles.folderChipTextActive,
            ]}>
            الكل · {formatArabicNumber(saved.length)}
          </Text>
        </Pressable>
        {folderOptions.map(folder => {
          const count = saved.filter(
            item => (item.folderId || 'watch-later') === folder.id,
          ).length;
          return (
            <Pressable
              accessibilityRole="button"
              accessibilityState={{selected: activeFolderId === folder.id}}
              key={folder.id}
              onPress={() => setActiveFolderId(folder.id)}
              style={[
                styles.folderChip,
                activeFolderId === folder.id && styles.folderChipActive,
              ]}>
              <Text
                numberOfLines={1}
                style={[
                  styles.folderChipText,
                  activeFolderId === folder.id && styles.folderChipTextActive,
                ]}>
                {formatArabicDisplayText(folder.name)} ·{' '}
                {formatArabicNumber(count)}
              </Text>
            </Pressable>
          );
        })}
      </ScrollView>

      {activeFolderId !== 'all' ? (
        <Pressable
          accessibilityLabel="حذف القائمة المحددة"
          accessibilityRole="button"
          disabled={deletingFolder}
          onPress={deleteActiveFolder}
          style={({pressed}) => [
            styles.deleteFolderButton,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.deleteFolderText}>
            {deletingFolder ? 'جارٍ حذف القائمة…' : 'حذف هذه القائمة'}
          </Text>
        </Pressable>
      ) : null}

      {!!folderError && !showCreateFolder ? (
        <Text accessibilityRole="alert" style={styles.inlineError}>
          {folderError}
        </Text>
      ) : null}

      {!!actionError && (
        <Text accessibilityRole="alert" style={styles.actionError}>
          {actionError}
        </Text>
      )}

      {!saved.length ? (
        <StatusView
          description="اضغط حفظ أثناء المشاهدة واختر القائمة التي تناسبك."
          state="empty"
          title="لا توجد خطوات محفوظة"
        />
      ) : !visibleSaved.length ? (
        <StatusView
          description="أثناء المشاهدة اختر هذه القائمة عند الضغط على حفظ."
          state="empty"
          title="القائمة فاضية حاليًا"
        />
      ) : null}

      {groupedSaved.map(([folderId, group]) => (
        <View key={folderId} style={styles.folder}>
          {activeFolderId === 'all' ? (
            <Text style={styles.folderTitle}>
              {formatArabicDisplayText(group.name)}
            </Text>
          ) : null}
          <View style={styles.list}>
            {group.items.map((item, index) => (
              <View key={`${item.folderId}:${item.id}`}>
                <Swipeable
                  friction={2}
                  overshootLeft={false}
                  overshootRight={false}
                  renderRightActions={() => (
                    <Pressable
                      accessibilityLabel="إزالة من هذه القائمة"
                      accessibilityRole="button"
                      onPress={() => void removeSaved(item)}
                      style={styles.swipeDelete}>
                      <Text style={styles.swipeDeleteText}>إزالة</Text>
                    </Pressable>
                  )}>
                  <Pressable
                    accessibilityLabel={`تشغيل ${item.title}`}
                    accessibilityRole="button"
                    onPress={() =>
                      navigation.navigate('Reels', {
                        courseId: item.courseId,
                        ...(item.remote
                          ? {lessonId: item.id}
                          : {reelId: item.reelId}),
                      })
                    }
                    style={({pressed}) => [
                      styles.row,
                      pressed && styles.pressed,
                    ]}>
                    <View style={styles.thumbWrap}>
                      <Image
                        source={
                          item.imageUrl
                            ? {uri: item.imageUrl}
                            : item.remote
                            ? require('../../assets/images/courseSliderBackground.jpg')
                            : require('../../assets/images/demo-course/ui-freelance-cover.jpg')
                        }
                        style={styles.thumb}
                      />
                      <View style={styles.playMark}>
                        <Text style={styles.playText}>▶</Text>
                      </View>
                    </View>
                    <View style={styles.copy}>
                      <Text numberOfLines={2} style={styles.title}>
                        {formatArabicDisplayText(item.title)}
                      </Text>
                      <Text numberOfLines={1} style={styles.course}>
                        {formatArabicDisplayText(item.courseTitle)}
                      </Text>
                      <Text style={styles.duration}>
                        {toArabicDigits(item.duration)}
                      </Text>
                    </View>
                    <Pressable
                      accessibilityLabel="إزالة من هذه القائمة"
                      accessibilityRole="button"
                      hitSlop={8}
                      onPress={event => {
                        event.stopPropagation();
                        void removeSaved(item);
                      }}
                      style={styles.removeButton}>
                      <Text style={styles.removeText}>×</Text>
                    </Pressable>
                  </Pressable>
                </Swipeable>
                {index < group.items.length - 1 && (
                  <View style={styles.divider} />
                )}
              </View>
            ))}
          </View>
        </View>
      ))}
      {nextPage ? (
        <View style={styles.moreWrap}>
          {loadMoreError ? (
            <Text accessibilityRole="alert" style={styles.moreError}>
              {loadMoreError}
            </Text>
          ) : null}
          <Pressable
            accessibilityLabel={
              loadingMore ? 'جارٍ تحميل المزيد' : 'عرض محفوظات أكثر'
            }
            accessibilityRole="button"
            disabled={loadingMore}
            onPress={loadMore}
            style={({pressed}) => [
              styles.moreButton,
              pressed && styles.pressed,
              loadingMore && styles.moreButtonDisabled,
            ]}>
            <Text style={styles.moreButtonText}>
              {loadingMore ? 'بنحمّل الباقي…' : 'عرض المزيد'}
            </Text>
          </Pressable>
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {paddingBottom: Spacing.xl},
  createFolderCard: {
    marginTop: Spacing.sm,
    padding: Spacing.md,
    borderRadius: Radius.lg,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
  },
  createFolderTitle: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginBottom: Spacing.sm,
  },
  createFolderRow: {...rtlRowStyle, alignItems: 'center', gap: Spacing.sm},
  folderInput: {
    ...Type.body,
    ...textDirection,
    flex: 1,
    minWidth: 0,
    minHeight: Accessibility.minTouchTarget,
    paddingHorizontal: Spacing.md,
    paddingVertical: 0,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surfaceRaised,
    color: Palette.text,
  },
  createButton: {
    minWidth: 82,
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
  },
  createButtonDisabled: {opacity: 0.45},
  createButtonText: {...Type.bodyStrong, color: '#FFFFFF'},
  inlineError: {
    ...Type.caption,
    ...textDirection,
    color: Palette.danger,
    marginTop: Spacing.sm,
  },
  folderChips: {
    ...rtlRowStyle,
    gap: Spacing.xs,
    paddingVertical: Spacing.md,
    paddingHorizontal: Spacing.xxs,
  },
  folderChip: {
    maxWidth: 230,
    minHeight: 40,
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
    borderRadius: Radius.pill,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
  },
  folderChipActive: {
    borderColor: 'rgba(75,142,247,0.55)',
    backgroundColor: Palette.primarySoft,
  },
  folderChipText: {...Type.caption, ...textDirection, color: Palette.textMuted},
  folderChipTextActive: {color: '#A9C9FF'},
  deleteFolderButton: {
    alignSelf: 'flex-end',
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    paddingHorizontal: Spacing.xs,
  },
  deleteFolderText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.danger,
  },
  actionError: {
    ...Type.caption,
    ...textDirection,
    color: Palette.danger,
    marginBottom: Spacing.sm,
  },
  folder: {marginTop: Spacing.md},
  folderTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    paddingHorizontal: Spacing.xs,
    marginBottom: Spacing.xs,
  },
  list: {
    backgroundColor: Palette.surface,
    borderRadius: Radius.lg,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    overflow: 'hidden',
    paddingHorizontal: Spacing.md,
  },
  row: {
    ...rtlRowStyle,
    alignItems: 'center',
    minHeight: 96,
    paddingVertical: Spacing.sm,
    backgroundColor: Palette.surface,
  },
  thumbWrap: {
    width: 94,
    aspectRatio: 1.5,
    borderRadius: Radius.md,
    overflow: 'hidden',
    backgroundColor: Palette.surfaceRaised,
  },
  thumb: {width: '100%', height: '100%', resizeMode: 'cover'},
  playMark: {
    position: 'absolute',
    inset: 0,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(0,0,0,0.2)',
  },
  playText: {color: Palette.text, fontSize: 14},
  copy: {flex: 1, marginHorizontal: Spacing.md},
  title: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  course: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 2,
  },
  duration: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    marginTop: 2,
  },
  removeButton: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
  },
  removeText: {fontSize: 24, color: Palette.textMuted},
  swipeDelete: {
    width: 88,
    minHeight: 96,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.danger,
  },
  swipeDeleteText: {...Type.bodyStrong, color: '#FFFFFF'},
  divider: {
    height: StyleSheet.hairlineWidth,
    backgroundColor: Palette.lineSoft,
  },
  moreWrap: {alignItems: 'center', marginTop: Spacing.lg},
  moreError: {
    ...Type.caption,
    ...textDirection,
    color: Palette.danger,
    marginBottom: Spacing.sm,
  },
  moreButton: {
    minWidth: 180,
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.pill,
    borderWidth: 1,
    borderColor: Palette.primary,
    paddingHorizontal: Spacing.lg,
  },
  moreButtonDisabled: {opacity: 0.56},
  moreButtonText: {...Type.bodyStrong, color: Palette.primary},
  pressed: {opacity: 0.72},
});
