import React, {useEffect, useMemo, useState} from 'react';
import {
  ActivityIndicator,
  Image,
  Linking,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import Svg, {Circle, Path} from 'react-native-svg';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {areSmartRemindersSupported} from '../../services/smartReminders';
import {MoreBellIcon} from '../../assets/SVG';
import {RoknCoinStack} from './RoknCoin';

type PrimerPhase = 'idle' | 'requesting' | 'denied';

type Props = {
  visible: boolean;
  onClose: () => void;
  onEnable: () => Promise<boolean>;
};

const LearningStepIcon = () => (
  <Svg height={28} viewBox="0 0 28 28" width={28}>
    <Circle
      cx={14}
      cy={14}
      fill="none"
      r={10.5}
      stroke="#78A8FF"
      strokeDasharray="46 20"
      strokeLinecap="round"
      strokeWidth={2}
    />
    <Path d="M12 9.5 19 14l-7 4.5v-9Z" fill="#D9E6FF" />
  </Svg>
);

const CourseSuggestionIcon = () => (
  <Svg height={28} viewBox="0 0 28 28" width={28}>
    <Path
      d="M6 7.5h12.5A3.5 3.5 0 0 1 22 11v9.5H9.5A3.5 3.5 0 0 1 6 17V7.5Z"
      fill="none"
      stroke="#78A8FF"
      strokeLinejoin="round"
      strokeWidth={2}
    />
    <Path
      d="m20.4 4.7.65 1.7 1.7.65-1.7.65-.65 1.7-.65-1.7-1.7-.65 1.7-.65.65-1.7Z"
      fill="#E6C465"
      stroke="#E6C465"
      strokeLinejoin="round"
    />
  </Svg>
);

const Benefit = ({
  icon,
  label,
  stacked,
}: {
  icon: React.ReactNode;
  label: string;
  stacked: boolean;
}) => (
  <View style={[styles.benefit, stacked && styles.benefitStacked]}>
    <View style={styles.benefitIcon}>{icon}</View>
    <Text
      maxFontSizeMultiplier={1.3}
      style={[styles.benefitLabel, stacked && styles.benefitLabelStacked]}>
      {label}
    </Text>
  </View>
);

export default function NotificationPermissionPrimer({
  visible,
  onClose,
  onEnable,
}: Props) {
  const insets = useSafeAreaInsets();
  const {width, fontScale} = useResponsiveLayout();
  const [phase, setPhase] = useState<PrimerPhase>('idle');
  const supported = areSmartRemindersSupported();
  const stackBenefits = width < 400 || fontScale > 1.15;

  useEffect(() => {
    if (!visible) setPhase('idle');
  }, [visible]);

  const copy = useMemo(() => {
    if (!supported) {
      return {
        eyebrow: 'من غير ما يعطّلك',
        title: 'الإشعارات مش متاحة على الجهاز ده',
        body: 'تقدر تكمّل تعليمك عادي وكل تقدمك هيفضل محفوظ',
        footnote: 'هتلاقي كل التحديثات المهمة جوه ركن',
        action: 'تمام',
      };
    }
    if (phase === 'denied') {
      return {
        eyebrow: 'خطوة واحدة',
        title: 'الإشعارات مقفولة من الهاتف',
        body: 'افتح إعدادات ركن واسمح بالإشعارات وبعدها اختار الأنواع اللي تهمك',
        footnote: 'تقدر تكمّل من غيرها عادي',
        action: 'فتح إعدادات الهاتف',
      };
    }
    return {
      eyebrow: 'على قد اللي يهمك',
      title: 'خليك عارف خطوتك الجاية',
      body: 'نرجّعك لمكانك لما تكون جاهز ونبلّغك لو وصلتلك مكافأة أو نزل كورس يستاهل تشوفه',
      footnote: 'العروض اختيارية من الإعدادات وتقدر توقف أي نوع وقت ما تحب',
      action: 'فعّل الإشعارات',
    };
  }, [phase, supported]);

  const close = () => {
    if (phase !== 'requesting') onClose();
  };

  const handlePrimary = async () => {
    if (phase === 'requesting') return;
    if (!supported) {
      onClose();
      return;
    }
    if (phase === 'denied') {
      try {
        await Linking.openSettings();
        onClose();
      } catch {
        // Keep the explanation visible if the OS settings page is unavailable.
      }
      return;
    }

    setPhase('requesting');
    try {
      if (await onEnable()) {
        onClose();
      } else {
        setPhase('denied');
      }
    } catch {
      setPhase('denied');
    }
  };

  return (
    <Modal
      animationType="fade"
      onRequestClose={close}
      statusBarTranslucent
      transparent
      visible={visible}>
      <View style={styles.overlay}>
        <ScrollView
          bounces={false}
          contentContainerStyle={[
            styles.scrollContent,
            {
              paddingTop: Math.max(insets.top, Spacing.lg),
              paddingBottom: Math.max(insets.bottom, Spacing.lg),
            },
          ]}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}>
          <View accessibilityViewIsModal style={styles.card}>
            <View pointerEvents="none" style={styles.glowBlue} />
            <View pointerEvents="none" style={styles.glowGold} />

            <View style={styles.topRow}>
              <View style={styles.brandIdentity}>
                <View style={styles.brandMark}>
                  <Image
                    accessibilityIgnoresInvertColors
                    source={require('../../assets/images/authLogo.png')}
                    style={styles.brandImage}
                  />
                </View>
                <View style={styles.brandCopy}>
                  <Text style={styles.brandTitle}>إشعارات ركن</Text>
                  <Text style={styles.brandSubtitle}>أنت اللي تختارها</Text>
                </View>
              </View>
              <Pressable
                accessibilityLabel="إغلاق"
                accessibilityRole="button"
                disabled={phase === 'requesting'}
                hitSlop={8}
                onPress={close}
                style={({pressed}) => [
                  styles.closeButton,
                  pressed && styles.pressed,
                ]}>
                <Text accessibilityElementsHidden style={styles.closeText}>
                  ×
                </Text>
              </Pressable>
            </View>

            {supported && phase !== 'denied' ? (
              <View
                accessibilityLabel="استكمال التعلّم والمكافآت والكورسات المقترحة"
                style={[
                  styles.benefits,
                  stackBenefits && styles.benefitsStacked,
                ]}>
                <Benefit
                  icon={<LearningStepIcon />}
                  label="كمّل من مكانك"
                  stacked={stackBenefits}
                />
                <Benefit
                  icon={<RoknCoinStack size={46} />}
                  label="مكافآت وصلتلك"
                  stacked={stackBenefits}
                />
                <Benefit
                  icon={<CourseSuggestionIcon />}
                  label="كورسات تستاهل"
                  stacked={stackBenefits}
                />
              </View>
            ) : (
              <View style={styles.stateIcon}>
                <MoreBellIcon height={30} width={30} />
              </View>
            )}

            <Text style={styles.eyebrow}>{copy.eyebrow}</Text>
            <Text accessibilityRole="header" style={styles.title}>
              {copy.title}
            </Text>
            <Text style={styles.body}>{copy.body}</Text>

            <Pressable
              accessibilityRole="button"
              disabled={phase === 'requesting'}
              onPress={() => void handlePrimary()}
              style={({pressed}) => [
                styles.primary,
                phase === 'requesting' && styles.primaryDisabled,
                pressed && styles.pressed,
              ]}>
              {phase === 'requesting' ? (
                <View style={styles.loadingRow}>
                  <ActivityIndicator color="#FFFFFF" size="small" />
                  <Text style={styles.primaryText}>لحظة واحدة</Text>
                </View>
              ) : (
                <Text style={styles.primaryText}>{copy.action}</Text>
              )}
            </Pressable>

            <Text style={styles.footnote}>{copy.footnote}</Text>
            {supported && (
              <Pressable
                accessibilityRole="button"
                disabled={phase === 'requesting'}
                onPress={close}
                style={({pressed}) => [
                  styles.later,
                  pressed && styles.pressed,
                ]}>
                <Text style={styles.laterText}>مش دلوقتي</Text>
              </Pressable>
            )}
          </View>
        </ScrollView>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    backgroundColor: 'rgba(2,4,8,0.82)',
  },
  scrollContent: {
    flexGrow: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
  },
  card: {
    width: '100%',
    maxWidth: 480,
    overflow: 'hidden',
    padding: Spacing.lg,
    borderRadius: Radius.xl,
    borderWidth: 1,
    borderColor: 'rgba(123,163,235,0.22)',
    backgroundColor: '#131924',
  },
  glowBlue: {
    position: 'absolute',
    top: -88,
    end: -72,
    width: 210,
    height: 210,
    borderRadius: 105,
    backgroundColor: 'rgba(44,105,219,0.14)',
  },
  glowGold: {
    position: 'absolute',
    bottom: -100,
    start: -85,
    width: 190,
    height: 190,
    borderRadius: 95,
    backgroundColor: 'rgba(216,166,60,0.07)',
  },
  topRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: Spacing.sm,
  },
  brandIdentity: {
    ...rtlRowStyle,
    flex: 1,
    minWidth: 0,
    alignItems: 'center',
    gap: Spacing.sm,
  },
  brandMark: {
    width: 42,
    height: 42,
    flexShrink: 0,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 13,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
    backgroundColor: Palette.surfacePressed,
  },
  brandImage: {width: 27, height: 27, resizeMode: 'contain'},
  brandCopy: {
    flex: 1,
    minWidth: 0,
    direction: 'rtl',
    alignItems: 'stretch',
  },
  brandTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    width: '100%',
    color: Palette.text,
  },
  brandSubtitle: {
    ...Type.caption,
    ...textDirection,
    width: '100%',
    color: Palette.textMuted,
  },
  closeButton: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    flexShrink: 0,
    alignItems: 'center',
    justifyContent: 'center',
    marginStart: -Spacing.xs,
    borderRadius: Radius.pill,
    backgroundColor: 'rgba(255,255,255,0.06)',
  },
  closeText: {
    color: Palette.textMuted,
    fontSize: 29,
    fontWeight: '300',
    lineHeight: 32,
  },
  benefits: {
    ...rtlRowStyle,
    width: '100%',
    gap: Spacing.xs,
    marginTop: Spacing.lg,
  },
  benefitsStacked: {flexDirection: 'column'},
  benefit: {
    flex: 1,
    minWidth: 0,
    minHeight: 112,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: Spacing.xxs,
    paddingVertical: Spacing.sm,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: 'rgba(255,255,255,0.035)',
  },
  benefitStacked: {
    ...rtlRowStyle,
    width: '100%',
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'flex-start',
    gap: Spacing.sm,
    paddingHorizontal: Spacing.md,
  },
  benefitIcon: {
    width: 48,
    height: 48,
    flexShrink: 0,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 16,
    backgroundColor: 'rgba(44,105,219,0.12)',
  },
  benefitLabel: {
    ...Type.caption,
    ...textDirection,
    color: Palette.text,
    textAlign: 'center',
    marginTop: Spacing.xs,
  },
  benefitLabelStacked: {
    flex: 1,
    width: '100%',
    marginTop: 0,
    ...textDirection,
  },
  stateIcon: {
    width: 64,
    height: 64,
    alignSelf: 'center',
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.lg,
    borderRadius: 22,
    backgroundColor: Palette.primarySoft,
  },
  eyebrow: {
    ...Type.caption,
    ...textDirection,
    alignSelf: 'center',
    color: '#9DBDFA',
    marginTop: Spacing.lg,
  },
  title: {
    ...Type.title,
    ...textDirection,
    alignSelf: 'stretch',
    color: Palette.text,
    textAlign: 'center',
    marginTop: Spacing.xxs,
  },
  body: {
    ...Type.body,
    ...textDirection,
    alignSelf: 'stretch',
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: Spacing.xs,
  },
  primary: {
    width: '100%',
    minHeight: 54,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.lg,
    paddingHorizontal: Spacing.md,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
  },
  primaryDisabled: {opacity: 0.68},
  primaryText: {...Type.button, ...textDirection, color: '#FFFFFF'},
  loadingRow: {...rtlRowStyle, alignItems: 'center', gap: Spacing.sm},
  footnote: {
    ...Type.caption,
    ...textDirection,
    alignSelf: 'stretch',
    color: Palette.textFaint,
    textAlign: 'center',
    marginTop: Spacing.sm,
  },
  later: {
    minHeight: Accessibility.minTouchTarget,
    alignSelf: 'center',
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: Spacing.lg,
  },
  laterText: {...Type.bodyStrong, ...textDirection, color: Palette.textMuted},
  pressed: {opacity: 0.72, transform: [{scale: 0.985}]},
});
