import {useFocusEffect, useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import React, {useCallback, useRef, useState} from 'react';
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
  const [removingSaved, setRemovingSaved] = useState<Set<string>>(new Set());
  const [folders, setFolders] = useState<SavedFolderOption[]>([]);
  const [activeFolderId, setActiveFolderId] = useState('all');
  const [showCreateFolder, setShowCreateFolder] = useState(false);
  const [newFolderName, setNewFolderName] = useState('');
  const [creatingFolder, setCreatingFolder] = useState(false);
  const [deletingFolder, setDeletingFolder] = useState(false);
  const [folderError, setFolderError] = useState('');
  const [folderLoadError, setFolderLoadError] = useState('');
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const loadGenerationRef = useRef(0);
  const loadingMoreRef = useRef(false);
  const screenActiveRef = useRef(false);
  const createFolderFlightRef = useRef(false);
  const deleteFolderFlightRef = useRef(false);
  const removeFlightsRef = useRef(new Set<string>());

  useFocusEffect(
    useCallback(() => {
      let active = true;
      screenActiveRef.current = true;
      if (!createFolderFlightRef.current) setCreatingFolder(false);
      if (!deleteFolderFlightRef.current) setDeletingFolder(false);
      if (!removeFlightsRef.current.size) setRemovingSaved(new Set());
      const generation = ++loadGenerationRef.current;
      loadingMoreRef.current = false;
      setLoading(true);
      setNextPage(null);
      setLoadMoreError('');
      if (reload > 0) setError('');
      void (async () => {
        try {
          const sessionAvailable = await hasSession();
          if (active) setServerSession(sessionAvailable);
          if (sessionAvailable) {
            const [result, folderOptionsResult] = await Promise.all([
              getSavedLessonsPage(1),
              getSavedFolderOptions().then(
                value => ({ok: true as const, value}),
                () => ({ok: false as const}),
              ),
            ]);
            if (!active || generation !== loadGenerationRef.current) return;
            setSaved(remoteLessonsToRows(result.lessons));
            if (folderOptionsResult.ok) {
              setFolders(folderOptionsResult.value);
              setFolderLoadError('');
            } else {
              // Keep the last known folder list. Replacing it with [] hides
              // valid empty folders and makes a partial outage look like data
              // the learner deleted.
              setFolderLoadError(
                'تعذّر تحديث القوائم\nالمحفوظات ما زالت موجودة',
              );
            }
            setNextPage(result.hasMore ? result.page + 1 : null);
            setError(
              result.fromCache
                ? 'نعرض آخر محفوظات متاحة\nأعد المحاولة عند عودة الاتصال'
                : '',
            );
            return;
          }
          if (!LOCAL_DEMO_ENABLED) {
            if (active) {
              setSaved([]);
              setFolders([]);
              setFolderLoadError('');
              setError('');
            }
            return;
          }
          const [state, folderOptions] = await Promise.all([
            getLocalLearningState(),
            getSavedFolderOptions(),
          ]);
          if (!active || generation !== loadGenerationRef.current) return;
          const savedIds = new Set(state.savedLessons);
          const folderNames = new Map(
            folderOptions.map(folder => [folder.id, folder.name]),
          );
          setFolders(folderOptions);
          setFolderLoadError('');
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
              )}\nمكانك وكل ما حفظته موجود`,
            );
        }
      })().finally(() => active && setLoading(false));
      return () => {
        active = false;
        screenActiveRef.current = false;
        loadGenerationRef.current += 1;
        loadingMoreRef.current = false;
      };
    }, [reload]),
  );

  const loadMore = useCallback(async () => {
    if (
      !nextPage ||
      loadingMore ||
      loadingMoreRef.current ||
      serverSession !== true
    )
      return;
    loadingMoreRef.current = true;
    const generation = loadGenerationRef.current;
    setLoadingMore(true);
    setLoadMoreError('');
    try {
      const result = await getSavedLessonsPage(nextPage);
      if (generation !== loadGenerationRef.current) return;
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
      if (generation === loadGenerationRef.current) {
        setLoadMoreError('تعذّر تحميل باقي المحفوظات');
      }
    } finally {
      if (generation === loadGenerationRef.current) {
        loadingMoreRef.current = false;
        setLoadingMore(false);
      }
    }
  }, [loadingMore, nextPage, serverSession]);

  const createFolder = useCallback(async () => {
    const name = newFolderName.trim();
    if (!name || creatingFolder || createFolderFlightRef.current) return;
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
    const generation = loadGenerationRef.current;
    createFolderFlightRef.current = true;
    setCreatingFolder(true);
    setFolderError('');
    try {
      const created = await createSavedFolderOption(name);
      if (!screenActiveRef.current || generation !== loadGenerationRef.current)
        return;
      setFolders(current => [
        ...current.filter(folder => folder.id !== created.id),
        created,
      ]);
      setActiveFolderId(created.id);
      setNewFolderName('');
      setShowCreateFolder(false);
    } catch {
      if (screenActiveRef.current && generation === loadGenerationRef.current) {
        setFolderError('تعذّر إنشاء القائمة\nتحقق من الاتصال ثم حاول مرة أخرى');
      }
    } finally {
      createFolderFlightRef.current = false;
      if (screenActiveRef.current && generation === loadGenerationRef.current) {
        setCreatingFolder(false);
      }
    }
  }, [creatingFolder, folders, newFolderName]);

  const removeSaved = useCallback(
    async (item: SavedReel) => {
      const key = `${item.folderId || ''}:${item.id}`;
      if (removingSaved.has(key) || removeFlightsRef.current.has(key)) return;
      const generation = loadGenerationRef.current;
      removeFlightsRef.current.add(key);
      setActionError('');
      setRemovingSaved(current => new Set(current).add(key));
      try {
        if (item.folderId) {
          await removeLessonFromSavedFolder(item.id, item.folderId);
        } else {
          await toggleWatchLater(item.id, true);
        }
        if (
          !screenActiveRef.current ||
          generation !== loadGenerationRef.current
        )
          return;
        setSaved(current =>
          current.filter(
            video =>
              !(video.id === item.id && video.folderId === item.folderId),
          ),
        );
      } catch {
        if (
          screenActiveRef.current &&
          generation === loadGenerationRef.current
        ) {
          setActionError('تعذّرت إزالة المقطع\nحاول مرة أخرى');
        }
      } finally {
        removeFlightsRef.current.delete(key);
        if (
          screenActiveRef.current &&
          generation === loadGenerationRef.current
        ) {
          setRemovingSaved(current => {
            const next = new Set(current);
            next.delete(key);
            return next;
          });
        }
      }
    },
    [removingSaved],
  );

  const deleteActiveFolder = useCallback(() => {
    const folder = folders.find(item => item.id === activeFolderId);
    if (!folder || deletingFolder || deleteFolderFlightRef.current) return;
    Alert.alert(
      'حذف القائمة',
      `سنحذف ${folder.name}\nوتبقى المحفوظات في القوائم الأخرى`,
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'حذف',
          style: 'destructive',
          onPress: () => {
            if (deleteFolderFlightRef.current) return;
            const generation = loadGenerationRef.current;
            deleteFolderFlightRef.current = true;
            setDeletingFolder(true);
            setFolderError('');
            void deleteSavedFolderOption(folder.id)
              .then(() => {
                if (
                  !screenActiveRef.current ||
                  generation !== loadGenerationRef.current
                )
                  return;
                setFolders(current =>
                  current.filter(item => item.id !== folder.id),
                );
                setSaved(current =>
                  current.filter(item => item.folderId !== folder.id),
                );
                setActiveFolderId('all');
              })
              .catch(() => {
                if (
                  screenActiveRef.current &&
                  generation === loadGenerationRef.current
                ) {
                  setFolderError(
                    'تعذّر حذف القائمة\nتحقق من الاتصال وحاول مرة أخرى',
                  );
                }
              })
              .finally(() => {
                deleteFolderFlightRef.current = false;
                if (
                  screenActiveRef.current &&
                  generation === loadGenerationRef.current
                ) {
                  setDeletingFolder(false);
                }
              });
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

  if (loading && !saved.length) {
    return <SavedLibrarySkeleton />;
  }

  if (error && !saved.length) {
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
        description="سجّل الدخول لعرض محفوظاتك على أي جهاز"
        onAction={() =>
          openGuestLogin(navigation, {
            name: 'Profile',
            params: {tab: 'saved'},
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
      {!!error && saved.length ? (
        <Pressable
          accessibilityRole="button"
          onPress={() => setReload(value => value + 1)}
          style={({pressed}) => [
            styles.retryNotice,
            pressed && styles.pressed,
          ]}>
          <Text style={styles.retryNoticeText}>{error}</Text>
          <Text style={styles.retryNoticeAction}>إعادة المحاولة</Text>
        </Pressable>
      ) : null}
      {folderLoadError ? (
        <Text accessibilityRole="alert" style={styles.actionError}>
          {folderLoadError}
        </Text>
      ) : null}
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
                {creatingFolder ? 'جارٍ الإنشاء' : 'إنشاء'}
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
            {deletingFolder ? 'جارٍ حذف القائمة' : 'حذف هذه القائمة'}
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
          description="اضغط حفظ أثناء المشاهدة واختر القائمة المناسبة"
          state="empty"
          title="لا توجد مقاطع محفوظة"
        />
      ) : !visibleSaved.length ? (
        <StatusView
          description="اختر هذه القائمة عند الضغط على حفظ"
          state="empty"
          title="لا توجد مقاطع في هذه القائمة"
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
            {group.items.map((item, index) => {
              const removalKey = `${item.folderId || ''}:${item.id}`;
              const removalPending = removingSaved.has(removalKey);
              return (
                <View key={`${item.folderId}:${item.id}`}>
                  <Swipeable
                    enabled={!removalPending}
                    friction={2}
                    overshootLeft={false}
                    overshootRight={false}
                    renderRightActions={() => (
                      <Pressable
                        accessibilityLabel="إزالة من هذه القائمة"
                        accessibilityRole="button"
                        accessibilityState={{disabled: removalPending}}
                        disabled={removalPending}
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
                        accessibilityLabel={
                          removalPending
                            ? 'جارٍ الإزالة'
                            : 'إزالة من هذه القائمة'
                        }
                        accessibilityRole="button"
                        accessibilityState={{
                          busy: removalPending,
                          disabled: removalPending,
                        }}
                        disabled={removalPending}
                        hitSlop={8}
                        onPress={event => {
                          event.stopPropagation();
                          void removeSaved(item);
                        }}
                        style={styles.removeButton}>
                        <Text style={styles.removeText}>
                          {removalPending ? '…' : '×'}
                        </Text>
                      </Pressable>
                    </Pressable>
                  </Swipeable>
                  {index < group.items.length - 1 && (
                    <View style={styles.divider} />
                  )}
                </View>
              );
            })}
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
              {loadingMore ? 'جارٍ تحميل المزيد' : 'عرض المزيد'}
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
  retryNotice: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: Spacing.sm,
    marginBottom: Spacing.sm,
    padding: Spacing.sm,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
  },
  retryNoticeText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    flex: 1,
  },
  retryNoticeAction: {
    ...Type.caption,
    color: Palette.primary,
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
