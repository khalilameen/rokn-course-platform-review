import React from 'react';
import {useNavigation} from '@react-navigation/native';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  useWindowDimensions,
  View,
} from 'react-native';
import Svg, {Path} from 'react-native-svg';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {rtlRowStyle, textDirection} from '../../constants/designSystem';
import {formatArabicNumber} from '../../constants/arabicFormatting';
import {Fonts} from '../../constants/styleConstants';
import {useCourseChat} from './courseChat/useCourseChat';
import type {CourseLearningData, CourseReel} from './types';

interface CourseChatOverlayProps {
  visible: boolean;
  course: CourseLearningData;
  reel?: CourseReel;
  onClose: () => void;
}

type CourseChatNavigation = {
  navigate: (screen: 'Wallet' | 'PrivacyPolicy') => void;
};

const SendIcon = () => (
  <Svg width={21} height={21} viewBox="0 0 24 24">
    <Path
      d="m20 4-8.1 16-2.3-6.5L4 10.1 20 4Z"
      fill="none"
      stroke="#fff"
      strokeWidth={1.9}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
    <Path
      d="m9.6 13.5 4.3-4.2"
      fill="none"
      stroke="#fff"
      strokeWidth={1.9}
      strokeLinecap="round"
    />
  </Svg>
);

const CourseChatOverlay = ({
  visible,
  course,
  reel,
  onClose,
}: CourseChatOverlayProps) => {
  const insets = useSafeAreaInsets();
  const {height: windowHeight, fontScale} = useWindowDimensions();
  const navigation = useNavigation<CourseChatNavigation>();
  const {
    acceptDisclosure,
    assistantIncluded,
    confirmUpgrade,
    disclosureAccepted,
    input,
    loadUpgradeQuote,
    messages,
    planLimitReached,
    scholarshipAccess,
    scrollRef,
    send,
    sending,
    setInput,
    upgradeError,
    upgradeLoading,
    upgradeQuote,
  } = useCourseChat({
    visible,
    course,
    reel,
    onOpenWallet: () => {
      onClose();
      navigation.navigate('Wallet');
    },
  });

  return (
    <Modal
      visible={visible}
      transparent
      animationType="slide"
      presentationStyle="overFullScreen"
      statusBarTranslucent
      onRequestClose={onClose}>
      <KeyboardAvoidingView
        style={styles.modal}
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="إغلاق الشات"
          style={styles.backdrop}
          onPress={onClose}
        />
        <View
          style={[
            styles.sheet,
            {
              height: fontScale > 1.25 ? '88%' : '78%',
              maxHeight: Math.max(380, windowHeight - insets.top - 8),
            },
          ]}>
          <View style={styles.handle} />
          <View style={styles.header}>
            <View style={styles.headerCopy}>
              <Text style={styles.title}>Rokn AI</Text>
            </View>
            <View style={styles.playingPill}>
              <View style={styles.liveDot} />
              <Text
                adjustsFontSizeToFit
                minimumFontScale={0.8}
                numberOfLines={1}
                style={styles.playingText}>
                الفيديو مستمر
              </Text>
            </View>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="إغلاق"
              hitSlop={10}
              style={styles.closeButton}
              onPress={onClose}>
              <Text style={styles.closeText} maxFontSizeMultiplier={1.1}>
                ×
              </Text>
            </Pressable>
          </View>

          {!assistantIncluded ? (
            <ScrollView
              contentContainerStyle={styles.entitlementGate}
              keyboardShouldPersistTaps="handled"
              showsVerticalScrollIndicator={false}>
              <Text style={styles.entitlementTitle}>
                {planLimitReached
                  ? 'استخدمت مساحة الأسئلة في اختيارك الحالي'
                  : scholarshipAccess
                  ? 'كل الكورس مفتوح لك بالمنحة'
                  : 'Rokn AI غير متاح مع هذا الوصول'}
              </Text>
              <Text style={styles.entitlementText}>
                {planLimitReached
                  ? 'مكانك وإجاباتك الحالية كما هما. لو محتاج تسأل أكتر تقدر تنتقل للاختيار التالي وتدفع فرق السعر فقط.'
                  : scholarshipAccess
                  ? 'اتفرج وطبّق كل المشاريع من أول الكورس لآخره مجانًا. لو احتجت Rokn AI أو الشهادة تقدر تضيف اختيارًا مدفوعًا من غير ما تخسر منحتك.'
                  : 'محتوى الكورس متاح لك كالمعتاد. لو محتاج Rokn AI شوف خيارات الوصول المتاحة للكورس.'}
              </Text>
              {upgradeQuote && (
                <View style={styles.upgradeCard}>
                  <View style={styles.upgradeRow}>
                    <Text style={styles.upgradeLabel}>
                      {upgradeQuote.targetPlanName || 'إضافة Rokn AI'}
                    </Text>
                    <Text style={styles.upgradeValue}>
                      {formatArabicNumber(upgradeQuote.price)} رصيد
                    </Text>
                  </View>
                  <View style={styles.upgradeDivider} />
                  <View style={styles.upgradeRow}>
                    <Text style={styles.upgradeHint}>المتاح للترقية</Text>
                    <Text style={styles.upgradeHintValue}>
                      {formatArabicNumber(upgradeQuote.spendableBalance)}
                    </Text>
                  </View>
                  {upgradeQuote.deficit > 0 && (
                    <Text style={styles.upgradeDeficit}>
                      ناقصك {formatArabicNumber(upgradeQuote.deficit)} رصيد
                    </Text>
                  )}
                  {!!upgradeQuote.targetMessageLimit && (
                    <Text style={styles.upgradeHint}>
                      حتى {formatArabicNumber(upgradeQuote.targetMessageLimit)}{' '}
                      رسالة في هذا الكورس
                    </Text>
                  )}
                </View>
              )}
              {!!upgradeError && (
                <Text accessibilityRole="alert" style={styles.upgradeError}>
                  {upgradeError}
                </Text>
              )}
              <Pressable
                accessibilityRole="button"
                disabled={upgradeLoading}
                onPress={() =>
                  void (upgradeQuote ? confirmUpgrade() : loadUpgradeQuote())
                }
                style={({pressed}) => [
                  styles.entitlementButton,
                  pressed && styles.entitlementButtonPressed,
                  upgradeLoading && styles.entitlementButtonDisabled,
                ]}>
                {upgradeLoading ? (
                  <ActivityIndicator color="#FFFFFF" size="small" />
                ) : (
                  <Text style={styles.entitlementButtonText}>
                    {upgradeQuote
                      ? upgradeQuote.deficit > 0
                        ? 'اشحن الرصيد الناقص'
                        : `انتقل إلى ${
                            upgradeQuote.targetPlanName || 'الاختيار التالي'
                          }`
                      : 'شوف اختيارات Rokn AI'}
                  </Text>
                )}
              </Pressable>
            </ScrollView>
          ) : disclosureAccepted === false ? (
            <ScrollView
              contentContainerStyle={styles.disclosure}
              keyboardShouldPersistTaps="handled"
              showsVerticalScrollIndicator={false}>
              <Text style={styles.disclosureTitle}>قبل أول سؤال</Text>
              <Text style={styles.disclosureText}>
                لإنتاج الرد نرسل نص سؤالك وسياقًا مختصرًا عن الكورس إلى
                OpenRouter ومزوّد النموذج. لا نضيف اسمك أو بريدك، ولا يحتفظ ركن
                بسجل دائم للشات.
              </Text>
              <Text style={styles.disclosureHint}>
                لا تكتب كلمة مرور أو بيانات مالية أو معلومات شخصية حساسة.
              </Text>
              <Pressable
                accessibilityRole="button"
                onPress={() => void acceptDisclosure()}
                style={styles.disclosureButton}>
                <Text style={styles.disclosureButtonText}>
                  فهمت، ابدأ الشات
                </Text>
              </Pressable>
              <Pressable
                accessibilityRole="link"
                onPress={() => {
                  onClose();
                  navigation.navigate('PrivacyPolicy');
                }}
                style={styles.disclosureLink}>
                <Text style={styles.disclosureLinkText}>
                  اقرأ سياسة الخصوصية
                </Text>
              </Pressable>
            </ScrollView>
          ) : disclosureAccepted === true ? (
            <>
              <ScrollView
                ref={scrollRef}
                style={styles.messages}
                contentContainerStyle={styles.messagesContent}
                keyboardShouldPersistTaps="handled"
                showsVerticalScrollIndicator={false}
                onContentSizeChange={() =>
                  scrollRef.current?.scrollToEnd({animated: true})
                }>
                {messages.map(message => (
                  <View
                    key={message.id}
                    style={[
                      styles.bubble,
                      message.role === 'user'
                        ? styles.userBubble
                        : styles.assistantBubble,
                    ]}>
                    {message.pending ? (
                      <ActivityIndicator color="#FFFFFF" size="small" />
                    ) : (
                      <Text style={styles.bubbleText}>{message.text}</Text>
                    )}
                  </View>
                ))}
              </ScrollView>

              <View
                style={[
                  styles.composer,
                  {paddingBottom: Math.max(10, insets.bottom + 6)},
                ]}>
                <TextInput
                  value={input}
                  onChangeText={setInput}
                  placeholder="اكتب سؤالك"
                  placeholderTextColor="rgba(255,255,255,.42)"
                  multiline
                  maxLength={1200}
                  style={styles.input}
                  onSubmitEditing={send}
                  blurOnSubmit={false}
                />
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="إرسال"
                  disabled={!input.trim() || sending}
                  style={[
                    styles.sendButton,
                    (!input.trim() || sending) && styles.sendButtonDisabled,
                  ]}
                  onPress={send}>
                  <SendIcon />
                </Pressable>
              </View>
            </>
          ) : (
            <View style={styles.disclosureLoading}>
              <ActivityIndicator color="#FFFFFF" />
            </View>
          )}
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
};

export default CourseChatOverlay;

const styles = StyleSheet.create({
  modal: {
    flex: 1,
    justifyContent: 'flex-end',
  },
  backdrop: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: 'rgba(0,0,0,.34)',
  },
  sheet: {
    width: '100%',
    maxWidth: 680,
    alignSelf: 'center',
    backgroundColor: '#0D121A',
    borderTopLeftRadius: 26,
    borderTopRightRadius: 26,
    borderWidth: 1,
    borderBottomWidth: 0,
    borderColor: 'rgba(255,255,255,.1)',
    overflow: 'hidden',
  },
  handle: {
    width: 42,
    height: 4,
    borderRadius: 2,
    backgroundColor: 'rgba(255,255,255,.26)',
    alignSelf: 'center',
    marginTop: 9,
  },
  header: {
    minHeight: 72,
    paddingHorizontal: 16,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
    borderBottomWidth: 1,
    borderBottomColor: 'rgba(255,255,255,.08)',
  },
  headerCopy: {
    flex: 1,
    minWidth: 0,
  },
  title: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 16,
  },
  playingPill: {
    minHeight: 28,
    maxWidth: '44%',
    paddingHorizontal: 9,
    borderRadius: 14,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 6,
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  liveDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    backgroundColor: '#52D795',
  },
  playingText: {
    color: 'rgba(255,255,255,.72)',
    fontFamily: Fonts.medium,
    fontSize: 10,
    flexShrink: 1,
  },
  closeButton: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  closeText: {
    color: '#FFFFFF',
    fontSize: 25,
    lineHeight: 27,
    fontFamily: Fonts.light,
  },
  messages: {
    flex: 1,
  },
  messagesContent: {
    paddingHorizontal: 14,
    paddingVertical: 18,
    gap: 10,
  },
  bubble: {
    maxWidth: '86%',
    minHeight: 42,
    paddingHorizontal: 14,
    paddingVertical: 11,
    borderRadius: 17,
    justifyContent: 'center',
  },
  userBubble: {
    alignSelf: 'flex-end',
    backgroundColor: '#236FE8',
    borderBottomRightRadius: 5,
  },
  assistantBubble: {
    alignSelf: 'flex-start',
    backgroundColor: '#192230',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.07)',
    borderBottomLeftRadius: 5,
  },
  bubbleText: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 14,
    lineHeight: 22,
  },
  disclosureLoading: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
  },
  entitlementGate: {
    flexGrow: 1,
    justifyContent: 'center',
    paddingHorizontal: 24,
    paddingBottom: 30,
  },
  entitlementTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 22,
    lineHeight: 32,
  },
  entitlementText: {
    ...textDirection,
    color: 'rgba(255,255,255,.72)',
    fontFamily: Fonts.regular,
    fontSize: 15,
    lineHeight: 26,
    marginTop: 12,
  },
  entitlementButton: {
    minHeight: 52,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 15,
    backgroundColor: '#2C69DB',
    marginTop: 24,
  },
  entitlementButtonPressed: {
    opacity: 0.82,
  },
  entitlementButtonDisabled: {
    opacity: 0.62,
  },
  entitlementButtonText: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 16,
  },
  upgradeCard: {
    marginTop: 20,
    padding: 14,
    borderRadius: 15,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.1)',
    backgroundColor: 'rgba(255,255,255,.055)',
  },
  upgradeRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
  },
  upgradeLabel: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 14,
  },
  upgradeValue: {
    color: '#E9C86D',
    fontFamily: Fonts.bold,
    fontSize: 14,
  },
  upgradeDivider: {
    height: StyleSheet.hairlineWidth,
    backgroundColor: 'rgba(255,255,255,.09)',
    marginVertical: 11,
  },
  upgradeHint: {
    ...textDirection,
    color: 'rgba(255,255,255,.62)',
    fontFamily: Fonts.regular,
    fontSize: 12,
  },
  upgradeHintValue: {
    color: 'rgba(255,255,255,.82)',
    fontFamily: Fonts.semiBold,
    fontSize: 12,
  },
  upgradeDeficit: {
    ...textDirection,
    color: '#FFB1B1',
    fontFamily: Fonts.medium,
    fontSize: 12,
    marginTop: 10,
  },
  upgradeError: {
    ...textDirection,
    color: '#FFB1B1',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    marginTop: 12,
  },
  disclosure: {
    flexGrow: 1,
    justifyContent: 'center',
    paddingHorizontal: 24,
    paddingBottom: 28,
  },
  disclosureTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 22,
    lineHeight: 32,
  },
  disclosureText: {
    ...textDirection,
    color: 'rgba(255,255,255,.76)',
    fontFamily: Fonts.regular,
    fontSize: 15,
    lineHeight: 26,
    marginTop: 12,
  },
  disclosureHint: {
    ...textDirection,
    color: 'rgba(255,255,255,.58)',
    fontFamily: Fonts.regular,
    fontSize: 13,
    lineHeight: 22,
    marginTop: 12,
  },
  disclosureButton: {
    minHeight: 52,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 15,
    backgroundColor: '#2C69DB',
    marginTop: 24,
  },
  disclosureButtonText: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 16,
  },
  disclosureLink: {
    minHeight: 44,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 4,
  },
  disclosureLinkText: {
    color: '#8EB8FF',
    fontFamily: Fonts.semiBold,
    fontSize: 14,
  },
  composer: {
    minHeight: 72,
    padding: 10,
    paddingBottom: Platform.OS === 'ios' ? 18 : 10,
    ...rtlRowStyle,
    alignItems: 'flex-end',
    gap: 8,
    borderTopWidth: 1,
    borderTopColor: 'rgba(255,255,255,.08)',
    backgroundColor: '#0B1017',
  },
  input: {
    ...textDirection,
    flex: 1,
    minHeight: 48,
    maxHeight: 110,
    borderRadius: 18,
    paddingHorizontal: 14,
    paddingVertical: 11,
    color: '#FFFFFF',
    backgroundColor: '#161E29',
    fontFamily: Fonts.regular,
    fontSize: 14,
    lineHeight: 21,
  },
  sendButton: {
    width: 48,
    height: 48,
    borderRadius: 17,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
  },
  sendButtonDisabled: {
    opacity: 0.38,
  },
});
