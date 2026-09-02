import {useIsFocused, useNavigation, useRoute} from '@react-navigation/native';
import React, {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {
  FlatList,
  LayoutChangeEvent,
  StatusBar,
  StyleSheet,
  View,
  ViewToken,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {goBackOrHome} from '../navigation/RootNavigationHelper';
import CourseChatOverlay from '../components/VideoPlayer/CourseChatOverlay';
import {
  flushPendingPlaybackPositions,
  persistLocalPlaybackPosition,
  reportPlaybackSessionEvent,
  saveLessonToFolder,
  SavedFolderOption,
  subscribeCourseRevisionChanges,
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
  claimDemoCourseCompletionReward,
  claimDemoFirstProjectReward,
  DEMO_COURSE_ID,
} from '../services/demoExperience';
import {isLocalDemoId, LOCAL_DEMO_ENABLED} from '../config/runtime';
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
  markQuizPassed,
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
import {selectPrimaryViewableItem} from '../components/VideoPlayer/courseLearning/viewability';
import {useReelsProgress} from './reels/useReelsProgress';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  extractApiToken,
  extractUserProfile,
} from '../constants/helpers';
import {useSelector} from 'react-redux';
import type {RootState} from '../store/store';

const Reels = () => {
  const route = useRoute();
  const navigation = useNavigation<ReelsNavigation>();
  const isScreenFocused = useIsFocused();
  const params = (route.params || {}) as ReelsRouteParams;
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const storedProfile = extractUserProfile(storedUser);
  const hasStoredToken = Boolean(extractApiToken(storedUser));
  const identityKey = hasStoredToken
    ? String(storedProfile.id ?? storedProfile.user_id ?? 'authenticated')
    : 'guest';
  const previewMode = params.preview === true;
  const requestedCourseId = String(
    params.courseId || (LOCAL_DEMO_ENABLED ? DEMO_COURSE_ID : ''),
  );
  const requestedCourseViewKey = `${requestedCourseId}:${
    previewMode ? 'preview' : 'learning'
  }`;
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
  const frameHeightRef = useRef(0);
  const scrollOffsetRef = useRef(0);
  const scrollDirectionRef = useRef<1 | -1>(1);
  const [layout, setLayout] = useState({width: 0, height: 0});
  const [loadedCourse, setCourse] = useState<CourseLearningData | null>(null);
  const loadedCourseOwnerRef = useRef(identityKey);
  const requestedCourseRef = useRef(requestedCourseViewKey);
  const course =
    loadedCourseOwnerRef.current === identityKey &&
    requestedCourseRef.current === requestedCourseViewKey &&
    String(loadedCourse?.id || '') === requestedCourseId
      ? loadedCourse
      : null;
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [connectionNote, setConnectionNote] = useState('');
  const [courseRevisionRefreshing, setCourseRevisionRefreshing] =
    useState(false);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  frameHeightRef.current = layout.height;
  const {
    autoplay,
    changePlaybackSpeed,
    changeQuality,
    dataSaver,
    getPlaybackSpeed,
    playbackPreferencesReady,
    playbackSpeed,
    selectedQuality,
  } = usePlaybackPreferences(serverSession, identityKey);
  const [manifestRefreshNonce, setManifestRefreshNonce] = useState(0);
  const [savedLessons, setSavedLessons] = useState<Set<string>>(new Set());
  const [savingLessons, setSavingLessons] = useState<Set<string>>(new Set());
  const [chatVisible, setChatVisible] = useState(false);

  useEffect(() => {
    if (!isScreenFocused || !course || params.openCourseChatUpgrade !== true) return;
    setChatVisible(true);
    navigation.setParams({openCourseChatUpgrade: false});
  }, [course, isScreenFocused, navigation, params.openCourseChatUpgrade]);
  const [contentOverlayVisible, setContentOverlayVisible] = useState(false);
  const contentOverlayScopesRef = useRef(new Set<string>());
  const handleContentOverlayVisibility = useCallback(
    (scopeKey: string, visible: boolean) => {
      if (visible) {
        contentOverlayScopesRef.current.add(scopeKey);
      } else {
        contentOverlayScopesRef.current.delete(scopeKey);
      }
      setContentOverlayVisible(contentOverlayScopesRef.current.size > 0);
    },
    [],
  );
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
  const manifestFlightsRef = useRef(new Map<string, Promise<void>>());
  const manifestVersionsRef = useRef<Record<string, number>>({});
  const courseRevisionReloadRef = useRef<Promise<void> | null>(null);
  const courseRevisionPendingRef = useRef(false);
  const routeNavigationFlightRef = useRef(false);
  const courseRevisionReloadHandlerRef = useRef<
    (lessonId?: string) => void
  >(() => undefined);
  const playbackRuntimeRef = useRef<Record<string, PlaybackRuntimeMetrics>>({});
  const playbackDurationRef = useRef<Record<string, number>>({});
  const closedPlaybackSessionsRef = useRef(new Set<string>());
  const activeReelRef = useRef<CourseReel | undefined>(undefined);
  const loadedCourseRef = useRef<CourseLearningData | null>(null);
  const accountViewGenerationRef = useRef(0);
  const loadRequestRef = useRef(0);
  const loadAbortRef = useRef<AbortController | null>(null);
  const mountedRef = useRef(true);
  const delayedActionsRef = useRef(new Set<ReturnType<typeof setTimeout>>());

  useEffect(() => {
    // Stack navigation can reuse this mounted route after either an account
    // or course change. Everything below belongs to that complete view scope.
    requestedCourseRef.current = requestedCourseViewKey;
    loadRequestRef.current += 1;
    loadAbortRef.current?.abort();
    loadAbortRef.current = null;
    reviewWatcherRef.current += 1;
    accountViewGenerationRef.current += 1;
    loadedCourseRef.current = null;
    loadedCourseOwnerRef.current = identityKey;
    positionsRef.current = {};
    lastPersistedRef.current = {};
    completionSentRef.current.clear();
    savePendingRef.current.clear();
    manifestFlightsRef.current.clear();
    manifestVersionsRef.current = {};
    courseRevisionReloadRef.current = null;
    courseRevisionPendingRef.current = false;
    routeNavigationFlightRef.current = false;
    playbackRuntimeRef.current = {};
    playbackDurationRef.current = {};
    closedPlaybackSessionsRef.current.clear();
    watchedProjectRef.current = null;
    pendingStudySecondsRef.current = 0;
    studySampleRef.current = null;
    pendingInitialKey.current = null;
    pendingInitialIndex.current = null;
    delayedActionsRef.current.forEach(timer => clearTimeout(timer));
    delayedActionsRef.current.clear();
    contentOverlayScopesRef.current.clear();
    currentIndexRef.current = 0;
    scrollOffsetRef.current = 0;
    setCourse(null);
    setLoading(true);
    setLoadError('');
    setServerSession(null);
    setSavedLessons(new Set());
    setSavingLessons(new Set());
    setConnectionNote('');
    setCourseRevisionRefreshing(false);
    setChatVisible(false);
    setContentOverlayVisible(false);
    setPreviewGateVisible(false);
    setCurrentIndex(0);
  }, [identityKey, requestedCourseViewKey]);
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
      loadAbort: loadAbortRef,
      loadRequest: loadRequestRef,
      mounted: mountedRef,
      playbackDurations: playbackDurationRef,
      playbackRuntime: playbackRuntimeRef,
      positions: positionsRef,
      reviewWatcher: reviewWatcherRef,
    }),
    [],
  );
  const refreshPlaybackAfterForeground = useCallback(
    () => setManifestRefreshNonce(value => value + 1),
    [],
  );
  const appIsActive = useReelsLifecycle(
    lifecycleRefs,
    getPlaybackSpeed,
    refreshPlaybackAfterForeground,
  );

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
      ownerGeneration: accountViewGenerationRef,
      positions: positionsRef,
      revisionReloadPending: courseRevisionPendingRef,
      runtime: playbackRuntimeRef,
      versions: manifestVersionsRef,
    }),
    [],
  );
  const handleCourseRevisionChanged = useCallback(() => {
    courseRevisionReloadHandlerRef.current();
  }, []);
  const requestPlaybackManifest = usePlaybackManifest({
    courseId: course?.id,
    dataSaver,
    getPlaybackSpeed,
    onCourseRevisionChanged: handleCourseRevisionChanged,
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
    if (
      !currentReel ||
      previewGateVisible ||
      !isScreenFocused ||
      !appIsActive
    ) {
      return;
    }
    const sessionId = currentReel.playbackSessionId;
    const sessionWasClosed = Boolean(
      sessionId && closedPlaybackSessionsRef.current.has(sessionId),
    );
    if (!sessionId || sessionWasClosed) {
      requestPlaybackManifest(currentReel, sessionId, !sessionWasClosed);
    }
  }, [
    currentReel,
    appIsActive,
    isScreenFocused,
    manifestRefreshNonce,
    previewGateVisible,
    requestPlaybackManifest,
  ]);

  useEffect(() => {
    if (
      !currentReel?.playbackSessionId ||
      !isScreenFocused ||
      !appIsActive
    ) {
      return;
    }
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
  }, [
    currentReel,
    appIsActive,
    isScreenFocused,
    manifestRefreshNonce,
    requestPlaybackManifest,
  ]);

  const loaderRefs = useMemo(
    () => ({
      closedPlaybackSessions: closedPlaybackSessionsRef,
      demoRewardsEnabled: demoRewardsEnabledRef,
      loadAbort: loadAbortRef,
      loadRequest: loadRequestRef,
      loadedCourse: loadedCourseRef,
      loadedCourseOwner: loadedCourseOwnerRef,
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
    identityKey,
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

  const reloadPublishedCourse = useCallback(
    (lessonId?: string) => {
      if (courseRevisionReloadRef.current) return;
      courseRevisionPendingRef.current = true;
      setCourseRevisionRefreshing(true);
      Object.keys(manifestVersionsRef.current).forEach(key => {
        manifestVersionsRef.current[key] =
          (manifestVersionsRef.current[key] || 0) + 1;
      });
      manifestFlightsRef.current.clear();
      closedPlaybackSessionsRef.current.clear();
      setConnectionNote('تم تحديث الكورس\nنعرض أحدث نسخة');
      let succeeded = false;
      const flight = load({
        lessonId: lessonId || activeReelRef.current?.lessonId,
        index: currentIndexRef.current,
        onResult: result => {
          succeeded = result;
        },
      }).finally(() => {
        if (courseRevisionReloadRef.current === flight) {
          courseRevisionReloadRef.current = null;
          if (succeeded) {
            courseRevisionPendingRef.current = false;
          }
          if (!mountedRef.current) return;
          if (succeeded) {
            setCourseRevisionRefreshing(false);
          } else {
            setConnectionNote('تغيّر محتوى الكورس\nاضغط لإعادة التحميل');
          }
        }
      });
      courseRevisionReloadRef.current = flight;
    },
    [load],
  );
  courseRevisionReloadHandlerRef.current = reloadPublishedCourse;

  useEffect(
    () =>
      subscribeCourseRevisionChanges(change => {
        const loaded = loadedCourseRef.current;
        const ownsSourceLesson = Boolean(
          change.sourceLessonId &&
            loaded?.modules.some(module =>
              module.reels.some(
                reel => reel.lessonId === change.sourceLessonId,
              ),
            ),
        );
        if (String(loaded?.id || '') !== change.courseId && !ownsSourceLesson) {
          return;
        }
        reloadPublishedCourse(change.currentLessonId);
      }),
    [reloadPublishedCourse],
  );

  useEffect(() => {
    void load();
  }, [load]);

  const projectReviewRefs = useMemo(
    () => ({
      loadedCourse: loadedCourseRef,
      mounted: mountedRef,
      ownerGeneration: accountViewGenerationRef,
      reviewWatcher: reviewWatcherRef,
      watchedProject: watchedProjectRef,
    }),
    [],
  );
  const {refreshProjectState, watchProjectUntilResolved} = useProjectReview({
    active: isScreenFocused,
    course,
    previewMode,
    refs: projectReviewRefs,
    setCourse,
  });

  useEffect(() => {
    if (!connectionNote || courseRevisionRefreshing) {
      return;
    }
    const timer = setTimeout(() => setConnectionNote(''), 4600);
    return () => clearTimeout(timer);
  }, [connectionNote, courseRevisionRefreshing]);

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
      ownerGeneration: accountViewGenerationRef,
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
      const ownerCourseId = loadedCourseRef.current?.id;
      if (!ownerCourseId) return;
      const operationKey = `${ownerCourseId}:${reel.lessonId}`;
      if (savePendingRef.current.has(operationKey)) {
        return;
      }
      savePendingRef.current.add(operationKey);
      setSavingLessons(current => new Set(current).add(reel.lessonId));
      const currentlySaved = savedLessons.has(reel.lessonId);
      const shouldSave = Boolean(folder) || !currentlySaved;
      let boundary: Awaited<
        ReturnType<typeof captureAccountSessionBoundary>
      > | null = null;
      const stillOwned = () => {
        if (!boundary) return false;
        try {
          assertAccountSessionBoundary(boundary);
          return (
            mountedRef.current &&
            loadedCourseRef.current?.id === ownerCourseId
          );
        } catch {
          return false;
        }
      };
      try {
        boundary = await captureAccountSessionBoundary();
        if (loadedCourseRef.current?.id !== ownerCourseId) return;
        if (folder) {
          await saveLessonToFolder(reel.lessonId, folder);
        } else {
          await toggleWatchLater(reel.lessonId, currentlySaved);
        }
        assertAccountSessionBoundary(boundary);
        if (!stillOwned()) return;
        setSavedLessons(current => {
          const next = new Set(current);
          if (shouldSave) next.add(reel.lessonId);
          else next.delete(reel.lessonId);
          return next;
        });
      } catch (error) {
        if (stillOwned()) {
          setConnectionNote(
            'تعذّر تحديث المحفوظات\nتحقق من الاتصال ثم حاول مرة أخرى',
          );
        }
        throw error;
      } finally {
        if (
          mountedRef.current &&
          loadedCourseRef.current?.id === ownerCourseId
        ) {
          setSavingLessons(current => {
            const next = new Set(current);
            next.delete(reel.lessonId);
            return next;
          });
        }
        savePendingRef.current.delete(operationKey);
      }
    },
    [savedLessons],
  );

  const submitProject = useCallback(
    async (projectId: string, files: SelectedProjectFile[], note?: string) => {
      const result = await submitProjectAttempt(projectId, files, note);
      if (result.passed) {
        if (course && isLocalDemoId(course.id)) {
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

  const passQuiz = useCallback(
    async (quizId: string) => {
      setCourse(current =>
        current ? markQuizPassed(current, quizId) : current,
      );
      await load();
    },
    [load],
  );

  const renderedAccountViewGeneration = accountViewGenerationRef.current;
  const onViewableItemsChanged = useCallback(
    ({viewableItems}: {viewableItems: ViewToken<CourseFeedItem>[]}) => {
      if (
        accountViewGenerationRef.current !== renderedAccountViewGeneration
      ) {
        return;
      }
      const height = Math.max(1, frameHeightRef.current);
      const visible = selectPrimaryViewableItem(
        viewableItems,
        scrollOffsetRef.current,
        height,
        scrollDirectionRef.current,
      );
      if (typeof visible?.index === 'number') {
        if (visible.index !== currentIndexRef.current) {
          void flushPendingPlaybackPositions();
        }
        setCurrentIndex(visible.index);
      }
    },
    [renderedAccountViewGeneration],
  );
  const viewabilityConfig = useRef({
    itemVisiblePercentThreshold: 70,
    minimumViewTime: 80,
  }).current;

  const handlePlaybackEvent = useCallback(
    (reel: CourseReel, event: PlaybackPlayerEvent) => {
      if (
        accountViewGenerationRef.current !== renderedAccountViewGeneration
      ) {
        return;
      }
      if (!reel.playbackSessionId) return;
      if (
        event.eventType === 'stop' ||
        (event.eventType === 'error' && event.endReason)
      ) {
        closedPlaybackSessionsRef.current.add(reel.playbackSessionId);
        while (closedPlaybackSessionsRef.current.size > 64) {
          const oldest = closedPlaybackSessionsRef.current
            .values()
            .next().value;
          if (typeof oldest !== 'string') break;
          closedPlaybackSessionsRef.current.delete(oldest);
        }
      }
      if (event.durationSeconds && event.durationSeconds > 0) {
        playbackDurationRef.current[reel.id] = event.durationSeconds;
      }
      positionsRef.current[`${course?.id || ''}:${reel.id}`] =
        event.positionSeconds;
      if (event.eventType === 'stop' && course?.id) {
        void persistLocalPlaybackPosition(
          course.id,
          reel.id,
          event.positionSeconds,
        ).catch(() => undefined);
      }
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
    [course?.id, getPlaybackSpeed, renderedAccountViewGeneration],
  );

  const handlePlaybackMetrics = useCallback(
    (reel: CourseReel, metrics: PlaybackRuntimeMetrics) => {
      if (
        accountViewGenerationRef.current !== renderedAccountViewGeneration
      ) {
        return;
      }
      playbackRuntimeRef.current[reel.id] = metrics;
    },
    [renderedAccountViewGeneration],
  );

  const renderItem = useReelsFeedRenderer({
    bottomInset: insets.bottom,
    changePlaybackSpeed,
    changeQuality,
    completeAndAdvance,
    course,
    currentIndex,
    feedLength: feedItems.length,
    frameWidth,
    handlePlaybackEvent,
    handlePlaybackMetrics,
    layout,
    load,
    navigation,
    persistProgress,
    playbackSpeed,
    playbackBlocked:
      !isScreenFocused ||
      chatVisible ||
      reminderNudgeVisible ||
      contentOverlayVisible ||
      courseRevisionRefreshing,
    preloadNext:
      isScreenFocused &&
      !chatVisible &&
      !reminderNudgeVisible &&
      !previewGateVisible &&
      !contentOverlayVisible &&
      !courseRevisionRefreshing &&
      !dataSaver,
    positions: positionsRef,
    preview: params.preview === true,
    previewCount: params.previewCount,
    previewGateVisible,
    requestPlaybackManifest,
    screenFocused: isScreenFocused,
    savedLessons,
    savingLessons,
    scheduleDelayedAction,
    scrollToIndex,
    scrollToKey,
    selectedQuality,
    serverSession,
    setChatVisible,
    onContentOverlayVisibilityChange: handleContentOverlayVisibility,
    submitProject,
    passQuiz,
    toggleSaved,
    topInset: insets.top,
  });

  const showCourseDetails = useCallback(
    (openPurchase: boolean) => {
      if (!course || routeNavigationFlightRef.current) return;
      routeNavigationFlightRef.current = true;
      const currentFeedItem = feedItems[currentIndex];
      const resumeReelId =
        currentFeedItem?.type === 'reel'
          ? String(currentFeedItem.reel.id)
          : undefined;
      navigation.replace('CourseDetails', {
        courseId: params.courseId || course.id,
        coinPrice: params.coinPrice,
        title: params.title || course.title,
        description: params.description,
        openPurchase,
        resumeAfterPreview: openPurchase,
        resumeReelId,
      });
    },
    [
      course,
      currentIndex,
      feedItems,
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

  // A rotation, split-screen resize or fold/unfold changes the paging unit.
  // Re-anchor the same logical item instead of leaving the list between reels.
  useEffect(() => {
    if (!layout.height || !feedItems.length) return;
    const frame = requestAnimationFrame(() => {
      listRef.current?.scrollToOffset({
        animated: false,
        offset: currentIndexRef.current * layout.height,
      });
    });
    return () => cancelAnimationFrame(frame);
  }, [feedItems.length, layout.height, layout.width]);

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
          onSecondary={() => goBackOrHome(navigation)}
          primaryLabel="إعادة المحاولة"
          secondaryLabel="العودة للكورسات"
          title="تعذّر فتح الكورس"
        />
      ) : !feedItems.length ? (
        <ReelsUnavailableState
          message="لا توجد مقاطع منشورة أو مشروع مفتوح لهذا الكورس"
          onPrimary={() => void load()}
          onSecondary={() =>
            navigation.replace('CourseDetails', {
              courseId: course.id,
            })
          }
          primaryLabel="تحديث المحتوى"
          secondaryLabel="فتح تفاصيل الكورس"
          title="لا يوجد مقطع متاح الآن"
        />
      ) : (
        <>
          <FlatList
            accessibilityLabel="مقاطع الكورس"
            key={`reels:${identityKey}:${course.id}`}
            ref={listRef}
            data={feedItems}
            keyExtractor={item => item.key}
            renderItem={renderItem}
            pagingEnabled
            scrollEnabled={
              !chatVisible &&
              !reminderNudgeVisible &&
              !previewGateVisible &&
              !contentOverlayVisible &&
              !courseRevisionRefreshing
            }
            bounces={false}
            decelerationRate="fast"
            snapToInterval={layout.height}
            snapToAlignment="start"
            disableIntervalMomentum
            showsVerticalScrollIndicator={false}
            initialNumToRender={2}
            maxToRenderPerBatch={2}
            windowSize={3}
            // Video surfaces must stay attached when a wide Android tablet
            // row is recycled; clipping can return audio over a black frame.
            removeClippedSubviews={false}
            getItemLayout={(_, index) => ({
              length: layout.height,
              offset: layout.height * index,
              index,
            })}
            viewabilityConfig={viewabilityConfig}
            onViewableItemsChanged={onViewableItemsChanged}
            scrollEventThrottle={16}
            onScroll={event => {
              const nextOffset = event.nativeEvent.contentOffset.y;
              if (Math.abs(nextOffset - scrollOffsetRef.current) > 1) {
                scrollDirectionRef.current = nextOffset > scrollOffsetRef.current ? 1 : -1;
              }
              scrollOffsetRef.current = nextOffset;
            }}
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
              onPress={
                courseRevisionRefreshing
                  ? () => reloadPublishedCourse(activeReelRef.current?.lessonId)
                  : undefined
              }
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
