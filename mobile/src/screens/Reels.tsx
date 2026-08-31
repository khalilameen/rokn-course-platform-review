import {useNavigation, useRoute} from '@react-navigation/native';
import React, {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {
  FlatList,
  LayoutChangeEvent,
  Platform,
  StatusBar,
  StyleSheet,
  View,
  ViewToken,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import CourseChatOverlay from '../components/VideoPlayer/CourseChatOverlay';
import {
  flushPendingPlaybackPositions,
  reportPlaybackSessionEvent,
  saveLessonToFolder,
  SavedFolderOption,
  submitProjectAttempt,
  toggleWatchLater,
  unlockAfterProject,
} from '../components/VideoPlayer/courseLearningApi';
import {
  scheduledManifestRefreshDelayMs,
  PlaybackPlayerEvent,
  PlaybackRuntimeMetrics,
} from '../components/VideoPlayer/playbackTelemetry';
import {
  CourseFeedItem,
  CourseLearningData,
  CourseReel,
  SelectedProjectFile,
} from '../components/VideoPlayer/types';
import {
  DEMO_COURSE_ID,
  claimDemoCourseCompletionReward,
  claimDemoFirstProjectReward,
} from '../services/demoExperience';
import NotificationPermissionPrimer from '../components/ui/NotificationPermissionPrimer';
import {
  ReelsConnectionNote,
  ReelsLoadingState,
  ReelsPreviewGate,
  ReelsUnavailableState,
} from './reels/ReelsPresentation';
import {
  buildAccessibleFeed,
  buildPreviewFeed,
  resolveReelsFrameWidth,
  type ReelsRouteParams,
  updateProjectStatusOnly,
} from './reels/presentation';
import {usePlaybackPreferences} from './reels/usePlaybackPreferences';
import {useReminderNudge} from './reels/useReminderNudge';
import {useReelsLifecycle} from './reels/useReelsLifecycle';
import {usePlaybackManifest} from './reels/usePlaybackManifest';
import {useReelsCourseLoader} from './reels/useReelsCourseLoader';
import {
  useReelsFeedRenderer,
  type ReelsNavigation,
} from './reels/useReelsFeedRenderer';
import {useProjectReview} from './reels/useProjectReview';
import {useReelsProgress} from './reels/useReelsProgress';

const Reels = () => {
  const route = useRoute();
  const navigation = useNavigation<ReelsNavigation>();
  const params = (route.params || {}) as ReelsRouteParams;
  const previewMode = params.preview === true;
  const insets = useSafeAreaInsets();
  const listRef = useRef<FlatList<CourseFeedItem>>(null);
  const positionsRef = useRef<Record<string, number>>({});
  const lastPersistedRef = useRef<Record<string, number>>({});
  const completionSentRef = useRef(new Set<string>());
  const savePendingRef = useRef(new Set<string>());
  const reviewWatcherRef = useRef(0);
  const watchedProjectRef = useRef<string | null>(null);
  const currentIndexRef = useRef(0);
  const feedLengthRef = useRef(0);
  const [layout, setLayout] = useState({width: 0, height: 0});
  const [course, setCourse] = useState<CourseLearningData | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [connectionNote, setConnectionNote] = useState('');
  const [currentIndex, setCurrentIndex] = useState(0);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const {
    autoplay,
    changeFitMode,
    changePlaybackSpeed,
    changeQuality,
    dataSaver,
    fitMode,
    getPlaybackSpeed,
    playbackPreferencesReady,
    playbackSpeed,
    selectedQuality,
  } = usePlaybackPreferences(serverSession);
  const [manifestRefreshNonce, setManifestRefreshNonce] = useState(0);
  const [savedLessons, setSavedLessons] = useState<Set<string>>(new Set());
  const [chatVisible, setChatVisible] = useState(false);
  const [previewGateVisible, setPreviewGateVisible] = useState(false);
  const {
    closeReminderNudge,
    enableRemindersFromNudge,
    maybeOfferReminders,
    reminderNudgeVisible,
  } = useReminderNudge({
    courseId: course?.id,
    courseTitle: course?.title,
  });
  const pendingInitialKey = useRef<string | null>(null);
  const pendingInitialIndex = useRef<number | null>(null);
  const demoRewardsEnabledRef = useRef(false);
  const pendingStudySecondsRef = useRef(0);
  const studySampleRef = useRef<{
    reelId: string;
    mediaTime: number;
    sampledAt: number;
  } | null>(null);
  const manifestFlightsRef = useRef(new Set<string>());
  const manifestVersionsRef = useRef<Record<string, number>>({});
  const playbackRuntimeRef = useRef<Record<string, PlaybackRuntimeMetrics>>({});
  const playbackDurationRef = useRef<Record<string, number>>({});
  const closedPlaybackSessionsRef = useRef(new Set<string>());
  const activeReelRef = useRef<CourseReel | undefined>(undefined);
  const loadedCourseRef = useRef<CourseLearningData | null>(null);
  const loadRequestRef = useRef(0);
  const mountedRef = useRef(true);
  const delayedActionsRef = useRef(new Set<ReturnType<typeof setTimeout>>());
  const scheduleDelayedAction = useCallback(
    (action: () => void, delayMs: number) => {
      const timer = setTimeout(() => {
        delayedActionsRef.current.delete(timer);
        action();
      }, delayMs);
      delayedActionsRef.current.add(timer);
    },
    [],
  );

  const lifecycleRefs = useMemo(
    () => ({
      activeReel: activeReelRef,
      course: loadedCourseRef,
      delayedActions: delayedActionsRef,
      loadRequest: loadRequestRef,
      mounted: mountedRef,
      playbackDurations: playbackDurationRef,
      playbackRuntime: playbackRuntimeRef,
      positions: positionsRef,
      reviewWatcher: reviewWatcherRef,
    }),
    [],
  );
  useReelsLifecycle(lifecycleRefs, getPlaybackSpeed);

  const feedItems = useMemo(
    () =>
      course
        ? previewMode
          ? buildPreviewFeed(course, Number(params.previewCount) || 0)
          : buildAccessibleFeed(course)
        : [],
    [course, params.previewCount, previewMode],
  );
  const currentItem = feedItems[currentIndex] || feedItems[0];
  const currentReel: CourseReel | undefined =
    currentItem?.type === 'reel' ? currentItem.reel : undefined;
  activeReelRef.current = currentReel;
  currentIndexRef.current = currentIndex;
  feedLengthRef.current = feedItems.length;

  const manifestRefs = useMemo(
    () => ({
      course: loadedCourseRef,
      durations: playbackDurationRef,
      flights: manifestFlightsRef,
      mounted: mountedRef,
      positions: positionsRef,
      runtime: playbackRuntimeRef,
      versions: manifestVersionsRef,
    }),
    [],
  );
  const requestPlaybackManifest = usePlaybackManifest({
    courseId: course?.id,
    dataSaver,
    getPlaybackSpeed,
    playbackPreferencesReady,
    refs: manifestRefs,
    scheduleDelayedAction,
    selectedQuality,
    serverSession,
    setConnectionNote,
    setCourse,
    setManifestRefreshNonce,
  });

  useEffect(() => {
    if (!currentReel || previewGateVisible) return;
    const sessionId = currentReel.playbackSessionId;
    if (!sessionId || closedPlaybackSessionsRef.current.has(sessionId)) {
      requestPlaybackManifest(currentReel, sessionId);
    }
  }, [
    currentReel,
    manifestRefreshNonce,
    previewGateVisible,
    requestPlaybackManifest,
  ]);

  useEffect(() => {
    if (!currentReel?.playbackSessionId) return;
    const delay = scheduledManifestRefreshDelayMs(
      currentReel.playbackRefreshAfter,
      currentReel.playbackExpiresAt,
    );
    if (delay === null) return;
    const expectedSessionId = currentReel.playbackSessionId;
    const timer = setTimeout(
      () => requestPlaybackManifest(currentReel, expectedSessionId),
      delay,
    );
    return () => clearTimeout(timer);
  }, [currentReel, manifestRefreshNonce, requestPlaybackManifest]);

  const loaderRefs = useMemo(
    () => ({
      closedPlaybackSessions: closedPlaybackSessionsRef,
      demoRewardsEnabled: demoRewardsEnabledRef,
      loadRequest: loadRequestRef,
      loadedCourse: loadedCourseRef,
      manifestVersions: manifestVersionsRef,
      pendingInitialIndex,
      pendingInitialKey,
      playbackDurations: playbackDurationRef,
      playbackRuntime: playbackRuntimeRef,
      positions: positionsRef,
    }),
    [],
  );
  const load = useReelsCourseLoader({
    navigation,
    params,
    previewMode,
    refs: loaderRefs,
    setConnectionNote,
    setCourse,
    setLoadError,
    setLoading,
    setPreviewGateVisible,
    setSavedLessons,
    setServerSession,
  });

  useEffect(() => {
    void load();
  }, [load]);

  const projectReviewRefs = useMemo(
    () => ({
      loadedCourse: loadedCourseRef,
      mounted: mountedRef,
      reviewWatcher: reviewWatcherRef,
      watchedProject: watchedProjectRef,
    }),
    [],
  );
  const {refreshProjectState, watchProjectUntilResolved} = useProjectReview({
    course,
    previewMode,
    refs: projectReviewRefs,
    setCourse,
  });

  useEffect(() => {
    if (!connectionNote) {
      return;
    }
    const timer = setTimeout(() => setConnectionNote(''), 4600);
    return () => clearTimeout(timer);
  }, [connectionNote]);

  useEffect(() => {
    if (!feedItems.length || !layout.height) {
      return;
    }
    let target = -1;
    if (pendingInitialKey.current) {
      target = feedItems.findIndex(
        item => item.key === pendingInitialKey.current,
      );
    } else if (pendingInitialIndex.current !== null) {
      target = Math.min(pendingInitialIndex.current, feedItems.length - 1);
    } else {
      return;
    }
    pendingInitialKey.current = null;
    pendingInitialIndex.current = null;
    if (target >= 0) {
      setCurrentIndex(target);
      requestAnimationFrame(() =>
        listRef.current?.scrollToOffset({
          offset: target * layout.height,
          animated: false,
        }),
      );
    }
  }, [feedItems, layout.height]);

  useEffect(() => {
    if (!layout.height || !feedLengthRef.current) {
      return;
    }
    requestAnimationFrame(() =>
      listRef.current?.scrollToOffset({
        offset:
          Math.min(
            currentIndexRef.current,
            Math.max(0, feedLengthRef.current - 1),
          ) * layout.height,
        animated: false,
      }),
    );
  }, [layout.height, layout.width]);

  const frameWidth = useMemo(() => resolveReelsFrameWidth(layout), [layout]);

  const scrollToIndex = useCallback(
    (index: number, animated = true) => {
      if (!layout.height || index < 0 || index >= feedLengthRef.current) {
        return;
      }
      listRef.current?.scrollToOffset({
        offset: layout.height * index,
        animated,
      });
      setCurrentIndex(index);
    },
    [layout.height],
  );

  const scrollToKey = useCallback(
    (key: string) => {
      const index = feedItems.findIndex(item => item.key === key);
      if (index >= 0) {
        scrollToIndex(index);
      }
    },
    [feedItems, scrollToIndex],
  );

  const progressRefs = useMemo(
    () => ({
      completionSent: completionSentRef,
      demoRewardsEnabled: demoRewardsEnabledRef,
      feedLength: feedLengthRef,
      lastPersisted: lastPersistedRef,
      pendingStudySeconds: pendingStudySecondsRef,
      playbackDurations: playbackDurationRef,
      playbackRuntime: playbackRuntimeRef,
      positions: positionsRef,
      studySample: studySampleRef,
    }),
    [],
  );
  const {completeAndAdvance, persistProgress} = useReelsProgress({
    autoplay,
    course,
    currentIndex,
    feedItems,
    maybeOfferReminders,
    playbackSpeed,
    previewMode,
    refs: progressRefs,
    scheduleDelayedAction,
    scrollToIndex,
    setChatVisible,
    setCourse,
    setPreviewGateVisible,
  });

  const toggleSaved = useCallback(
    async (reel: CourseReel, folder?: SavedFolderOption | null) => {
      if (savePendingRef.current.has(reel.lessonId)) {
        return;
      }
      savePendingRef.current.add(reel.lessonId);
      const currentlySaved = savedLessons.has(reel.lessonId);
      const shouldSave = Boolean(folder) || !currentlySaved;
      setSavedLessons(current => {
        const next = new Set(current);
        if (shouldSave) {
          next.add(reel.lessonId);
        } else {
          next.delete(reel.lessonId);
        }
        return next;
      });
      try {
        if (folder) {
          await saveLessonToFolder(reel.lessonId, folder);
        } else {
          await toggleWatchLater(reel.lessonId, currentlySaved);
        }
      } catch (error) {
        setSavedLessons(current => {
          const next = new Set(current);
          if (currentlySaved) next.add(reel.lessonId);
          else next.delete(reel.lessonId);
          return next;
        });
        setConnectionNote(
          'لم يتم تحديث المحفوظات. تأكد من الاتصال وحاول مرة أخرى.',
        );
        throw error;
      } finally {
        savePendingRef.current.delete(reel.lessonId);
      }
    },
    [savedLessons],
  );

  const submitProject = useCallback(
    async (projectId: string, file: SelectedProjectFile) => {
      const result = await submitProjectAttempt(projectId, file);
      if (result.passed) {
        if (course?.id.startsWith('demo')) {
          if (demoRewardsEnabledRef.current) {
            void claimDemoFirstProjectReward(projectId).catch(() => undefined);
          }
          const finalProject = [...course.modules]
            .reverse()
            .find(module => module.project)?.project;
          if (demoRewardsEnabledRef.current && finalProject?.id === projectId) {
            void claimDemoCourseCompletionReward(course.id).catch(
              () => undefined,
            );
          }
          setCourse(current =>
            current
              ? unlockAfterProject(current, projectId, 'passed')
              : current,
          );
          return {...result, canContinue: true};
        }

        // Unlock only after refreshed media entitlements arrive.
        const refreshed = await refreshProjectState(projectId);
        if (refreshed?.status === 'passed') {
          return {...result, canContinue: refreshed.canContinue};
        }
        setCourse(current =>
          current
            ? updateProjectStatusOnly(current, projectId, 'passed')
            : current,
        );
        watchProjectUntilResolved(projectId);
        return {...result, synced: false, canContinue: false};
      }

      if (result.provisional) {
        setCourse(current =>
          current
            ? updateProjectStatusOnly(current, projectId, 'reviewing')
            : current,
        );
        watchProjectUntilResolved(projectId);
      } else {
        setCourse(current =>
          current
            ? updateProjectStatusOnly(current, projectId, 'needs_retry')
            : current,
        );
      }
      return result;
    },
    [course, refreshProjectState, watchProjectUntilResolved],
  );

  const onViewableItemsChanged = useRef(
    ({viewableItems}: {viewableItems: ViewToken<CourseFeedItem>[]}) => {
      const visible = viewableItems.find(item => item.isViewable);
      if (typeof visible?.index === 'number') {
        if (visible.index !== currentIndexRef.current) {
          void flushPendingPlaybackPositions();
        }
        setCurrentIndex(visible.index);
      }
    },
  ).current;
  const viewabilityConfig = useRef({
    itemVisiblePercentThreshold: 70,
    minimumViewTime: 80,
  }).current;

  const handlePlaybackEvent = useCallback(
    (reel: CourseReel, event: PlaybackPlayerEvent) => {
      if (!reel.playbackSessionId) return;
      if (
        event.eventType === 'stop' ||
        (event.eventType === 'error' && event.endReason)
      ) {
        closedPlaybackSessionsRef.current.add(reel.playbackSessionId);
      }
      if (event.durationSeconds && event.durationSeconds > 0) {
        playbackDurationRef.current[reel.id] = event.durationSeconds;
      }
      positionsRef.current[`${course?.id || ''}:${reel.id}`] =
        event.positionSeconds;
      playbackRuntimeRef.current[reel.id] = {
        effectiveQuality: event.effectiveQuality,
        effectiveBitrateKbps: event.effectiveBitrateKbps,
        recoveryCount: event.recoveryCount,
        bufferCount: event.bufferCount,
        bufferDurationMs: event.bufferDurationMs,
        startupLatencyMs: event.startupLatencyMs,
        diagnostics: event.diagnostics,
      };
      void reportPlaybackSessionEvent({
        lessonId: reel.lessonId,
        playbackSessionId: reel.playbackSessionId,
        playbackRate: getPlaybackSpeed(),
        ...event,
      });
    },
    [course?.id, getPlaybackSpeed],
  );

  const handlePlaybackMetrics = useCallback(
    (reel: CourseReel, metrics: PlaybackRuntimeMetrics) => {
      playbackRuntimeRef.current[reel.id] = metrics;
    },
    [],
  );

  const renderItem = useReelsFeedRenderer({
    bottomInset: insets.bottom,
    changeFitMode,
    changePlaybackSpeed,
    changeQuality,
    completeAndAdvance,
    course,
    currentIndex,
    feedLength: feedItems.length,
    fitMode,
    frameWidth,
    handlePlaybackEvent,
    handlePlaybackMetrics,
    layout,
    load,
    navigation,
    persistProgress,
    playbackSpeed,
    positions: positionsRef,
    previewGateVisible,
    requestPlaybackManifest,
    savedLessons,
    scheduleDelayedAction,
    scrollToIndex,
    scrollToKey,
    selectedQuality,
    serverSession,
    setChatVisible,
    submitProject,
    toggleSaved,
    topInset: insets.top,
  });

  const showCourseDetails = useCallback(
    (openPurchase: boolean) => {
      if (!course) return;
      navigation.replace('CourseDetails', {
        courseId: params.courseId || course.id,
        coinPrice: params.coinPrice,
        title: params.title || course.title,
        description: params.description,
        openPurchase,
        resumeAfterPreview: openPurchase,
        previewCount: feedItems.length,
      });
    },
    [
      course,
      feedItems.length,
      navigation,
      params.coinPrice,
      params.courseId,
      params.description,
      params.title,
    ],
  );

  const onLayout = (event: LayoutChangeEvent) => {
    const {width, height} = event.nativeEvent.layout;
    if (
      width &&
      height &&
      (width !== layout.width || height !== layout.height)
    ) {
      setLayout({width, height});
    }
  };

  return (
    <View style={styles.screen} onLayout={onLayout}>
      <StatusBar
        translucent
        barStyle="light-content"
        backgroundColor="transparent"
      />
      {loading || (!course && !loadError) || !layout.height ? (
        <ReelsLoadingState />
      ) : loadError || !course ? (
        <ReelsUnavailableState
          message={loadError}
          onPrimary={() => void load()}
          onSecondary={() => navigation.goBack()}
          primaryLabel="إعادة المحاولة"
          secondaryLabel="العودة للكورسات"
          title="تعذر فتح الكورس"
        />
      ) : !feedItems.length ? (
        <ReelsUnavailableState
          message="لا توجد مقاطع منشورة أو مشروع مفتوح لهذا الكورس"
          onPrimary={() => void load()}
          onSecondary={() =>
            navigation.replace('CourseDetails', {
              courseId: params.courseId || DEMO_COURSE_ID,
            })
          }
          primaryLabel="تحديث المحتوى"
          secondaryLabel="فتح تفاصيل الكورس"
          title="لا يوجد مقطع متاح الآن"
        />
      ) : (
        <>
          <FlatList
            ref={listRef}
            data={feedItems}
            keyExtractor={item => item.key}
            renderItem={renderItem}
            pagingEnabled
            bounces={false}
            decelerationRate="fast"
            snapToInterval={layout.height}
            snapToAlignment="start"
            disableIntervalMomentum
            showsVerticalScrollIndicator={false}
            initialNumToRender={2}
            maxToRenderPerBatch={2}
            windowSize={3}
            removeClippedSubviews={Platform.OS === 'android'}
            getItemLayout={(_, index) => ({
              length: layout.height,
              offset: layout.height * index,
              index,
            })}
            viewabilityConfig={viewabilityConfig}
            onViewableItemsChanged={onViewableItemsChanged}
            onScrollToIndexFailed={info =>
              listRef.current?.scrollToOffset({
                offset: info.index * layout.height,
                animated: false,
              })
            }
          />
          {previewGateVisible && (
            <ReelsPreviewGate
              bottomInset={insets.bottom}
              onBackToDetails={() => showCourseDetails(false)}
              onStartLearning={() => showCourseDetails(true)}
              previewCount={feedItems.length}
              topInset={insets.top}
            />
          )}
          {!!connectionNote && !previewGateVisible && (
            <ReelsConnectionNote
              message={connectionNote}
              topInset={insets.top}
            />
          )}
          <CourseChatOverlay
            visible={chatVisible}
            course={course}
            reel={currentReel}
            onClose={() => setChatVisible(false)}
          />
          <NotificationPermissionPrimer
            onClose={closeReminderNudge}
            onEnable={enableRemindersFromNudge}
            visible={reminderNudgeVisible}
          />
        </>
      )}
    </View>
  );
};

export default Reels;

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: '#000000',
  },
});
