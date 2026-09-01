import React, {useEffect, useState} from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
} from '../constants/designSystem';
import {
  createDebugBundle,
  DebugBundle,
  shareDebugBundle,
} from '../services/debugBundle';
import {PremiumCard} from './ui/PremiumUI';

const enabledLabel = (enabled: boolean) => (enabled ? 'مفعّل' : 'متوقف');

/** Self-contained support card; a parent screen only needs to render it. */
export const DebugBundleShareCard = () => {
  const [bundle, setBundle] = useState<DebugBundle | null>(null);
  const [sharing, setSharing] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    let mounted = true;
    void createDebugBundle().then(result => {
      if (mounted) setBundle(result);
    });
    return () => {
      mounted = false;
    };
  }, []);

  const share = async () => {
    if (sharing) return;
    setSharing(true);
    setError('');
    try {
      const sharedBundle = await shareDebugBundle();
      setBundle(sharedBundle);
    } catch {
      setError('تعذّرت المشاركة الآن\nحاول مرة أخرى');
    } finally {
      setSharing(false);
    }
  };

  const buildLabel = bundle?.app.build_number
    ? `${bundle.app.version} (${bundle.app.build_number})`
    : bundle?.app.version;

  return (
    <PremiumCard style={styles.card}>
      <Text accessibilityRole="header" style={styles.title}>
        معلومات تشخيصية آمنة
      </Text>
      <Text style={styles.description}>
        تقرير مختصر يساعد الدعم في معرفة سبب العطل
        {'\n'}لا يتضمن بيانات حسابك أو رسائلك
      </Text>

      {bundle ? (
        <View accessibilityLabel="ملخص معلومات التشخيص" style={styles.summary}>
          <Text style={styles.summaryLine}>الإصدار: {buildLabel}</Text>
          <Text style={styles.summaryLine}>
            المنصة: {bundle.app.platform}
            {bundle.app.os_major ? ` ${bundle.app.os_major}` : ''}
          </Text>
          <Text style={styles.summaryLine}>
            القناة: {bundle.app.distribution_channel}
          </Text>
          <Text style={styles.summaryLine}>
            العرض التجريبي: {enabledLabel(bundle.feature_flags.local_demo_enabled)}
          </Text>
          <Text style={styles.summaryLine}>
            الدفع الخارجي:{' '}
            {enabledLabel(bundle.feature_flags.external_checkout_enabled)}
          </Text>
          <Text style={styles.summaryLine}>
            تحكم الميزات: {bundle.product_controls.source}
          </Text>
          <Text style={styles.summaryLine}>
            الدفع / الفيديو / المشاريع / Rokn AI:{' '}
            {[
              bundle.product_controls.flags.checkout,
              bundle.product_controls.flags.playback,
              bundle.product_controls.flags.project_uploads,
              bundle.product_controls.flags.ai_chat,
            ]
              .map(enabledLabel)
              .join(' · ')}
          </Text>
          <Text style={styles.summaryLine}>
            أحداث التشغيل الأخيرة: {bundle.operational_events.length}
          </Text>
          {bundle.operational_events.slice(0, 3).map((event, index) => (
            <Text
              key={`${event.event}-${event.occurred_at}-${index}`}
              style={styles.eventLine}>
              {event.event} · {event.code} · {event.severity}
            </Text>
          ))}
        </View>
      ) : (
        <ActivityIndicator
          accessibilityLabel="جارٍ تجهيز معلومات التشخيص"
          color={Palette.primary}
          style={styles.loader}
        />
      )}

      {!!error && (
        <Text accessibilityRole="alert" style={styles.error}>
          {error}
        </Text>
      )}
      <Pressable
        accessibilityHint="يفتح قائمة المشاركة بتقرير منزوع البيانات الشخصية"
        accessibilityLabel="مشاركة معلومات التشخيص الآمنة"
        accessibilityRole="button"
        accessibilityState={{busy: sharing, disabled: sharing}}
        disabled={sharing}
        onPress={() => void share()}
        style={({pressed}) => [
          styles.shareButton,
          sharing && styles.shareButtonDisabled,
          pressed && styles.pressed,
        ]}>
        {sharing ? (
          <ActivityIndicator color={Palette.text} size="small" />
        ) : (
          <Text style={styles.shareButtonText}>مشاركة التقرير الآمن</Text>
        )}
      </Pressable>
    </PremiumCard>
  );
};

const styles = StyleSheet.create({
  card: {padding: Spacing.lg, gap: Spacing.sm},
  title: {...Type.section, ...textDirection, color: Palette.text},
  description: {...Type.caption, ...textDirection, color: Palette.textMuted},
  summary: {
    padding: Spacing.md,
    borderRadius: Radius.md,
    backgroundColor: Palette.canvasSoft,
    gap: Spacing.xxs,
  },
  summaryLine: {...Type.caption, ...textDirection, color: Palette.text},
  eventLine: {...Type.caption, color: Palette.textMuted, textAlign: 'left'},
  loader: {minHeight: Accessibility.minTouchTarget},
  error: {...Type.caption, ...textDirection, color: Palette.danger},
  shareButton: {
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
  },
  shareButtonDisabled: {opacity: 0.5},
  shareButtonText: {...Type.button, color: Palette.text},
  pressed: {opacity: 0.75},
});
