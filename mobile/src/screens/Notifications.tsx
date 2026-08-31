import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  CommonActions,
  useFocusEffect,
  useNavigation,
} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import React, {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {
  FlatList,
  Image,
  ImageSourcePropType,
  Linking,
  ListRenderItemInfo,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import {NotificationIcon} from '../assets/SVG';
import {Container} from '../components/containers/Containers';
import {SectionHeading, StatusView} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {RoknCoinStack} from '../components/ui/RoknCoin';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../constants/designSystem';
import {getLocalLearningState} from '../components/VideoPlayer/courseLearningApi';
import {
  DemoExperienceState,
  subscribeDemoExperience,
} from '../services/demoExperience';
import {
  getCachedPublishedCourses,
  getNotificationsPage,
  hasSession,
  markAllNotificationsRead,
  markNotificationRead,
  Notification as NotificationDto,
} from '../services/roknApi';
import {formatArabicDisplayText} from '../constants/arabicFormatting';
import {LOCAL_DEMO_ENABLED} from '../config/runtime';
import {isExternalWebLink, parseRoknDestination} from '../navigation/deepLinks';

type NotificationItem = {
  id: string;
  title: string;
  description: string;
  time: string;
  read: boolean;
  tone: 'learning' | 'project' | 'coins';
  link?: string;
  image?: ImageSourcePropType;
  actionLabel?: string;
};

const READ_KEY = '@rokn/notifications/read/v1';
const MAX_LOCAL_READ_IDS = 250;

export default function Notifications() {
  const navigation = useNavigation<RootNavigation>();
  const {width, fontScale, contentWidth, gutter} = useResponsiveLayout();
  const compactLayout = width < 380 || fontScale > 1.2;
  const [experience, setExperience] = useState<DemoExperienceState | null>(
    null,
  );
  const [learning, setLearning] = useState({completed: 0, passed: 0});
  const [readIds, setReadIds] = useState<string[]>([]);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [serverNotifications, setServerNotifications] = useState<
    NotificationDto[]
  >([]);
  const [notificationError, setNotificationError] = useState('');
  const [loading, setLoading] = useState(true);
  const [failedImages, setFailedImages] = useState<Record<string, true>>({});
  const [notificationPage, setNotificationPage] = useState(1);
  const [hasMoreNotifications, setHasMoreNotifications] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [courseImages, setCourseImages] = useState<
    Record<string, ImageSourcePropType>
  >({});
  const notificationGenerationRef = useRef(0);
  const loadMoreFlightRef = useRef<symbol | null>(null);
  const markAllFlightRef = useRef<symbol | null>(null);
  const readFlightsRef = useRef(new Set<string>());

  useEffect(
    () =>
      LOCAL_DEMO_ENABLED
        ? subscribeDemoExperience(setExperience)
        : () => undefined,
    [],
  );
  useEffect(() => {
    let active = true;
    void AsyncStorage.getItem(READ_KEY)
      .then(value => {
        if (!active) return;
        try {
          if (!value) return;
          const parsed = JSON.parse(value);
          if (!Array.isArray(parsed)) return;
          const compact = Array.from(
            new Set(parsed.filter(item => typeof item === 'string')),
          ).slice(-MAX_LOCAL_READ_IDS) as string[];
          setReadIds(compact);
          if (compact.length !== parsed.length) {
            void AsyncStorage.setItem(READ_KEY, JSON.stringify(compact)).catch(
              () => undefined,
            );
          }
        } catch {
          // Ignore a damaged preference; notifications remain usable.
        }
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);
  const refreshNotifications = useCallback(async () => {
    const requestGeneration = ++notificationGenerationRef.current;
    loadMoreFlightRef.current = null;
    setLoadingMore(false);
    setLoading(true);
    try {
      const sessionAvailable = await hasSession();
      if (requestGeneration !== notificationGenerationRef.current) return;
      setServerSession(sessionAvailable);
      if (!sessionAvailable) {
        setServerNotifications([]);
        setCourseImages({});
        setNotificationPage(1);
        setHasMoreNotifications(false);
        setNotificationError('');
        return;
      }
      const [page, cachedCourses] = await Promise.all([
        getNotificationsPage(),
        getCachedPublishedCourses().catch(() => []),
      ]);
      if (requestGeneration !== notificationGenerationRef.current) return;
      setServerNotifications(page.notifications);
      setCourseImages(
        Object.fromEntries(
          cachedCourses.map(course => [course.id, course.image]),
        ),
      );
      setNotificationPage(page.page);
      setHasMoreNotifications(page.hasMore);
      setNotificationError('');
    } catch {
      if (requestGeneration === notificationGenerationRef.current) {
        setNotificationError('تعذّر تحديث الإشعارات الآن. لم نفقد أي تحديث.');
      }
    } finally {
      if (requestGeneration === notificationGenerationRef.current) {
        setLoading(false);
      }
    }
  }, []);

  const loadMoreNotifications = useCallback(async () => {
    if (
      serverSession !== true ||
      loading ||
      loadingMore ||
      loadMoreFlightRef.current ||
      !hasMoreNotifications
    ) {
      return;
    }
    const flight = Symbol('notifications-load-more');
    const requestGeneration = notificationGenerationRef.current;
    loadMoreFlightRef.current = flight;
    setLoadingMore(true);
    try {
      const page = await getNotificationsPage({
        page: notificationPage + 1,
      });
      if (
        loadMoreFlightRef.current !== flight ||
        requestGeneration !== notificationGenerationRef.current
      )
        return;
      setServerNotifications(current => {
        const merged = new Map(current.map(item => [item.id, item]));
        page.notifications.forEach(item => merged.set(item.id, item));
        return Array.from(merged.values());
      });
      setNotificationPage(page.page);
      setHasMoreNotifications(page.hasMore);
      setNotificationError('');
    } catch {
      if (
        loadMoreFlightRef.current === flight &&
        requestGeneration === notificationGenerationRef.current
      ) {
        setNotificationError('تعذّر تحميل الإشعارات الأقدم\nحاول مرة أخرى');
      }
    } finally {
      if (loadMoreFlightRef.current === flight) {
        loadMoreFlightRef.current = null;
        setLoadingMore(false);
      }
    }
  }, [
    hasMoreNotifications,
    loading,
    loadingMore,
    notificationPage,
    serverSession,
  ]);

  useFocusEffect(
    useCallback(() => {
      let active = true;
      void refreshNotifications();
      void getLocalLearningState()
        .then(state => {
          if (active) {
            setLearning({
              completed: state.completedSections.filter(id =>
                id.startsWith('demo-section-'),
              ).length,
              passed: state.passedProjects.filter(id =>
                id.startsWith('demo-project-'),
              ).length,
            });
          }
        })
        .catch(() => undefined);
      return () => {
        active = false;
        notificationGenerationRef.current += 1;
        loadMoreFlightRef.current = null;
        markAllFlightRef.current = null;
      };
    }, [refreshNotifications]),
  );

  const demoNotifications = useMemo<NotificationItem[]>(() => {
    const items: NotificationItem[] = [];
    if (learning.completed > 0 && learning.completed < 30) {
      items.push({
        id: `learning-${learning.completed}`,
        title: 'مقطعك التالي جاهز',
        description: formatArabicDisplayText(
          `أنهيت ${learning.completed} من ٣٠ مقطعًا\nأكمل من مكانك`,
        ),
        time: 'الآن',
        read: false,
        tone: 'learning',
        link: 'rokn://course/demo-freelance-course/watch',
        actionLabel: 'أكمل من مكانك',
      });
    }
    if (learning.passed > 0) {
      items.push({
        id: `projects-${learning.passed}`,
        title:
          learning.passed === 3 ? 'اكتملت مشروعات الكورس' : 'تم اعتماد مشروعك',
        description: formatArabicDisplayText(
          `اعتمدنا ${learning.passed} من ٣ مشروعات\nالمقطع التالي مفتوح`,
        ),
        time: 'آخر تقدم',
        read: false,
        tone: 'project',
        link: 'rokn://course/demo-freelance-course/watch',
        actionLabel: 'افتح النتيجة',
      });
    }
    (experience?.transactions ?? []).slice(0, 6).forEach(transaction => {
      items.push({
        id: `transaction-${transaction.id}`,
        title:
          transaction.amount > 0
            ? formatArabicDisplayText(`زاد رصيدك ${transaction.amount}`)
            : 'تم فتح الكورس',
        description: transaction.title,
        time: new Date(transaction.createdAt).toLocaleDateString('ar-EG', {
          day: 'numeric',
          month: 'short',
        }),
        read: false,
        tone: 'coins',
        link: 'rokn://wallet',
        actionLabel: 'افتح المحفظة',
      });
    });
    return items;
  }, [experience?.transactions, learning.completed, learning.passed]);

  const source = useMemo<NotificationItem[]>(() => {
    if (serverSession !== true) {
      return serverSession === false && LOCAL_DEMO_ENABLED
        ? demoNotifications
        : [];
    }
    return serverNotifications.map(item => ({
      id: item.id,
      title: item.title,
      description: item.description,
      time: item.createdAt
        ? new Date(item.createdAt).toLocaleDateString('ar-EG', {
            day: 'numeric',
            month: 'short',
          })
        : '',
      read: item.read,
      tone: item.tone,
      link: item.link,
      image: item.imageUrl
        ? {uri: item.imageUrl}
        : item.courseId
        ? courseImages[item.courseId]
        : undefined,
      actionLabel: item.actionLabel,
    }));
  }, [courseImages, demoNotifications, serverNotifications, serverSession]);

  const updateReadIds = (next: string[]) => {
    // Keep the demo/offline read list bounded; the API owns server state.
    const unique = Array.from(new Set(next)).slice(-MAX_LOCAL_READ_IDS);
    setReadIds(unique);
    void AsyncStorage.setItem(READ_KEY, JSON.stringify(unique)).catch(
      () => undefined,
    );
  };

  const hasUnread = source.some(
    item =>
      !item.read && (serverSession === true || !readIds.includes(item.id)),
  );

  const markAllRead = async () => {
    if (serverSession === true) {
      if (markAllFlightRef.current) return;
      const flight = Symbol('notifications-mark-all-read');
      markAllFlightRef.current = flight;
      const requestGeneration = notificationGenerationRef.current;
      setServerNotifications(current =>
        current.map(item => ({...item, read: true})),
      );
      try {
        await markAllNotificationsRead();
      } catch {
        if (requestGeneration === notificationGenerationRef.current) {
          setNotificationError('تعذّر تحديث حالة القراءة\nحاول مرة أخرى');
          void refreshNotifications();
        }
      } finally {
        if (markAllFlightRef.current === flight) {
          markAllFlightRef.current = null;
        }
      }
      return;
    }
    updateReadIds(
      Array.from(new Set([...readIds, ...source.map(item => item.id)])),
    );
  };

  const openNotification = async (item: NotificationItem, read: boolean) => {
    if (!read) {
      if (serverSession === true) {
        setServerNotifications(current =>
          current.map(notification =>
            notification.id === item.id
              ? {...notification, read: true}
              : notification,
          ),
        );
        if (!readFlightsRef.current.has(item.id)) {
          readFlightsRef.current.add(item.id);
          void markNotificationRead(item.id)
            .catch(() => {
              // The next refresh reconciles an optimistic read state.
            })
            .finally(() => readFlightsRef.current.delete(item.id));
        }
      } else {
        updateReadIds([...readIds, item.id]);
      }
    }
    if (item.link) {
      const destination = parseRoknDestination(item.link);
      if (destination) {
        navigation.dispatch(
          CommonActions.navigate(
            destination.name,
            'params' in destination ? destination.params : undefined,
          ),
        );
        return;
      }
      try {
        if (isExternalWebLink(item.link)) {
          await Linking.openURL(item.link);
          return;
        }
        setNotificationError(
          'رابط الإشعار غير مكتمل\nحدّث الصفحة ثم حاول مرة أخرى',
        );
      } catch {
        setNotificationError('تعذّر فتح وجهة الإشعار الآن');
      }
    }
  };

  const renderNotification = ({item}: ListRenderItemInfo<NotificationItem>) => {
    const read =
      item.read || (serverSession !== true && readIds.includes(item.id));
    const gradient =
      item.tone === 'coins'
        ? ['rgba(216,166,60,0.18)', 'rgba(17,22,32,0.98)']
        : item.tone === 'project'
        ? ['rgba(72,185,138,0.15)', 'rgba(17,22,32,0.98)']
        : ['rgba(44,105,219,0.17)', 'rgba(17,22,32,0.98)'];
    return (
      <View
        style={[
          styles.itemFrame,
          {maxWidth: contentWidth, paddingHorizontal: gutter},
        ]}>
        <Pressable
          accessibilityRole="button"
          onPress={() => openNotification(item, read)}
          style={({pressed}) => [
            styles.cardPressable,
            pressed && styles.pressed,
          ]}>
          <LinearGradient
            colors={gradient}
            end={{x: 0, y: 1}}
            start={{x: 1, y: 0}}
            style={[
              styles.row,
              compactLayout && styles.rowCompact,
              !read && styles.unreadRow,
            ]}>
            <View
              style={[
                styles.mark,
                item.tone === 'coins' && styles.coinMark,
                item.tone === 'project' && styles.projectMark,
                compactLayout && styles.markCompact,
                Boolean(item.image) &&
                  !failedImages[item.id] &&
                  styles.courseMark,
              ]}>
              {item.tone === 'coins' ? (
                <RoknCoinStack size={compactLayout ? 34 : 42} />
              ) : item.image && !failedImages[item.id] ? (
                <Image
                  onError={() =>
                    setFailedImages(current => ({
                      ...current,
                      [item.id]: true,
                    }))
                  }
                  source={item.image}
                  style={styles.courseImage}
                />
              ) : (
                <NotificationIcon width={23} height={23} />
              )}
            </View>
            <View style={styles.copy}>
              <View style={styles.titleRow}>
                <Text numberOfLines={2} style={styles.title}>
                  {formatArabicDisplayText(item.title)}
                </Text>
                {!read && (
                  <View
                    accessibilityLabel="غير مقروء"
                    style={styles.unreadDot}
                  />
                )}
              </View>
              <Text
                numberOfLines={compactLayout ? 4 : 3}
                style={styles.description}>
                {formatArabicDisplayText(item.description)}
              </Text>
              <View
                style={[
                  styles.metaRow,
                  compactLayout && styles.metaRowCompact,
                ]}>
                {!!item.link && !!item.actionLabel && (
                  <View style={styles.actionPill}>
                    <Text style={styles.actionLabel}>
                      {formatArabicDisplayText(item.actionLabel)}
                    </Text>
                  </View>
                )}
                {!!item.time && (
                  <Text style={styles.time}>
                    {formatArabicDisplayText(item.time)}
                  </Text>
                )}
              </View>
            </View>
          </LinearGradient>
        </Pressable>
      </View>
    );
  };

  const frameStyle = {maxWidth: contentWidth, paddingHorizontal: gutter};
  const showLoading = loading && !source.length;
  const showError =
    !showLoading &&
    notificationError &&
    serverSession !== false &&
    !source.length;

  return (
    <Container noPadding>
      <FlatList
        accessibilityRole="list"
        contentContainerStyle={styles.listContent}
        data={source}
        initialNumToRender={8}
        keyExtractor={item => item.id}
        ListHeaderComponent={
          <View style={[styles.headerFrame, frameStyle]}>
            <HeaderWithBack title="الإشعارات" />
            <SectionHeading
              actionLabel={hasUnread ? 'تحديد الكل كمقروء' : undefined}
              onAction={markAllRead}
              title="آخر التحديثات"
              eyebrow="ما يساعدك على الاستمرار فقط"
            />
            {showLoading ? (
              <StatusView
                state="loading"
                description="نجمع آخر ما يهمك فقط."
                title="نحدّث إشعاراتك"
              />
            ) : showError ? (
              <StatusView
                actionLabel="إعادة المحاولة"
                description={notificationError}
                onAction={refreshNotifications}
                state="error"
                title="تعذّر تحديث الإشعارات"
              />
            ) : !source.length ? (
              <StatusView
                description="تذكيرات التعلم ونتائج المشروعات وحركة العملات"
                title="لا توجد إشعارات"
              />
            ) : notificationError && serverSession === true ? (
              <Pressable
                accessibilityRole="button"
                onPress={refreshNotifications}
                style={styles.staleNotice}>
                <Text style={styles.staleNoticeText}>{notificationError}</Text>
                <Text style={styles.staleNoticeAction}>إعادة المحاولة</Text>
              </Pressable>
            ) : null}
          </View>
        }
        ListFooterComponent={
          serverSession === true && hasMoreNotifications ? (
            <View style={[styles.footerFrame, frameStyle]}>
              <Pressable
                accessibilityRole="button"
                disabled={loadingMore}
                onPress={() => void loadMoreNotifications()}
                style={({pressed}) => [
                  styles.loadMore,
                  pressed && styles.pressed,
                  loadingMore && styles.loadMoreDisabled,
                ]}>
                <Text style={styles.loadMoreText}>
                  {loadingMore ? 'نحمّل الأقدم' : 'عرض إشعارات أقدم'}
                </Text>
              </Pressable>
            </View>
          ) : null
        }
        maxToRenderPerBatch={8}
        onRefresh={() => void refreshNotifications()}
        refreshing={loading && source.length > 0}
        removeClippedSubviews={Platform.OS === 'android'}
        renderItem={renderNotification}
        showsVerticalScrollIndicator={false}
        updateCellsBatchingPeriod={40}
        windowSize={7}
      />
    </Container>
  );
}

const styles = StyleSheet.create({
  listContent: {paddingBottom: 100},
  headerFrame: {width: '100%', alignSelf: 'center'},
  itemFrame: {width: '100%', alignSelf: 'center', marginTop: Spacing.sm},
  footerFrame: {width: '100%', alignSelf: 'center'},
  cardPressable: {borderRadius: Radius.lg, overflow: 'hidden'},
  row: {
    minHeight: 116,
    ...rtlRowStyle,
    alignItems: 'flex-start',
    gap: Spacing.sm,
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.md,
    opacity: 0.82,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    borderRadius: Radius.lg,
  },
  rowCompact: {
    minHeight: 0,
    gap: Spacing.xs,
    paddingHorizontal: Spacing.sm,
    paddingVertical: Spacing.sm,
  },
  unreadRow: {opacity: 1},
  mark: {
    width: 46,
    height: 46,
    flexShrink: 0,
    borderRadius: 14,
    backgroundColor: Palette.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
  },
  markCompact: {width: 40, height: 40, borderRadius: 12},
  coinMark: {backgroundColor: Palette.coinSoft},
  projectMark: {backgroundColor: 'rgba(72,185,138,0.12)'},
  courseMark: {borderWidth: 1, borderColor: Palette.lineSoft},
  courseImage: {width: '100%', height: '100%', resizeMode: 'cover'},
  markInner: {
    width: 10,
    height: 10,
    borderRadius: 5,
    backgroundColor: Palette.primary,
  },
  copy: {flex: 1, minWidth: 0},
  titleRow: {...rtlRowStyle, alignItems: 'center'},
  title: {...Type.bodyStrong, ...textDirection, color: Palette.text, flex: 1},
  unreadDot: {
    width: 7,
    height: 7,
    borderRadius: 4,
    backgroundColor: Palette.primary,
    marginStart: Spacing.xs,
  },
  description: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 3,
  },
  metaRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    flexWrap: 'wrap',
    gap: Spacing.sm,
    marginTop: Spacing.sm,
  },
  metaRowCompact: {alignItems: 'flex-start', gap: Spacing.xs},
  time: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    flexShrink: 0,
  },
  actionPill: {
    minHeight: 30,
    maxWidth: '100%',
    justifyContent: 'center',
    paddingHorizontal: Spacing.sm,
    borderRadius: Radius.pill,
    backgroundColor: 'rgba(139,181,255,0.12)',
    borderWidth: 1,
    borderColor: 'rgba(139,181,255,0.18)',
    flexShrink: 1,
  },
  actionLabel: {...Type.caption, ...textDirection, color: '#A8C7FF'},
  loadMore: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.md,
    paddingHorizontal: Spacing.lg,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
  },
  loadMoreDisabled: {opacity: 0.6},
  loadMoreText: {...Type.bodyStrong, ...textDirection, color: '#A8C7FF'},
  staleNotice: {
    minHeight: 52,
    marginTop: Spacing.md,
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.sm,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: 'rgba(240,100,105,0.2)',
    backgroundColor: 'rgba(240,100,105,0.07)',
  },
  staleNoticeText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
  },
  staleNoticeAction: {
    ...Type.caption,
    ...textDirection,
    color: Palette.danger,
    marginTop: Spacing.xxs,
  },
  pressed: {opacity: 0.66},
});
