import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import React, {useCallback, useEffect, useMemo, useState} from 'react';
import {
  Image,
  Pressable,
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
import {AsyncKeys, getItem, removeItem, saveItem} from '../constants/helpers';
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

const QUICK_SEARCHES = [
  'العمل الحر',
  'التسويق',
  'التصميم',
  'صناعة المحتوى',
  'اللغات',
];

const Home = () => {
  const navigation = useNavigation<RootNavigation>();
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
    serverSession,
    refresh: refreshCatalogue,
    remoteCourses,
    usingLocalDemo,
  } = useHomeCatalogue({demoCatalogue, searchQuery});

  useEffect(() => {
    void getSearchHistory().then(setSearchHistory);
    void trackProductEvent({event_name: 'home_viewed', screen_key: 'home'});
    if (!LOCAL_DEMO_ENABLED) {
      return undefined;
    }
    const unsubscribe = subscribeDemoExperience(setExperience);
    return unsubscribe;
  }, []);

  const commitSearch = useCallback((rawQuery: string) => {
    const query = rawQuery.trim().replace(/\s+/g, ' ');
    if (!query) return;
    setSearchQuery(query);
    setSearchFocused(false);
    void rememberSearch(query).then(setSearchHistory);
    void trackProductEvent({
      event_name: 'search_submitted',
      screen_key: 'search',
      value: Math.min(query.length, 200),
    });
  }, []);

  const clearRecentSearches = useCallback(() => {
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
    void getItem(AsyncKeys.PENDING_WELCOME_BONUS).then(value => {
      const amount = Number(value || 0);
      if (amount > 0) setWelcomeBonus(amount);
      setBonusChecked(true);
    });
  }, []);

  const dismissWelcomeBonus = () => {
    setWelcomeBonus(null);
    void removeItem(AsyncKeys.PENDING_WELCOME_BONUS);
  };

  useEffect(() => {
    if (
      !bonusChecked ||
      welcomeBonus !== null ||
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
      const seenKey = `@rokn/campaign/seen/${candidate.id}`;
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
          actionLabel: 'ابدأ التعلّم الآن',
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
  ]);

  const dismissCampaign = async (open = false) => {
    const current = campaign;
    setCampaign(null);
    setCampaignImageFailed(false);
    if (!current) return;
    await saveItem(`@rokn/campaign/seen/${current.id}`, true);
    if (serverSession === true) {
      // A Home promotion is also read in the inbox.
      void markNotificationRead(current.id).catch(() => undefined);
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
        usingLocalDemo,
      }),
    [
      browseCatalogue,
      catalogue,
      demoCatalogue,
      remoteCourses,
      searchQuery,
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
  const hasSearchQuery = Boolean(searchQuery.trim());

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
        noPadding
        onScroll={handleCatalogueScroll}
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
              onBlur={() => setTimeout(() => setSearchFocused(false), 120)}
              onChangeText={setSearchQuery}
              onFocus={() => setSearchFocused(true)}
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
              description="ارجع لنا قريب، بنجهّز لك حاجات جديدة."
              state="empty"
              title="الجديد في الطريق"
            />
          </ResponsiveFrame>
        ) : !hasSearchQuery ? (
          <CourseCarousel data={heroCourses} onButtonPress={openCourse} />
        ) : null}

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
              description="جرّب اسم مهارة أو اسم المدرب."
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
                  ? 'هذه نتائج محفوظة على جهازك. أعد المحاولة لعرض أحدث النتائج.'
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
      </Content>
      <TabBar />
      <HomeOverlays
        campaign={campaign}
        campaignImageFailed={campaignImageFailed}
        onCampaignImageError={() => setCampaignImageFailed(true)}
        onDismissCampaign={open => void dismissCampaign(open)}
        onDismissWelcome={dismissWelcomeBonus}
        welcomeBonus={welcomeBonus}
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
