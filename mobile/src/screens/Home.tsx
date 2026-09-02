import {
  CommonActions,
  useIsFocused,
  useNavigation,
} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import React, {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {
  Image,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import {useTranslation} from 'react-i18next';
import {NotificationIcon, SearchIcon} from '../assets/SVG';
import TabBar from '../components/TabBar';
import {Container, Content} from '../components/containers/Containers';
import {ResponsiveFrame, StatusView} from '../components/ui/PremiumUI';
import {CatalogueSkeleton} from '../components/ui/Skeleton';
import CourseCarousel from '../components/view/CourseCarousel';
import CoursesSection from '../components/view/CoursesSection';
import {
  Accessibility,
  fixedIconSlot,
  flexibleTextColumn,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../constants/designSystem';
import {courseSections, demoCourses, DemoCourse} from '../data/demoContent';
import {
  DEMO_COURSE_ID,
  DEMO_COURSE_PRICE,
  DemoExperienceState,
  claimDemoDailyReward,
  subscribeDemoExperience,
} from '../services/demoExperience';
import {
  claimDailyReward,
  getNotifications,
  markNotificationRead,
} from '../services/roknApi';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  extractApiToken,
  extractUserProfile,
  getItem,
  normalizeText,
  saveItem,
  type AccountSessionBoundary,
} from '../constants/helpers';
import SearchAssist from '../components/search/SearchAssist';
import {LOCAL_DEMO_ENABLED} from '../config/runtime';
import {
  clearSearchHistory,
  getSearchHistory,
  rememberSearch,
} from '../services/searchHistory';
import {parseRoknDestination} from '../navigation/deepLinks';
import {trackProductEvent} from '../services/productAnalytics';
import {
  buildHomeSections,
  buildQuickSearches,
  searchHomeCatalogue,
  selectHeroCourses,
  selectHomeRecommendations,
} from './home/homeCatalogue';
import {useHomeCatalogue} from './home/useHomeCatalogue';
import {HomeCampaign, HomeOverlays} from './home/HomeOverlays';
import {
  EngagementMessage,
  getEngagementMessage,
  getNextEngagementMessage,
} from '../services/api/engagement';
import {
  clearPendingWelcomeBonus,
  getPendingWelcomeBonus,
} from '../services/pendingWelcomeBonus';
import {serverNowMs} from '../utils/serverClock';
import {openGuestLogin} from '../navigation/journeyNavigation';
import {useAppActiveState} from '../hooks/useAppActiveState';
import {useSelector} from 'react-redux';
import type {RootState} from '../store/store';

const QUICK_SEARCHES = [
  'العمل الحر',
  'التسويق',
  'التصميم',
  'صناعة المحتوى',
  'اللغات',
];

const homeReceiptKey = (path: string, boundary?: AccountSessionBoundary) =>
  accountScopedStorageKey(`@rokn/home-receipt/${path}`, boundary);
const homeScrollKey = (boundary?: AccountSessionBoundary) =>
  accountScopedStorageKey('@rokn/home-scroll/v1', boundary);

let pendingGuestHomeExplorationHandoff: {
  scope: string;
  offset?: number;
  query?: string;
  createdAt: number;
} | null = null;

const saveHomeReceipt = async (path: string, value: unknown) => {
  const boundary = await captureAccountSessionBoundary();
  await saveItem(await homeReceiptKey(path, boundary), value);
  assertAccountSessionBoundary(boundary);
};

const isWithinCooldown = (receipt: unknown, cooldownHours: number) => {
  if (receipt === true) return true;
  const shownAt = Number(receipt || 0);
  if (!Number.isFinite(shownAt) || shownAt <= 0) return false;
  const elapsed = serverNowMs() - shownAt;
  return elapsed >= 0 && elapsed < Math.max(1, cooldownHours) * 60 * 60 * 1000;
};

const Home = () => {
  const navigation = useNavigation<RootNavigation>();
  const screenFocused = useIsFocused();
  const appIsActive = useAppActiveState();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const storedProfile = extractUserProfile(storedUser);
  const hasStoredToken = Boolean(extractApiToken(storedUser));
  const identityKey = hasStoredToken
    ? String(storedProfile.id ?? storedProfile.user_id ?? 'authenticated')
    : 'guest';
  const {t} = useTranslation();
  const [searchQuery, setSearchQuery] = useState('');
  const [searchFocused, setSearchFocused] = useState(false);
  const [searchHistory, setSearchHistory] = useState<string[]>([]);
  const [experience, setExperience] = useState<DemoExperienceState | null>(
    null,
  );
  const [welcomeBonus, setWelcomeBonus] = useState<number | null>(null);
  const [bonusChecked, setBonusChecked] = useState(false);
  const [campaign, setCampaign] = useState<HomeCampaign | null>(null);
  const [campaignImageFailed, setCampaignImageFailed] = useState(false);
  const [guestPrompt, setGuestPrompt] = useState<EngagementMessage | null>(
    null,
  );
  const [welcomeMessage, setWelcomeMessage] =
    useState<EngagementMessage | null>(null);
  const [rewardPrompt, setRewardPrompt] = useState<EngagementMessage | null>(
    null,
  );
  const [scrollRestoreOffset, setScrollRestoreOffset] = useState<number | null>(
    null,
  );
  const searchBlurTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const searchHistoryGenerationRef = useRef(0);
  const searchQueryRef = useRef(searchQuery);
  const homeScrollRef = useRef<ScrollView | null>(null);
  const scrollSaveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const homeScrollBoundaryRef = useRef<AccountSessionBoundary | null>(null);
  const homeScrollBoundaryFlightRef = useRef<
    Promise<AccountSessionBoundary> | null
  >(null);
  const homeScrollBoundaryGenerationRef = useRef(0);
  const latestHomeScrollOffsetRef = useRef<number | null>(null);
  const homeScrollUserMovedRef = useRef(false);
  const homeScrollWriteTailRef = useRef<Promise<void>>(Promise.resolve());
  const dailyRewardFlightRef = useRef<{
    identityKey: string;
    promise: Promise<boolean>;
  } | null>(null);
  const courseNavigationFlightRef = useRef(false);
  searchQueryRef.current = searchQuery;

  useEffect(() => {
    if (screenFocused) courseNavigationFlightRef.current = false;
  }, [screenFocused]);

  const openCourseDetailsOnce = useCallback(
    (course: Pick<DemoCourse, 'id' | 'coinPrice' | 'title' | 'description'>) => {
      if (courseNavigationFlightRef.current) return false;
      courseNavigationFlightRef.current = true;
      navigation.navigate('CourseDetails', {
        courseId: course.id,
        coinPrice: course.coinPrice,
        title: course.title,
        description: course.description,
      });
      return true;
    },
    [navigation],
  );

  useEffect(() => {
    homeScrollBoundaryGenerationRef.current += 1;
    homeScrollBoundaryRef.current = null;
    homeScrollBoundaryFlightRef.current = null;
    homeScrollUserMovedRef.current = false;
  }, [identityKey]);

  const getHomeScrollBoundary = useCallback(() => {
    if (homeScrollBoundaryRef.current) {
      return Promise.resolve(homeScrollBoundaryRef.current);
    }
    if (homeScrollBoundaryFlightRef.current) {
      return homeScrollBoundaryFlightRef.current;
    }
    const generation = homeScrollBoundaryGenerationRef.current;
    const flight = captureAccountSessionBoundary()
      .then(boundary => {
        if (generation !== homeScrollBoundaryGenerationRef.current) {
          throw new Error('HOME_SCROLL_OWNER_CHANGED');
        }
        homeScrollBoundaryRef.current = boundary;
        return boundary;
      })
      .catch(error => {
        if (homeScrollBoundaryFlightRef.current === flight) {
          homeScrollBoundaryFlightRef.current = null;
        }
        throw error;
      });
    homeScrollBoundaryFlightRef.current = flight;
    return flight;
  }, []);

  const persistLatestHomeScroll = useCallback(() => {
    const offset = latestHomeScrollOffsetRef.current;
    if (offset === null || !Number.isFinite(offset)) return;
    void getHomeScrollBoundary()
      .then(owner => {
        const write = homeScrollWriteTailRef.current
          .catch(() => undefined)
          .then(async () => {
            assertAccountSessionBoundary(owner);
            await saveItem(await homeScrollKey(owner), offset);
            assertAccountSessionBoundary(owner);
          });
        homeScrollWriteTailRef.current = write.catch(() => undefined);
        return write;
      })
      .catch(() => undefined);
  }, [getHomeScrollBoundary]);

  const demoCourse = useMemo<DemoCourse>(
    () => ({
      ...demoCourses[0],
      coinPrice: DEMO_COURSE_PRICE,
      owned: !!experience?.purchasedCourseIds.includes(DEMO_COURSE_ID),
      progress: undefined,
    }),
    [experience?.purchasedCourseIds],
  );

  const demoCatalogue = useMemo(
    () =>
      demoCourses.map(course =>
        course.id === DEMO_COURSE_ID ? demoCourse : course,
      ),
    [demoCourse],
  );

  const {
    browseCatalogue,
    catalogue,
    error: catalogueError,
    handleScroll: handleCatalogueScroll,
    loadMore: loadMoreCatalogue,
    loading: catalogueLoading,
    loadMoreError,
    loadedSearchQuery,
    serverSession,
    refresh: refreshCatalogue,
    remoteCourses,
    staleNotice,
    usingLocalDemo,
  } = useHomeCatalogue({
    active: screenFocused,
    demoCatalogue,
    identityKey,
    searchQuery,
  });

  useEffect(() => {
    let active = true;
    const historyGeneration = ++searchHistoryGenerationRef.current;
    // Home survives guest -> login and account switches. Search history is
    // account-owned, so reload it with the identity instead of retaining the
    // previous owner's chips until the screen is remounted.
    setSearchHistory([]);
    void getSearchHistory()
      .then(history => {
        if (
          active &&
          historyGeneration === searchHistoryGenerationRef.current
        ) {
          setSearchHistory(history);
        }
      })
      .catch(() => undefined);

    return () => {
      active = false;
      searchHistoryGenerationRef.current += 1;
    };
  }, [identityKey]);

  useEffect(() => {
    let active = true;
    void trackProductEvent({event_name: 'home_viewed', screen_key: 'home'});
    const unsubscribe = LOCAL_DEMO_ENABLED
      ? subscribeDemoExperience(state => {
          if (active) setExperience(state);
        })
      : () => undefined;
    return () => {
      active = false;
      unsubscribe();
      if (searchBlurTimerRef.current) {
        clearTimeout(searchBlurTimerRef.current);
      }
      if (scrollSaveTimerRef.current) {
        clearTimeout(scrollSaveTimerRef.current);
      }
      const owner = homeScrollBoundaryRef.current;
      const offset = latestHomeScrollOffsetRef.current;
      const activeQuery = searchQueryRef.current;
      if (
        owner?.scope.startsWith('guest-') &&
        ((offset !== null && Number.isFinite(offset)) || activeQuery.trim())
      ) {
        pendingGuestHomeExplorationHandoff = {
          scope: owner.scope,
          ...(offset !== null && Number.isFinite(offset)
            ? {offset: Math.max(0, offset)}
            : {}),
          ...(activeQuery.trim() ? {query: activeQuery.slice(0, 240)} : {}),
          createdAt: Date.now(),
        };
      }
      persistLatestHomeScroll();
    };
  }, [persistLatestHomeScroll]);

  useEffect(() => {
    let active = true;
    void getHomeScrollBoundary()
      .then(async boundary => {
        let offset = await getItem<number>(await homeScrollKey(boundary));
        assertAccountSessionBoundary(boundary);
        const handoff = pendingGuestHomeExplorationHandoff;
        const canAdoptHandoff = Boolean(
          boundary.scope.startsWith('user-') &&
            handoff?.scope.startsWith('guest-') &&
            handoff.scope !== boundary.scope &&
            Date.now() - handoff.createdAt < 5 * 60 * 1000,
        );
        if (
          canAdoptHandoff &&
          handoff &&
          Number.isFinite(Number(handoff.offset))
        ) {
          offset = Number(handoff.offset);
          await saveItem(await homeScrollKey(boundary), offset);
          assertAccountSessionBoundary(boundary);
        }
        if (canAdoptHandoff && handoff?.query) {
          setSearchQuery(handoff.query);
        }
        if (boundary.scope.startsWith('user-')) {
          pendingGuestHomeExplorationHandoff = null;
        }
        if (
          !active ||
          homeScrollUserMovedRef.current ||
          !Number.isFinite(Number(offset))
        )
          return;
        const restoredOffset = Math.max(0, Number(offset));
        latestHomeScrollOffsetRef.current = restoredOffset;
        setScrollRestoreOffset(restoredOffset);
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [getHomeScrollBoundary]);

  useEffect(() => {
    if (screenFocused && appIsActive) return;
    if (scrollSaveTimerRef.current) {
      clearTimeout(scrollSaveTimerRef.current);
      scrollSaveTimerRef.current = null;
    }
    persistLatestHomeScroll();
  }, [appIsActive, persistLatestHomeScroll, screenFocused]);

  const commitSearch = useCallback((rawQuery: string) => {
    const query = rawQuery.trim().replace(/\s+/g, ' ');
    if (!query) return;
    const historyGeneration = ++searchHistoryGenerationRef.current;
    setSearchQuery(query);
    setSearchFocused(false);
    void rememberSearch(query)
      .then(history => {
        if (historyGeneration === searchHistoryGenerationRef.current) {
          setSearchHistory(history);
        }
      })
      // An account can change while AsyncStorage is in flight. The storage
      // boundary deliberately rejects that stale write; it must not become an
      // unhandled promise rejection that destabilizes the home screen.
      .catch(() => undefined);
    void trackProductEvent({
      event_name: 'search_submitted',
      screen_key: 'search',
      value: Math.min(query.length, 200),
    });
  }, []);

  const clearRecentSearches = useCallback(() => {
    searchHistoryGenerationRef.current += 1;
    setSearchHistory([]);
    void clearSearchHistory().catch(() => undefined);
  }, []);

  useEffect(() => {
    // Account rewards come from the API; the local demo mirrors the event shape.
    if (!screenFocused || !appIsActive) return;
    if (serverSession === true) {
      let active = true;
      let timer: ReturnType<typeof setTimeout> | null = null;
      let retryIndex = 0;
      const retryDelays = [5_000, 20_000, 60_000];
      const attempt = () => {
        const existing = dailyRewardFlightRef.current;
        const promise =
          existing?.identityKey === identityKey
            ? existing.promise
            : claimDailyReward().then(
                () => true,
                () => false,
              );
        if (existing?.identityKey !== identityKey) {
          dailyRewardFlightRef.current = {identityKey, promise};
          void promise.finally(() => {
            if (dailyRewardFlightRef.current?.promise === promise) {
              dailyRewardFlightRef.current = null;
            }
          });
        }
        void promise.then(success => {
          if (!active || success || retryIndex >= retryDelays.length) return;
          const delay = retryDelays[retryIndex];
          retryIndex += 1;
          timer = setTimeout(attempt, delay);
        });
      };
      attempt();
      return () => {
        active = false;
        if (timer) clearTimeout(timer);
      };
    }
    if (serverSession === false && LOCAL_DEMO_ENABLED) {
      void claimDemoDailyReward().catch(() => undefined);
    }
    return undefined;
  }, [appIsActive, identityKey, screenFocused, serverSession]);

  useEffect(() => {
    let active = true;
    // Home is intentionally kept mounted through social login and account
    // replacement. Every overlay below belongs to one account journey; never
    // let a prompt, campaign or welcome receipt survive into the next owner.
    setBonusChecked(false);
    setWelcomeBonus(null);
    setWelcomeMessage(null);
    setGuestPrompt(null);
    setRewardPrompt(null);
    setCampaign(null);
    setCampaignImageFailed(false);
    void getPendingWelcomeBonus()
      .then(value => {
        if (!active) return;
        const amount = Number(value || 0);
        setWelcomeBonus(amount > 0 ? amount : null);
        setBonusChecked(true);
      })
      .catch(() => {
        if (active) setBonusChecked(true);
      });
    return () => {
      active = false;
    };
  }, [identityKey]);

  const dismissWelcomeBonus = () => {
    setWelcomeBonus(null);
    void clearPendingWelcomeBonus().catch(() => undefined);
  };

  const openEngagementDestination = (
    message: EngagementMessage | null,
    fallback: 'Home' | 'Wallet',
  ) => {
    const destination = parseRoknDestination(message?.link);
    if (!destination) {
      navigation.navigate(fallback);
      return;
    }
    navigation.dispatch(
      CommonActions.navigate(
        destination.name,
        'params' in destination ? destination.params : undefined,
      ),
    );
  };

  const openWelcomeBonus = () => {
    const message = welcomeMessage;
    dismissWelcomeBonus();
    openEngagementDestination(message, 'Wallet');
  };

  useEffect(() => {
    if (!bonusChecked || catalogueLoading || serverSession === null) return;
    let active = true;

    const loadExperienceMessage = async () => {
      const boundary = await captureAccountSessionBoundary();
      if (welcomeBonus !== null) {
        const message = await getEngagementMessage('welcome_bonus_received');
        assertAccountSessionBoundary(boundary);
        if (active && message) {
          setWelcomeMessage(message);
        } else if (active) {
          setWelcomeBonus(null);
          void clearPendingWelcomeBonus().catch(() => undefined);
        }
        return;
      }
      if (serverSession) {
        if (active) setGuestPrompt(null);
        const message = await getNextEngagementMessage();
        assertAccountSessionBoundary(boundary);
        if (!message || !active) return;
        const identity =
          message.campaignKey ||
          `${message.key}/${message.taskId || message.id}`;
        const seenKey = await homeReceiptKey(
          `engagement/${identity}`,
          boundary,
        );
        if (
          isWithinCooldown(await getItem(seenKey), message.cooldownHours || 72)
        )
          return;
        assertAccountSessionBoundary(boundary);
        if (active) setRewardPrompt(message);
        return;
      }

      const message = await getEngagementMessage('guest_registration_prompt');
      assertAccountSessionBoundary(boundary);
      if (!message || !active) return;
      const seenKey = await homeReceiptKey(
        `engagement/${message.key}/${message.version}`,
        boundary,
      );
      if (await getItem(seenKey)) return;
      assertAccountSessionBoundary(boundary);
      if (active) setGuestPrompt(message);
    };

    void loadExperienceMessage().catch(() => undefined);
    return () => {
      active = false;
    };
  }, [
    bonusChecked,
    catalogueLoading,
    identityKey,
    serverSession,
    welcomeBonus,
  ]);

  const dismissGuestPrompt = () => {
    const message = guestPrompt;
    setGuestPrompt(null);
    if (message) {
      void saveHomeReceipt(
        `engagement/${message.key}/${message.version}`,
        true,
      ).catch(() => undefined);
    }
  };

  const openGuestPrompt = () => {
    dismissGuestPrompt();
    openGuestLogin(navigation);
  };

  const dismissRewardPrompt = () => {
    const message = rewardPrompt;
    setRewardPrompt(null);
    if (message) {
      const identity =
        message.campaignKey || `${message.key}/${message.taskId || message.id}`;
      void saveHomeReceipt(`engagement/${identity}`, serverNowMs()).catch(
        () => undefined,
      );
    }
  };

  const openRewardPrompt = () => {
    const message = rewardPrompt;
    dismissRewardPrompt();
    openEngagementDestination(message, 'Wallet');
  };

  useEffect(() => {
    if (
      !bonusChecked ||
      welcomeBonus !== null ||
      rewardPrompt !== null ||
      catalogueLoading ||
      serverSession === null
    ) {
      return;
    }
    let active = true;
    const loadCampaign = async () => {
      const boundary = await captureAccountSessionBoundary();
      // Welcome rewards and course promotions are separate events.
      if (!serverSession) return;
      const candidate = (await getNotifications()).find(
        item =>
          (item.kind === 'course_recommendation' ||
            item.kind === 'new_course') &&
          !item.read,
      );
      assertAccountSessionBoundary(boundary);
      if (!candidate || !active) return;
      const seenKey = await homeReceiptKey(
        `campaign/${candidate.id}`,
        boundary,
      );
      if (await getItem(seenKey)) return;
      assertAccountSessionBoundary(boundary);
      const destination = parseRoknDestination(candidate.link);
      const courseId =
        candidate.courseId ||
        (destination?.name === 'CourseDetails' || destination?.name === 'Reels'
          ? destination.params.courseId
          : undefined);
      if (!courseId) return;
      if (active) {
        const campaignCourse = (remoteCourses ?? []).find(
          item => item.id === courseId,
        );
        // Home only promotes a published course the learner does not own.
        if (
          !campaignCourse ||
          campaignCourse.published === false ||
          campaignCourse.owned === true
        ) {
          return;
        }
        setCampaignImageFailed(false);
        setCampaign({
          id: candidate.id,
          title: candidate.title,
          description: candidate.description,
          courseId,
          image: candidate.imageUrl
            ? {uri: candidate.imageUrl}
            : campaignCourse?.image,
          badge: 'مقترح لك',
          actionLabel: candidate.actionLabel || 'تفاصيل الكورس',
        });
      }
    };
    void loadCampaign().catch(() => undefined);
    return () => {
      active = false;
    };
  }, [
    bonusChecked,
    catalogueLoading,
    identityKey,
    serverSession,
    remoteCourses,
    welcomeBonus,
    rewardPrompt,
  ]);

  const dismissCampaign = async (open = false) => {
    const boundary = await captureAccountSessionBoundary();
    const current = campaign;
    setCampaign(null);
    setCampaignImageFailed(false);
    if (!current) return;
    const receiptKey = await homeReceiptKey(`campaign/${current.id}`, boundary);
    if (serverSession === true) {
      // Keep the popup receipt behind the same server acknowledgement as the inbox.
      try {
        await markNotificationRead(current.id);
        assertAccountSessionBoundary(boundary);
        await saveItem(receiptKey, true);
        assertAccountSessionBoundary(boundary);
      } catch {
        // A read-receipt outage must not block the learner from opening the
        // course. An account switch is different: do not route stale UI into
        // the next learner's session.
        assertAccountSessionBoundary(boundary);
      }
    } else {
      await saveItem(receiptKey, true);
      assertAccountSessionBoundary(boundary);
    }
    if (open && current.courseId) {
      const target = catalogue.find(item => item.id === current.courseId);
      const opened = openCourseDetailsOnce({
        id: current.courseId,
        coinPrice: target?.coinPrice,
        title: target?.title || current.title,
        description: target?.description || current.description,
      });
      if (!opened) return;
      void trackProductEvent({
        event_name: 'notification_opened',
        source: 'notification',
        screen_key: 'home',
        campaign_key: current.id,
        course_id: current.courseId,
      });
      void trackProductEvent({
        event_name: 'course_opened',
        source: 'notification',
        screen_key: 'course_details',
        campaign_key: current.id,
        course_id: current.courseId,
      });
    }
  };

  const homeSections = useMemo(() => {
    return buildHomeSections({
      catalogue,
      demoCatalogue,
      demoSections: courseSections,
      usingLocalDemo,
    });
  }, [catalogue, demoCatalogue, usingLocalDemo]);

  const heroCourses = useMemo(
    () => selectHeroCourses({catalogue, demoCourse, usingLocalDemo}),
    [catalogue, demoCourse, usingLocalDemo],
  );

  const recommendations = useMemo(
    () => selectHomeRecommendations(catalogue, heroCourses),
    [catalogue, heroCourses],
  );

  const quickSearches = useMemo(
    () => buildQuickSearches(catalogue, QUICK_SEARCHES),
    [catalogue],
  );

  const searchMatches = useMemo(
    () =>
      searchHomeCatalogue({
        browseCatalogue,
        catalogue,
        demoCatalogue,
        remoteCourses,
        searchQuery,
        loadedSearchQuery,
        usingLocalDemo,
      }),
    [
      browseCatalogue,
      catalogue,
      demoCatalogue,
      remoteCourses,
      searchQuery,
      loadedSearchQuery,
      usingLocalDemo,
    ],
  );

  const filteredSections = useMemo(
    () =>
      searchMatches.length
        ? [{id: 'search-results', title: 'نتائج البحث', data: searchMatches}]
        : [],
    [searchMatches],
  );
  const hasSearchQuery = Boolean(normalizeText(searchQuery));

  useEffect(() => {
    if (catalogueLoading || hasSearchQuery) return undefined;
    const offset = scrollRestoreOffset;
    if (offset === null || offset <= 0 || !homeScrollRef.current)
      return undefined;
    const timer = setTimeout(() => {
      homeScrollRef.current?.scrollTo({y: offset, animated: false});
      setScrollRestoreOffset(null);
    }, 80);
    return () => clearTimeout(timer);
  }, [catalogueLoading, hasSearchQuery, scrollRestoreOffset]);

  const bindHomeScroll = useCallback((scrollView: ScrollView | null) => {
    homeScrollRef.current = scrollView;
  }, []);

  const handleHomeScroll = useCallback(
    (event: Parameters<typeof handleCatalogueScroll>[0]) => {
      handleCatalogueScroll(event);
      if (searchQuery.trim()) return;
      const offset = Math.max(0, event.nativeEvent.contentOffset.y);
      latestHomeScrollOffsetRef.current = offset;
      if (scrollSaveTimerRef.current) clearTimeout(scrollSaveTimerRef.current);
      scrollSaveTimerRef.current = setTimeout(() => {
        scrollSaveTimerRef.current = null;
        persistLatestHomeScroll();
      }, 600);
    },
    [handleCatalogueScroll, persistLatestHomeScroll, searchQuery],
  );

  useEffect(() => {
    if (
      hasSearchQuery &&
      !catalogueLoading &&
      !catalogueError &&
      searchMatches.length === 0
    ) {
      void trackProductEvent({
        event_name: 'search_zero_results',
        screen_key: 'search',
        value: Math.min(searchQuery.trim().length, 200),
      });
    }
  }, [
    catalogueError,
    catalogueLoading,
    hasSearchQuery,
    searchMatches.length,
    searchQuery,
  ]);

  const openCourse = (course: DemoCourse) => {
    if (course.published === false) return;
    if (!openCourseDetailsOnce(course)) return;
    void trackProductEvent({
      event_name: 'course_opened',
      screen_key: 'home',
      course_id: course.id,
    });
  };

  return (
    <Container noPadding>
      <Content
        controls={bindHomeScroll}
        noPadding
        onScroll={handleHomeScroll}
        onScrollBeginDrag={() => {
          homeScrollUserMovedRef.current = true;
        }}
        scrollEventThrottle={250}>
        <ResponsiveFrame>
          <View style={styles.topView}>
            <View style={styles.brandCopy}>
              <Image
                source={require('../assets/images/logo.png')}
                style={styles.logo}
              />
            </View>
            <Pressable
              accessibilityLabel={t('Notifications')}
              accessibilityRole="button"
              hitSlop={6}
              onPress={() => navigation.navigate('Notifications')}
              style={({pressed}) => [
                styles.iconButton,
                pressed && styles.pressed,
              ]}>
              <NotificationIcon />
            </Pressable>
          </View>

          <View style={styles.searchContainer}>
            <View style={styles.searchIconSlot}>
              <SearchIcon color={Palette.textMuted} />
            </View>
            <TextInput
              accessibilityLabel={t('Search')}
              autoCorrect={false}
              // Keep suggestions mounted long enough for the tapped chip to
              // receive its press after the keyboard dismisses the input.
              onBlur={() => {
                if (searchBlurTimerRef.current) {
                  clearTimeout(searchBlurTimerRef.current);
                }
                searchBlurTimerRef.current = setTimeout(
                  () => setSearchFocused(false),
                  120,
                );
              }}
              onChangeText={setSearchQuery}
              onFocus={() => {
                if (searchBlurTimerRef.current) {
                  clearTimeout(searchBlurTimerRef.current);
                  searchBlurTimerRef.current = null;
                }
                setSearchFocused(true);
              }}
              onSubmitEditing={() => commitSearch(searchQuery)}
              placeholder="ابحث عن مهارة أو كورس"
              placeholderTextColor={Palette.textFaint}
              returnKeyType="search"
              selectionColor={Palette.primary}
              style={styles.searchInput}
              value={searchQuery}
            />
            {!!searchQuery && (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('Close')}
                hitSlop={8}
                onPress={() => setSearchQuery('')}
                style={styles.clearSearch}>
                <Text style={styles.clearSearchText}>×</Text>
              </Pressable>
            )}
          </View>
          <SearchAssist
            onClearRecent={clearRecentSearches}
            onSelect={commitSearch}
            recent={searchHistory}
            searching={hasSearchQuery && catalogueLoading}
            suggestions={quickSearches}
            visible={searchFocused && !hasSearchQuery}
          />
        </ResponsiveFrame>

        {catalogueLoading && !hasSearchQuery ? (
          <CatalogueSkeleton />
        ) : catalogueError && !hasSearchQuery ? (
          <ResponsiveFrame>
            <StatusView
              actionLabel="إعادة المحاولة"
              description={catalogueError}
              onAction={refreshCatalogue}
              state="error"
              title="تعذّر تحميل الكورسات"
            />
          </ResponsiveFrame>
        ) : !catalogue.length && !hasSearchQuery ? (
          <ResponsiveFrame>
            <StatusView
              description="ستظهر الكورسات هنا فور نشرها"
              state="empty"
              title="الجديد في الطريق"
            />
          </ResponsiveFrame>
        ) : !hasSearchQuery ? (
          <CourseCarousel data={heroCourses} onButtonPress={openCourse} />
        ) : null}

        {!!staleNotice && !catalogueError && (
          <ResponsiveFrame>
            <Pressable
              accessibilityRole="button"
              onPress={refreshCatalogue}
              style={({pressed}) => [
                styles.catalogueNotice,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.catalogueNoticeText}>{staleNotice}</Text>
              <Text style={styles.catalogueNoticeAction}>إعادة المحاولة</Text>
            </Pressable>
          </ResponsiveFrame>
        )}

        {!catalogueLoading && !catalogueError && !hasSearchQuery ? (
          <>
            {!!recommendations.length && (
              <CoursesSection
                data={recommendations}
                key="recommendations"
                onEndReached={loadMoreCatalogue}
                title="مقترحات لك"
              />
            )}
            {homeSections.map(section => (
              <CoursesSection
                data={section.data}
                key={section.id}
                onEndReached={loadMoreCatalogue}
                title={section.title}
              />
            ))}
          </>
        ) : null}

        {hasSearchQuery && filteredSections.length ? (
          filteredSections.map(section => (
            <CoursesSection
              data={section.data}
              key={section.id}
              onEndReached={loadMoreCatalogue}
              title={section.title}
            />
          ))
        ) : hasSearchQuery && !catalogueLoading && !catalogueError ? (
          <ResponsiveFrame>
            <StatusView
              description="ابحث باسم المهارة أو المدرب"
              state="empty"
              title="لم نجد نتيجة مطابقة"
            />
          </ResponsiveFrame>
        ) : null}

        {hasSearchQuery && !catalogueLoading && catalogueError ? (
          <ResponsiveFrame>
            <StatusView
              actionLabel="إعادة المحاولة"
              description={
                searchMatches.length
                  ? 'هذه نتائج محفوظة على جهازك\nأعد المحاولة لعرض أحدث النتائج'
                  : catalogueError
              }
              onAction={refreshCatalogue}
              state="error"
              title={
                searchMatches.length
                  ? 'تعذّر تحديث نتائج البحث'
                  : 'تعذّر البحث الآن'
              }
            />
          </ResponsiveFrame>
        ) : null}

        {!!loadMoreError && !catalogueError && (
          <ResponsiveFrame>
            <Pressable
              accessibilityRole="button"
              onPress={loadMoreCatalogue}
              style={({pressed}) => [
                styles.catalogueNotice,
                pressed && styles.pressed,
              ]}>
              <Text style={styles.catalogueNoticeText}>{loadMoreError}</Text>
              <Text style={styles.catalogueNoticeAction}>إعادة المحاولة</Text>
            </Pressable>
          </ResponsiveFrame>
        )}
      </Content>
      <TabBar />
      <HomeOverlays
        campaign={campaign}
        campaignImageFailed={campaignImageFailed}
        onCampaignImageError={() => setCampaignImageFailed(true)}
        onDismissCampaign={open => {
          void dismissCampaign(open).catch(() => undefined);
        }}
        onDismissWelcome={dismissWelcomeBonus}
        onOpenWelcome={openWelcomeBonus}
        guestPrompt={guestPrompt}
        onDismissGuestPrompt={dismissGuestPrompt}
        onOpenGuestPrompt={openGuestPrompt}
        welcomeMessage={welcomeMessage}
        rewardPrompt={rewardPrompt}
        onDismissRewardPrompt={dismissRewardPrompt}
        onOpenRewardPrompt={openRewardPrompt}
        welcomeBonus={welcomeMessage ? welcomeBonus : null}
      />
    </Container>
  );
};

const styles = StyleSheet.create({
  topView: {
    minHeight: 70,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: Spacing.xs,
  },
  brandCopy: {
    ...flexibleTextColumn,
    direction: 'rtl',
    alignItems: 'flex-start',
    justifyContent: 'center',
  },
  logo: {width: 94, height: 38, resizeMode: 'contain'},
  iconButton: {
    ...fixedIconSlot,
    borderRadius: Radius.md,
    backgroundColor: Palette.surface,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  catalogueNotice: {
    minHeight: Accessibility.minTouchTarget,
    marginTop: Spacing.md,
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.sm,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
  },
  catalogueNoticeText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
  },
  catalogueNoticeAction: {
    ...Type.caption,
    ...textDirection,
    color: Palette.primary,
    marginTop: Spacing.xxs,
  },
  searchContainer: {
    alignItems: 'center',
    backgroundColor: Palette.surface,
    borderColor: Palette.lineSoft,
    borderRadius: Radius.md,
    borderWidth: 1,
    ...rtlRowStyle,
    minHeight: 52,
    marginBottom: Spacing.lg,
    paddingHorizontal: Spacing.md,
  },
  searchInput: {
    ...Type.body,
    ...textDirection,
    color: Palette.text,
    flex: 1,
    minWidth: 0,
    minHeight: 50,
    marginHorizontal: Spacing.sm,
    paddingVertical: 0,
    textAlignVertical: 'center',
  },
  searchIconSlot: {
    ...fixedIconSlot,
    width: 30,
    minWidth: 30,
  },
  clearSearch: {
    alignItems: 'center',
    height: Accessibility.minTouchTarget,
    justifyContent: 'center',
    width: Accessibility.minTouchTarget,
  },
  clearSearchText: {color: Palette.textMuted, fontSize: 24, lineHeight: 28},
  pressed: {opacity: 0.75},
});

export default Home;
