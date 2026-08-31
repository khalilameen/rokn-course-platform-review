import React, {useMemo, useState} from 'react';
import {
  Alert,
  Image,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import {useNavigation, useRoute} from '@react-navigation/native';
import type {RootNavigation, RootRoute} from '../navigation/types';
import {launchImageLibrary, PhotoQuality} from 'react-native-image-picker';
import {useTranslation} from 'react-i18next';

import {Container, Content} from '../components/containers/Containers';
import {
  PremiumCard,
  ResponsiveFrame,
  SectionHeading,
  StatusView,
} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../constants/designSystem';
import {
  FeedbackAttachment,
  ProductFeedbackCategory,
  submitProductFeedback,
} from '../services/productFeedback';
import {openSupportWhatsApp} from '../services/supportWhatsApp';
import {DebugBundleShareCard} from '../components/DebugBundleShareCard';

const CATEGORIES: Array<{key: ProductFeedbackCategory; label: string}> = [
  {key: 'problem', label: 'مشكلة'},
  {key: 'idea', label: 'اقتراح'},
  {key: 'content', label: 'ملاحظة على المحتوى'},
  {key: 'playback', label: 'تشغيل الفيديو'},
];

export default function Feedback() {
  const navigation = useNavigation<RootNavigation>();
  const route = useRoute<RootRoute<'Feedback'>>();
  const {i18n} = useTranslation();
  const [category, setCategory] = useState<ProductFeedbackCategory>('problem');
  const [message, setMessage] = useState('');
  const [attachment, setAttachment] = useState<
    FeedbackAttachment | undefined
  >();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);
  const canSubmit = useMemo(
    () => message.trim().length >= 10 && !busy,
    [busy, message],
  );

  const chooseScreenshot = async () => {
    const result = await launchImageLibrary({
      mediaType: 'photo',
      quality: 0.8 as PhotoQuality,
      selectionLimit: 1,
    });
    const asset = result.assets?.[0];
    if (!asset?.uri) return;
    if (Number(asset.fileSize || 0) > 4 * 1024 * 1024) {
      Alert.alert('الصورة كبيرة', 'اختر صورة أصغر من ٤ ميجابايت');
      return;
    }
    setAttachment({
      fileName: asset.fileName,
      type: asset.type,
      uri: asset.uri,
    });
  };

  const submit = async () => {
    if (!canSubmit) return;
    setBusy(true);
    setError('');
    try {
      await submitProductFeedback({
        attachment,
        category,
        context: {
          locale: i18n.resolvedLanguage || i18n.language || 'ar',
          sourceScreen: route.params?.sourceScreen || 'feedback',
        },
        message,
      });
      setSent(true);
    } catch {
      setError(
        'لم تصل الرسالة\nتحقق من الاتصال ثم حاول مرة أخرى\nنصك محفوظ',
      );
    } finally {
      setBusy(false);
    }
  };

  if (sent) {
    return (
      <Container noPadding>
        <Content noPadding>
          <ResponsiveFrame>
            <HeaderWithBack title="إرسال ملاحظة" />
            <StatusView
              actionLabel="العودة"
              description="وصلتنا رسالتك\nسنتواصل معك عند الحاجة"
              onAction={() => navigation.goBack()}
              title="شكرًا لملاحظتك"
            />
          </ResponsiveFrame>
        </Content>
      </Container>
    );
  }

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame style={styles.frame}>
          <HeaderWithBack title="إرسال ملاحظة" />
          <SectionHeading
            eyebrow="من داخل التطبيق"
            style={styles.heading}
            title="ماذا حدث"
          />
          <Text style={styles.intro}>
            اكتب المشكلة أو الاقتراح
            {'\n'}سنرفق إصدار التطبيق ونوع الجهاز تلقائيًا
          </Text>

          <View style={styles.categories}>
            {CATEGORIES.map(item => {
              const selected = item.key === category;
              return (
                <Pressable
                  accessibilityRole="button"
                  accessibilityState={{selected}}
                  key={item.key}
                  onPress={() => setCategory(item.key)}
                  style={({pressed}) => [
                    styles.category,
                    selected && styles.categorySelected,
                    pressed && styles.pressed,
                  ]}>
                  <Text
                    style={[
                      styles.categoryText,
                      selected && styles.categoryTextSelected,
                    ]}>
                    {item.label}
                  </Text>
                </Pressable>
              );
            })}
          </View>

          <PremiumCard style={styles.form}>
            <Text style={styles.label}>اكتب التفاصيل</Text>
            <TextInput
              multiline
              maxLength={1600}
              onChangeText={setMessage}
              placeholder="أين كنت وما الذي ظهر لك"
              placeholderTextColor={Palette.textFaint}
              selectionColor={Palette.primary}
              style={styles.input}
              textAlignVertical="top"
              value={message}
            />
            <Text style={styles.counter}>{message.length} / ١٦٠٠</Text>

            {attachment ? (
              <View style={styles.attachmentRow}>
                <Image
                  source={{uri: attachment.uri}}
                  style={styles.attachmentImage}
                />
                <View style={styles.attachmentCopy}>
                  <Text style={styles.attachmentTitle}>
                    الصورة جاهزة للإرسال
                  </Text>
                  <Pressable
                    accessibilityLabel="حذف الصورة المرفقة"
                    accessibilityRole="button"
                    hitSlop={8}
                    onPress={() => setAttachment(undefined)}>
                    <Text style={styles.removeAttachment}>حذف الصورة</Text>
                  </Pressable>
                </View>
              </View>
            ) : (
              <Pressable
                accessibilityRole="button"
                onPress={() => void chooseScreenshot()}
                style={({pressed}) => [
                  styles.attachmentButton,
                  pressed && styles.pressed,
                ]}>
                <Text style={styles.attachmentButtonText}>
                  أضف صورة إذا كانت توضح المشكلة
                </Text>
              </Pressable>
            )}
          </PremiumCard>

          <DebugBundleShareCard />

          {!!error && (
            <View>
              <Text accessibilityRole="alert" style={styles.error}>
                {error}
              </Text>
              <Pressable
                accessibilityRole="button"
                onPress={() =>
                  void openSupportWhatsApp(
                    `مرحبًا فريق ركن\n\n${message.trim()}`,
                  ).catch(() => undefined)
                }
                style={({pressed}) => [
                  styles.supportFallback,
                  pressed && styles.pressed,
                ]}>
                <Text style={styles.supportFallbackText}>
                  إرسالها للدعم على واتساب
                </Text>
              </Pressable>
            </View>
          )}
          <Pressable
            accessibilityRole="button"
            accessibilityState={{disabled: !canSubmit}}
            disabled={!canSubmit}
            onPress={() => void submit()}
            style={({pressed}) => [
              styles.submit,
              !canSubmit && styles.submitDisabled,
              pressed && styles.pressed,
            ]}>
            <Text style={styles.submitText}>
              {busy ? 'جارٍ الإرسال…' : 'إرسال'}
            </Text>
          </Pressable>
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  frame: {paddingBottom: Spacing.section},
  heading: {marginTop: Spacing.sm},
  intro: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  categories: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    gap: Spacing.xs,
    marginTop: Spacing.lg,
  },
  category: {
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
    borderRadius: Radius.pill,
    borderWidth: 1,
    borderColor: Palette.line,
    backgroundColor: Palette.surface,
  },
  categorySelected: {
    borderColor: Palette.primary,
    backgroundColor: Palette.primarySoft,
  },
  categoryText: {...Type.caption, color: Palette.textMuted},
  categoryTextSelected: {color: '#9ABFFF'},
  form: {padding: Spacing.lg, marginTop: Spacing.md},
  label: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  input: {
    ...Type.body,
    ...textDirection,
    color: Palette.text,
    minHeight: 164,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.line,
    backgroundColor: Palette.canvasSoft,
    padding: Spacing.md,
    marginTop: Spacing.sm,
  },
  counter: {
    ...Type.caption,
    color: Palette.textFaint,
    marginTop: Spacing.xs,
    textAlign: 'left',
  },
  attachmentButton: {
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.md,
    backgroundColor: Palette.primarySoft,
    marginTop: Spacing.md,
    paddingHorizontal: Spacing.md,
  },
  attachmentButtonText: {...Type.bodyStrong, color: '#9ABFFF'},
  attachmentRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: Spacing.md,
    marginTop: Spacing.md,
  },
  attachmentImage: {
    width: 74,
    height: 74,
    borderRadius: Radius.md,
    resizeMode: 'cover',
  },
  attachmentCopy: {flex: 1, alignItems: 'flex-start'},
  attachmentTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  removeAttachment: {
    ...Type.caption,
    color: Palette.danger,
    marginTop: Spacing.xxs,
  },
  error: {
    ...Type.caption,
    ...textDirection,
    color: Palette.danger,
    marginTop: Spacing.md,
  },
  supportFallback: {
    alignSelf: 'flex-start',
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    marginTop: Spacing.xs,
  },
  supportFallbackText: {...Type.bodyStrong, color: '#9ABFFF'},
  submit: {
    minHeight: 56,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
    marginTop: Spacing.lg,
  },
  submitDisabled: {opacity: 0.45},
  submitText: {...Type.button, color: Palette.text},
  pressed: {opacity: 0.75},
});
