import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import type {NativeScrollEvent, NativeSyntheticEvent} from 'react-native';
import {LOCAL_DEMO_ENABLED} from '../../config/runtime';
import type {DemoCourse} from '../../data/demoContent';
import {friendlyNetworkMessage} from '../../services/networkExperience';
import {
  getPublishedCoursesPage,
  hasSession,
} from '../../services/roknApi';

type CatalogueRequest = {
  query?: string;
  page?: number;
  append?: boolean;
  blocking?: boolean;
};

export const useHomeCatalogue = ({
  demoCatalogue,
  searchQuery,
}: {
  demoCatalogue: DemoCourse[];
  searchQuery: string;
}) => {
  const [remoteCourses, setRemoteCourses] = useState<DemoCourse[] | null>(null);
  const [serverSession, setServerSession] = useState<boolean | null>(
    null,
  );
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [error, setError] = useState('');
  const requestId = useRef(0);
  const loadingMoreRef = useRef(false);
  const loadedQuery = useRef('');
  const browseCatalogue = useRef<DemoCourse[]>([]);

  const load = useCallback(
    async ({
      query = '',
      page: requestedPage = 1,
      append = false,
      blocking = true,
    }: CatalogueRequest = {}) => {
      if (append && loadingMoreRef.current) return;
      loadingMoreRef.current = append;
      const currentRequestId = ++requestId.current;

      if (blocking) {
        setLoading(true);
        if (!append) setRemoteCourses(null);
      } else if (append) {
        setLoadingMore(true);
      }

      const sessionAvailable = await hasSession();
      if (currentRequestId !== requestId.current) return;
      setServerSession(sessionAvailable);

      if (LOCAL_DEMO_ENABLED && !sessionAvailable) {
        setRemoteCourses([]);
        setError('');
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
        });
        if (currentRequestId !== requestId.current) return;

        setRemoteCourses(current => {
          if (!append || !current) {
            if (!query.trim()) browseCatalogue.current = result.courses;
            return result.courses;
          }

          const merged = new Map(current.map(course => [course.id, course]));
          result.courses.forEach(course => merged.set(course.id, course));
          const next = Array.from(merged.values());
          if (!query.trim()) browseCatalogue.current = next;
          return next;
        });
        setPage(result.page);
        setHasMore(result.hasMore);
        loadedQuery.current = query.trim();
        setError('');
      } catch (requestError) {
        if (currentRequestId === requestId.current && !append) {
          setError(
            LOCAL_DEMO_ENABLED
              ? ''
              : friendlyNetworkMessage(
                  requestError,
                  query ? 'نتائج البحث' : 'الكورسات',
                ),
          );
        }
      } finally {
        if (currentRequestId === requestId.current) {
          loadingMoreRef.current = false;
          setLoading(false);
          setLoadingMore(false);
        }
      }
    },
    [],
  );

  useEffect(() => {
    void load();
  }, [load]);

  const catalogue = useMemo<DemoCourse[]>(() => {
    if (
      !loading &&
      serverSession === false &&
      LOCAL_DEMO_ENABLED &&
      !remoteCourses?.length
    ) {
      return demoCatalogue;
    }
    return remoteCourses ?? [];
  }, [demoCatalogue, loading, serverSession, remoteCourses]);

  const usingLocalDemo =
    LOCAL_DEMO_ENABLED &&
    serverSession === false &&
    !remoteCourses?.length &&
    !error;

  useEffect(() => {
    if (serverSession === null || usingLocalDemo) return undefined;
    const query = searchQuery.trim();
    if (query === loadedQuery.current) return undefined;

    requestId.current += 1;
    loadingMoreRef.current = false;
    setLoading(true);
    setRemoteCourses(null);
    const timer = setTimeout(() => {
      void load({query, page: 1, append: false, blocking: true});
    }, 350);
    return () => clearTimeout(timer);
  }, [load, serverSession, searchQuery, usingLocalDemo]);

  const refresh = useCallback(
    () =>
      load({
        query: searchQuery.trim(),
        page: 1,
        append: false,
        blocking: true,
      }),
    [load, searchQuery],
  );

  const loadMore = useCallback(() => {
    if (loading || loadingMore || !hasMore || usingLocalDemo) return;
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
    browseCatalogue: browseCatalogue.current,
    catalogue,
    error,
    handleScroll,
    loadMore,
    loading,
    loadingMore,
    serverSession,
    refresh,
    remoteCourses,
    usingLocalDemo,
  };
};
