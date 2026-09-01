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
  getItem,
  normalizeText,
  saveItem,
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

const QUICK_SEARCHES = [
  'العمل الحر',
  'التسويق',
  'التصميم',
  'صناعة المحتوى',
  'اللغات',
];

const homeReceiptKey = (path: string) =>
  accountScopedStorageKey(`@rokn/home-receipt/${path}`);
const homeScrollKey = () => accountScopedStorageKey('@rokn/home-scroll/v1');

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
  const homeScrollRef = useRef<ScrollView | null>(null);
  const scrollSaveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

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
  } = useHomeCatalogue({active: screenFocused, demoCatalogue, searchQuery});

  useEffect(() => {
    let active = true;
    const historyGeneration = ++searchHistoryGenerationRef.current;
    void getSearchHistory().then(history => {
      if (active && historyGeneration === searchHistoryGenerationRef.current) {
        setSearchHistory(history);
      }
    });
    void trackProductEvent({event_name: 'home_viewed', screen_key: 'home'});
    const unsubscribe = LOCAL_DEMO_ENABLED
      ? subscribeDemoExperience(state => {
          if (active) setExperience(state);
        })
      : () => undefined;
    return () => {
      active = false;
      searchHistoryGenerationRef.current += 1;
      unsubscribe();
      if (searchBlurTimerRef.current) {
        clearTimeout(searchBlurTimerRef.current);
      }
      if (scrollSaveTimerRef.current) {
        clearTimeout(scrollSaveTimerRef.current);
      }
    };
  }, []);

  useEffect(() => {
    let active = true;
    void homeScrollKey()
      .then(key => getItem<number>(key))
      .then(offset => {
        if (!active || !Number.isFinite(Number(offset))) return;
        setScrollRestoreOffset(Math.max(0, Number(offset)));
      });
    return () => {
      active = false;
    };
  }, []);

  const commitSearch = useCallback((rawQuery: string) => {
    const query = rawQuery.trim().replace(/\s+/g, ' ');
    if (!query) return;
    const historyGeneration = ++searchHistoryGenerationRef.current;
    setSearchQuery(query);
    setSearchFocused(false);
    void rememberSearch(query).then(history => {
      if (historyGeneration === searchHistoryGenerationRef.current) {
        setSearchHistory(history);
      }
    });
    void trackProductEvent({
      event_name: 'search_submitted',
      screen_key: 'search',
      value: Math.min(query.length, 200),
    });
  }, []);

  const clearRecentSearches = useCallback(() => {
    searchHistoryGenerationRef.current += 1;
    setSearchHistory([]);
    void clearSearchHistory();
  }, []);

  useEffect(() => {
    // Account rewards come from the API; the local demo mirrors the event shape.
    if (serverSession === true) {
      void claimDailyReward().catch(() => undefined);
    } else if (serverSession === false && LOCAL_DEMO_ENABLED) {
      void claimDemoDailyReward().catch(() => undefined);
    }
  }, [serverSession]);

  useEffect(() => {
    let active = true;
    void getPendingWelcomeBonus().then(value => {
      if (!active) return;
      const amount = Number(value || 0);
      if (amount > 0) setWelcomeBonus(amount);
      setBonusChecked(true);
    });
    return () => {
      active = false;
    };
  }, []);

  const dismissWelcomeBonus = () => {
    setWelcomeBonus(null);
    void clearPendingWelcomeBonus();
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
      if (welcomeBonus !== null) {
        const message = await getEngagementMessage('welcome_bonus_received');
        if (active && message) {
          setWelcomeMessage(message);
        } else if (active) {
          setWelcomeBonus(null);
          void clearPendingWelcomeBonus();
        }
        return;
      }
      if (serverSession) {
        if (active) setGuestPrompt(null);
        const message = await getNextEngagementMessage();
        if (!message || !active) return;
        const identity =
          message.campaignKey ||
          `${message.key}/${message.taskId || message.id}`;
        const seenKey = await homeReceiptKey(`engagement/${identity}`);
        if (
          isWithinCooldown(await getItem(seenKey), message.cooldownHours || 72)
        )
          return;
        if (active) setRewardPrompt(message);
        return;
      }

      const message = await getEngagementMessage('guest_registration_prompt');
      if (!message || !active) return;
      const seenKey = await homeReceiptKey(
        `engagement/${message.key}/${message.version}`,
      );
      if (await getItem(seenKey)) return;
      if (active) setGuestPrompt(message);
    };

    void loadExperienceMessage().catch(() => undefined);
    return () => {
      active = false;
    };
  }, [bonusChecked, catalogueLoading, serverSession, welcomeBonus]);

  const dismissGuestPrompt = () => {
    const message = guestPrompt;
    setGuestPrompt(null);
    if (message) {
      void homeReceiptKey(`engagement/${message.key}/${message.version}`).then(
        key => saveItem(key, true),
      );
    }
  };

  const openGuestPrompt = () => {
    dismissGuestPrompt();
    navigation.navigate('Login');
  };

  const dismissRewardPrompt = () => {
    const message = rewardPrompt;
    setRewardPrompt(null);
    if (message) {
      const identity =
        message.campaignKey || `${message.key}/${message.taskId || message.id}`;
      void homeReceiptKey(`engagement/${identity}`).then(key =>
        saveItem(key, serverNowMs()),
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
      // Welcome rewards and course promotions are separate events.
      if (!serverSession) return;
      const candidate = (await getNotifications()).find(
        item =>
          (item.kind === 'course_recommendation' ||
            item.kind === 'new_course') &&
          !item.read,
      );
      if (!candidate || !active) return;
      const seenKey = await homeReceiptKey(`campaign/${candidate.id}`);
      if (await getItem(seenKey)) return;
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
    serverSession,
    remoteCourses,
    welcomeBonus,
    rewardPrompt,
  ]);

  const dismissCampaign = async (open = false) => {
    const current = campaign;
    setCampaign(null);
    setCampaignImageFailed(false);
    if (!current) return;
    const receiptKey = await homeReceiptKey(`campaign/${current.id}`);
    if (serverSession === true) {
      // Keep the popup receipt behind the same server acknowledgement as the inbox.
      void markNotificationRead(current.id)
        .then(() => saveItem(receiptKey, true))
        .catch(() => undefined);
    } else {
      await saveItem(receiptKey, true);
    }
    if (open && current.courseId) {
      const target = catalogue.find(item => item.id === current.courseId);
      navigation.navigate('CourseDetails', {
        courseId: current.courseId,
        coinPrice: target?.coinPrice,
        title: target?.title,
        description: target?.description,
      });
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
      if (scrollSaveTimerRef.current) clearTimeout(scrollSaveTimerRef.current);
      scrollSaveTimerRef.current = setTimeout(() => {
        void homeScrollKey().then(key => saveItem(key, offset));
      }, 600);
    },
    [handleCatalogueScroll, searchQuery],
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
    void trackProductEvent({
      event_name: 'course_opened',
      screen_key: 'home',
      course_id: course.id,
    });
    navigation.navigate('CourseDetails', {
      courseId: course.id,
      coinPrice: course.coinPrice,
      title: course.title,
      description: course.description,
    });
  };

  return (
    <Container noPadding>
      <Content
        controls={bindHomeScroll}
        noPadding
        onScroll={handleHomeScroll}
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
        onDismissCampaign={open => void dismissCampaign(open)}
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
