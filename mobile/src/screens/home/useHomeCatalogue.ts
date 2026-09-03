import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {type NativeScrollEvent, type NativeSyntheticEvent} from 'react-native';
import {LOCAL_DEMO_ENABLED} from '../../config/runtime';
import type {DemoCourse} from '../../data/demoContent';
import {normalizeText} from '../../utils/searchText';
import {
  friendlyNetworkMessage,
  networkFailureKind,
} from '../../services/networkExperience';
import {
  getCachedPublishedCourses,
  getPublishedCoursesPage,
  hasSession,
  subscribeToUnavailableCourses,
} from '../../services/roknApi';
import {useAppActiveState} from '../../hooks/useAppActiveState';

type CatalogueRequest = {
  query?: string;
  page?: number;
  append?: boolean;
  blocking?: boolean;
};

export const useHomeCatalogue = ({
  active,
  demoCatalogue,
  identityKey,
  searchQuery,
}: {
  active: boolean;
  demoCatalogue: DemoCourse[];
  identityKey: string;
  searchQuery: string;
}) => {
  const [remoteCourses, setRemoteCourses] = useState<DemoCourse[] | null>(null);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [error, setError] = useState('');
  const [staleNotice, setStaleNotice] = useState('');
  const [loadMoreError, setLoadMoreError] = useState('');
  const appIsActive = useAppActiveState();
  const previouslyActiveRef = useRef(appIsActive);
  const previouslyFocusedRef = useRef(active);
  const requestId = useRef(0);
  const requestController = useRef<AbortController | null>(null);
  const dataOwnerRef = useRef(identityKey);
  const refreshFlight = useRef<Promise<void> | null>(null);
  const loadingMoreRef = useRef(false);
  const loadedQuery = useRef('');
  const requestedQuery = useRef('');
  const browseCatalogue = useRef<DemoCourse[]>([]);
  const catalogueRevision = useRef<number | undefined>(undefined);
  const lastAttemptAt = useRef(0);
  const lastSuccessfulLoadAt = useRef(0);
  const activeQuery = useRef(normalizeText(searchQuery));
  const shouldRefreshOnForeground = useRef(false);
  const localDemoActive = useRef(false);
  activeQuery.current = normalizeText(searchQuery);
  shouldRefreshOnForeground.current = Boolean(error || staleNotice);

  const load = useCallback(
    async ({
      query = '',
      page: requestedPage = 1,
      append = false,
      blocking = true,
    }: CatalogueRequest = {}) => {
      if (append && loadingMoreRef.current) return;
      requestController.current?.abort();
      const controller = new AbortController();
      requestController.current = controller;
      requestedQuery.current = normalizeText(query);
      lastAttemptAt.current = Date.now();
      loadingMoreRef.current = append;
      const currentRequestId = ++requestId.current;

      if (!append) setLoadMoreError('');
      if (blocking) {
        const needsBlockingState =
          normalizeText(query) !== '' || browseCatalogue.current.length === 0;
        setLoading(needsBlockingState);
        setError('');
        // A refresh or search must never erase a catalogue the learner can
        // already use. Keep the last good snapshot until the replacement is
        // confirmed; only a true first load owns the empty skeleton state.
        if (!append && browseCatalogue.current.length === 0) {
          setRemoteCourses(null);
        }
      } else if (append) {
        setLoadingMore(true);
        setLoadMoreError('');
      }

      const sessionAvailable = await hasSession();
      if (currentRequestId !== requestId.current) return;
      setServerSession(sessionAvailable);

      if (LOCAL_DEMO_ENABLED && !sessionAvailable) {
        setRemoteCourses([]);
        setError('');
        setStaleNotice('');
        setLoadMoreError('');
        setHasMore(false);
        setPage(1);
        loadedQuery.current = '';
        setLoading(false);
        return;
      }

      try {
        const result = await getPublishedCoursesPage({
          page: requestedPage,
          perPage: 30,
          search: query,
          revision: append ? catalogueRevision.current : undefined,
          signal: controller.signal,
        });
        if (currentRequestId !== requestId.current) return;

        setRemoteCourses(current => {
          if (!append || result.reset || !current) {
            if (!query.trim()) browseCatalogue.current = result.courses;
            return result.courses;
          }

          const merged = new Map(current.map(course => [course.id, course]));
          result.courses.forEach(course => merged.set(course.id, course));
          const next = Array.from(merged.values());
          if (!query.trim()) browseCatalogue.current = next;
          return next;
        });
        catalogueRevision.current = result.revision;
        setPage(result.page);
        setHasMore(result.hasMore);
        loadedQuery.current = query.trim();
        setError('');
        if (result.fromCache) {
          setStaleNotice('نعرض النسخة المحفوظة\nسنحدّثها عند عودة الاتصال');
        } else {
          lastSuccessfulLoadAt.current = Date.now();
          setStaleNotice('');
        }
        setLoadMoreError('');
      } catch (requestError) {
        if (networkFailureKind(requestError) === 'cancelled') return;
        if (currentRequestId === requestId.current && append) {
          setLoadMoreError('تعذّر تحميل المزيد\nحاول مرة أخرى');
        } else if (currentRequestId === requestId.current) {
          // A failed refresh must not replace a usable catalogue snapshot with
          // a full-screen error. Keep cached cards visible and reserve the
          // blocking state for devices that have never loaded this catalogue.
          const hasUsableSnapshot = browseCatalogue.current.length > 0;
          const normalizedQuery = normalizeText(query);
          const fallbackCourses = normalizedQuery
            ? browseCatalogue.current.filter(course =>
                [
                  course.title,
                  course.description,
                  course.category,
                  course.instructor,
                  course.label,
                  ...(course.homeRows || []).map(row => row.title),
                ]
                  .filter(Boolean)
                  .some(value =>
                    normalizeText(String(value)).includes(normalizedQuery),
                  ),
              )
            : browseCatalogue.current;
          if (hasUsableSnapshot) {
            setRemoteCourses(fallbackCourses);
            loadedQuery.current = query.trim();
            setHasMore(false);
            setPage(1);
            setStaleNotice(
              'نعرض النسخة المحفوظة\nأعد المحاولة عند عودة الاتصال',
            );
          }
          setError(
            LOCAL_DEMO_ENABLED || fallbackCourses.length > 0
              ? ''
              : friendlyNetworkMessage(
                  requestError,
                  query ? 'نتائج البحث' : 'الكورسات',
                ),
          );
        }
      } finally {
        if (currentRequestId === requestId.current) {
          if (requestController.current === controller) {
            requestController.current = null;
          }
          loadingMoreRef.current = false;
          setLoading(false);
          setLoadingMore(false);
        }
      }
    },
    [],
  );

  useEffect(() => {
    let mounted = true;

    dataOwnerRef.current = identityKey;
    requestController.current?.abort();
    requestController.current = null;
    requestId.current += 1;
    loadingMoreRef.current = false;
    refreshFlight.current = null;
    loadedQuery.current = '';
    requestedQuery.current = '';
    browseCatalogue.current = [];
    catalogueRevision.current = undefined;
    lastAttemptAt.current = 0;
    lastSuccessfulLoadAt.current = 0;
    setRemoteCourses(null);
    setServerSession(null);
    setLoading(true);
    setLoadingMore(false);
    setPage(1);
    setHasMore(false);
    setError('');
    setStaleNotice('');
    setLoadMoreError('');

    void getCachedPublishedCourses()
      .then(cached => {
        if (!mounted) return;
        if (cached.length) {
          browseCatalogue.current = cached;
          setRemoteCourses(cached);
          setLoading(false);
        }
        void load({
          query: activeQuery.current,
          blocking: cached.length === 0 || activeQuery.current !== '',
        });
      })
      .catch(() => {
        if (mounted) void load({query: activeQuery.current});
      });

    return () => {
      mounted = false;
      requestController.current?.abort();
      requestController.current = null;
      requestId.current += 1;
      loadingMoreRef.current = false;
    };
  }, [identityKey, load]);

  useEffect(
    () =>
      subscribeToUnavailableCourses(courseId => {
        browseCatalogue.current = browseCatalogue.current.filter(
          course => course.id !== courseId,
        );
        setRemoteCourses(current =>
          current ? current.filter(course => course.id !== courseId) : current,
        );
      }),
    [],
  );

  const ownerMatches = dataOwnerRef.current === identityKey;
  const catalogue = useMemo<DemoCourse[]>(() => {
    if (!ownerMatches) return [];
    if (
      !loading &&
      serverSession === false &&
      LOCAL_DEMO_ENABLED &&
      !remoteCourses?.length
    ) {
      return demoCatalogue;
    }
    if (
      normalizeText(searchQuery) === '' &&
      loadedQuery.current !== '' &&
      browseCatalogue.current.length > 0
    ) {
      return browseCatalogue.current;
    }
    return remoteCourses ?? [];
  }, [
    demoCatalogue,
    loading,
    ownerMatches,
    searchQuery,
    serverSession,
    remoteCourses,
  ]);

  const usingLocalDemo =
    ownerMatches &&
    LOCAL_DEMO_ENABLED &&
    serverSession === false &&
    !remoteCourses?.length &&
    !error;
  localDemoActive.current = usingLocalDemo;

  useEffect(() => {
    const wasAppActive = previouslyActiveRef.current;
    const wasFocused = previouslyFocusedRef.current;
    previouslyActiveRef.current = appIsActive;
    previouslyFocusedRef.current = active;
    if (!active || !appIsActive || localDemoActive.current) {
      return;
    }
    const returnedToHome = !wasFocused;
    const returnedToApp = !wasAppActive;
    if (!returnedToHome && !returnedToApp) return;
    const now = Date.now();
    if (!returnedToHome && now - lastAttemptAt.current < 3_000) return;
    if (
      !returnedToHome &&
      !shouldRefreshOnForeground.current &&
      now - lastSuccessfulLoadAt.current < 2 * 60 * 1000
    ) {
      return;
    }
    void load({
      query: activeQuery.current,
      page: 1,
      append: false,
      blocking: browseCatalogue.current.length === 0,
    });
  }, [active, appIsActive, load]);

  useEffect(() => {
    if (
      !active ||
      !appIsActive ||
      usingLocalDemo ||
      (!error && !staleNotice)
    ) {
      return undefined;
    }
    // NetInfo is intentionally not a launch dependency. While this screen is
    // visible, retry only its read-only catalogue at a restrained cadence so
    // a restored connection replaces the last-known-good snapshot without
    // asking the learner to leave and reopen the app.
    const timer = setInterval(() => {
      if (requestController.current) return;
      void load({
        query: activeQuery.current,
        page: 1,
        append: false,
        blocking: browseCatalogue.current.length === 0,
      });
    }, 20_000);
    return () => clearInterval(timer);
  }, [active, appIsActive, error, load, staleNotice, usingLocalDemo]);

  useEffect(() => {
    if (serverSession === null || usingLocalDemo) return undefined;
    const query = normalizeText(searchQuery);
    if (query === loadedQuery.current) return undefined;
    if (
      requestController.current &&
      query === requestedQuery.current
    )
      return undefined;

    requestController.current?.abort();
    requestController.current = null;
    requestId.current += 1;
    loadingMoreRef.current = false;
    catalogueRevision.current = undefined;
    setLoading(true);
    setLoadMoreError('');
    const timer = setTimeout(() => {
      void load({query, page: 1, append: false, blocking: true});
    }, 350);
    return () => clearTimeout(timer);
  }, [load, serverSession, searchQuery, usingLocalDemo]);

  const refresh = useCallback(() => {
    if (refreshFlight.current) return refreshFlight.current;
    const flight = load({
      query: normalizeText(searchQuery),
      page: 1,
      append: false,
      blocking: true,
    }).finally(() => {
      if (refreshFlight.current === flight) refreshFlight.current = null;
    });
    refreshFlight.current = flight;
    return flight;
  }, [load, searchQuery]);

  const loadMore = useCallback(() => {
    if (
      loading ||
      loadingMore ||
      requestController.current ||
      !hasMore ||
      usingLocalDemo
    )
      return;
    void load({
      query: loadedQuery.current,
      page: page + 1,
      append: true,
      blocking: false,
    });
  }, [hasMore, load, loading, loadingMore, page, usingLocalDemo]);

  const handleScroll = useCallback(
    (event: NativeSyntheticEvent<NativeScrollEvent>) => {
      const {contentOffset, contentSize, layoutMeasurement} = event.nativeEvent;
      if (
        contentOffset.y + layoutMeasurement.height >=
        contentSize.height - 320
      ) {
        loadMore();
      }
    },
    [loadMore],
  );

  return {
    browseCatalogue: ownerMatches ? browseCatalogue.current : [],
    catalogue,
    error: ownerMatches ? error : '',
    handleScroll,
    loadMore,
    loading: loading || !ownerMatches,
    loadingMore: ownerMatches ? loadingMore : false,
    loadMoreError: ownerMatches ? loadMoreError : '',
    loadedSearchQuery: ownerMatches ? loadedQuery.current : '',
    serverSession: ownerMatches ? serverSession : null,
    refresh,
    remoteCourses: ownerMatches ? remoteCourses : null,
    staleNotice: ownerMatches ? staleNotice : '',
    usingLocalDemo,
  };
};
