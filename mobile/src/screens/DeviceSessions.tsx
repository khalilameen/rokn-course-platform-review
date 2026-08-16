import {useFocusEffect} from '@react-navigation/native';
import React, {useCallback, useState} from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {Container, Content} from '../components/containers/Containers';
import {PremiumCard, ResponsiveFrame} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {toArabicDigits} from '../constants/arabicFormatting';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../constants/designSystem';
import {
  getDeviceSessions,
  revokeDeviceSession,
  type DeviceSession,
} from '../services/deviceSessions';

const dateLabel = (value?: string | null) => {
  if (!value) return 'غير معروف';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return 'غير معروف';
  return new Intl.DateTimeFormat('ar-EG', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
};

const platformLabel = (platform: DeviceSession['platform']) => {
  if (platform === 'android') return 'هاتف Android';
  if (platform === 'ios') return 'iPhone أو iPad';
  if (platform === 'web') return 'متصفح ويب';
  return 'جهاز آخر';
};

export default function DeviceSessions() {
  const insets = useSafeAreaInsets();
  const [sessions, setSessions] = useState<DeviceSession[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [removing, setRemoving] = useState<string | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(async (refresh = false) => {
    refresh ? setRefreshing(true) : setLoading(true);
    setError('');
    try {
      setSessions(await getDeviceSessions());
    } catch {
      setError('لم نقدر نحمّل الأجهزة الآن');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  const revoke = (session: DeviceSession) => {
    if (session.current || removing) return;
    Alert.alert(
      'تسجيل الخروج من هذا الجهاز؟',
      'سيحتاج تسجيل الدخول من جديد على هذا الجهاز فقط',
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'تسجيل الخروج',
          style: 'destructive',
          onPress: async () => {
            setRemoving(session.id);
            try {
              await revokeDeviceSession(session.id);
              setSessions(current =>
                current.filter(item => item.id !== session.id),
              );
            } catch {
              Alert.alert('لم يتم تسجيل الخروج', 'حاول مرة أخرى بعد قليل');
            } finally {
              setRemoving(null);
            }
          },
        },
      ],
    );
  };

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame>
          <HeaderWithBack title="الأجهزة المسجّل عليها" />
          <ScrollView
            contentContainerStyle={[
              styles.content,
              {
                paddingBottom: Math.max(
                  Spacing.section,
                  insets.bottom + Spacing.xl,
                ),
              },
            ]}
            refreshControl={
              <RefreshControl
                refreshing={refreshing}
                tintColor={Palette.primary}
                onRefresh={() => void load(true)}
              />
            }>
            <Text style={styles.intro}>
              لو لقيت جهاز مش بتستخدمه، سجّل خروجك منه هنا
            </Text>

            {loading ? (
              <ActivityIndicator color={Palette.primary} size="large" />
            ) : error ? (
              <PremiumCard style={styles.stateCard}>
                <Text style={styles.stateText}>{error}</Text>
                <Pressable
                  accessibilityLabel="إعادة تحميل الأجهزة المسجّل عليها"
                  accessibilityRole="button"
                  style={styles.retryButton}
                  onPress={() => void load()}>
                  <Text style={styles.retryText}>حاول مرة أخرى</Text>
                </Pressable>
              </PremiumCard>
            ) : sessions.length === 0 ? (
              <PremiumCard style={styles.stateCard}>
                <Text style={styles.stateText}>
                  ستظهر جلساتك هنا بعد تسجيل الدخول من النسخة الجديدة
                </Text>
              </PremiumCard>
            ) : (
              sessions.map(session => (
                <PremiumCard key={session.id} style={styles.sessionCard}>
                  <View style={styles.sessionHeader}>
                    <View style={styles.sessionCopy}>
                      <Text style={styles.sessionTitle}>
                        {platformLabel(session.platform)}
                      </Text>
                      <Text style={styles.sessionMeta}>
                        آخر استخدام{' '}
                        {dateLabel(session.last_used_at || session.issued_at)}
                      </Text>
                      {!!session.app_version && (
                        <Text style={styles.sessionMeta}>
                          إصدار {toArabicDigits(session.app_version)}
                          {session.app_build
                            ? ` (${toArabicDigits(session.app_build)})`
                            : ''}
                        </Text>
                      )}
                    </View>
                    {session.current && (
                      <View style={styles.currentPill}>
                        <Text style={styles.currentText}>هذا الجهاز</Text>
                      </View>
                    )}
                  </View>
                  {!session.current && (
                    <Pressable
                      accessibilityRole="button"
                      disabled={Boolean(removing)}
                      onPress={() => revoke(session)}
                      style={({pressed}) => [
                        styles.logoutButton,
                        pressed && styles.pressed,
                      ]}>
                      {removing === session.id ? (
                        <ActivityIndicator color={Palette.danger} />
                      ) : (
                        <Text style={styles.logoutText}>
                          تسجيل الخروج من الجهاز
                        </Text>
                      )}
                    </Pressable>
                  )}
                </PremiumCard>
              ))
            )}
          </ScrollView>
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  content: {paddingHorizontal: Spacing.lg, gap: Spacing.md},
  intro: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginBottom: Spacing.sm,
  },
  sessionCard: {padding: Spacing.lg, borderRadius: Radius.lg},
  sessionHeader: {...rtlRowStyle, alignItems: 'flex-start', gap: Spacing.md},
  sessionCopy: {flex: 1, minWidth: 0},
  sessionTitle: {...Type.section, ...textDirection, color: Palette.text},
  sessionMeta: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  currentPill: {
    borderRadius: Radius.pill,
    backgroundColor: Palette.primarySoft,
    paddingHorizontal: Spacing.sm,
    paddingVertical: Spacing.xs,
  },
  currentText: {...Type.caption, color: Palette.primary},
  logoutButton: {
    alignItems: 'center',
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.line,
    marginTop: Spacing.md,
    paddingTop: Spacing.md,
    minHeight: 44,
    justifyContent: 'center',
  },
  logoutText: {...Type.body, color: Palette.danger},
  stateCard: {padding: Spacing.xl, alignItems: 'center', gap: Spacing.md},
  stateText: {...Type.body, ...textDirection, color: Palette.textMuted},
  retryButton: {
    minHeight: 44,
    justifyContent: 'center',
    paddingHorizontal: Spacing.lg,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
  },
  retryText: {...Type.body, color: Palette.text},
  pressed: {opacity: 0.72},
});
