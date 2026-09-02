import React, {useEffect, useRef, useState} from 'react';
import {useNavigation} from '@react-navigation/native';
import Clipboard from '@react-native-clipboard/clipboard';
import {
  ActivityIndicator,
  Alert,
  Animated,
  KeyboardAvoidingView,
  Image,
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
import * as DocumentPicker from 'expo-document-picker';
import Svg, {Path} from 'react-native-svg';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {
  Palette,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {formatArabicNumber} from '../../constants/arabicFormatting';
import {Fonts} from '../../constants/styleConstants';
import {useCourseChat} from './courseChat/useCourseChat';
import type {CourseLearningData, CourseReel} from './types';
import type {AssistantPresence} from './courseChat/useCourseChat';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import {cleanUnicodeText, truncateGraphemes} from '../../utils/unicodeText';
import {secureRandomUuid} from '../../utils/secureRandom';
import {
  cacheLearnerDraftFile,
  removeLearnerDraftFile,
} from '../../services/learnerDraftFiles';
import {showMediaPickerFailure} from '../../services/mediaPickerErrors';
import {openCourseAssistantAttachment} from './courseLearningApi';

interface CourseChatOverlayProps {
  visible: boolean;
  course: CourseLearningData;
  reel?: CourseReel;
  onClose: () => void;
}

type CourseChatNavigation = {
  navigate: (
    screen: 'Wallet',
    params?: {returnTo?: import('../../navigation/types').LoginReturnTo},
  ) => void;
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

const WorkingIndicator = () => {
  const dots = useRef([
    new Animated.Value(0.32),
    new Animated.Value(0.32),
    new Animated.Value(0.32),
  ]).current;
  const reduceMotion = useReducedMotion();

  useEffect(() => {
    if (reduceMotion) return undefined;
    const animation = Animated.loop(
      Animated.stagger(
        140,
        dots.map(dot =>
          Animated.sequence([
            Animated.timing(dot, {
              toValue: 1,
              duration: 280,
              useNativeDriver: true,
            }),
            Animated.timing(dot, {
              toValue: 0.32,
              duration: 280,
              useNativeDriver: true,
            }),
          ]),
        ),
      ),
    );
    animation.start();
    return () => animation.stop();
  }, [dots, reduceMotion]);

  return (
    <View
      accessible
      accessibilityLiveRegion="polite"
      accessibilityRole="text"
      accessibilityLabel="ركن يكتب الآن"
      style={styles.workingIndicator}>
      {dots.map((opacity, index) => (
        <Animated.View
          key={index}
          style={[styles.workingDot, {opacity: reduceMotion ? 0.72 : opacity}]}
        />
      ))}
    </View>
  );
};

const presenceLabel = (presence: AssistantPresence): string =>
  presence === 'working' ? 'يكتب الآن' : 'متصل الآن';

const CourseChatOverlay = ({
  visible,
  course,
  reel,
  onClose,
}: CourseChatOverlayProps) => {
  const insets = useSafeAreaInsets();
  const reducedMotion = useReducedMotion();
  const {height: windowHeight, fontScale} = useWindowDimensions();
  const navigation = useNavigation<CourseChatNavigation>();
  const [copiedMessageId, setCopiedMessageId] = useState<string>();
  const copiedTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const attachmentPickerFlightRef = useRef(false);
  const attachmentPickerGenerationRef = useRef(0);
  const activeAttachmentCourseRef = useRef(String(course.id));
  const upgradeAutoLoadCourseRef = useRef<string | undefined>(undefined);
  activeAttachmentCourseRef.current = String(course.id);
  const {
    assistantPresence,
    assistantIncluded,
    attachments,
    confirmUpgrade,
    input,
    loadUpgradeQuote,
    messages,
    planLimitReached,
    retry,
    scholarshipAccess,
    scrollRef,
    send,
    sending,
    stop,
    setInput,
    setAttachments,
    upgradeError,
    upgradeLoading,
    upgradeQuote,
  } = useCourseChat({
    visible,
    course,
    reel,
    onOpenWallet: () => {
      navigation.navigate('Wallet', {
        returnTo: {
          name: 'Reels',
          params: {
            courseId: String(course.id),
            reelId: reel?.id ? String(reel.id) : undefined,
            lessonId: reel?.lessonId ? String(reel.lessonId) : undefined,
            openCourseChatUpgrade: true,
          },
        },
      });
    },
  });
  const hasSendableInput =
    cleanUnicodeText(input).length > 0 || attachments.length > 0;
  const attachmentLimit = Math.max(0, course.chatAttachmentMaxFiles || 0);

  useEffect(() => {
    attachmentPickerGenerationRef.current += 1;
    attachmentPickerFlightRef.current = false;
    setCopiedMessageId(undefined);
    if (copiedTimerRef.current) clearTimeout(copiedTimerRef.current);
    return () => {
      attachmentPickerGenerationRef.current += 1;
    };
  }, [course.id]);

  useEffect(() => {
    if (upgradeAutoLoadCourseRef.current !== String(course.id)) {
      upgradeAutoLoadCourseRef.current = undefined;
    }
    if (
      visible &&
      !assistantIncluded &&
      !upgradeQuote &&
      !upgradeLoading &&
      !upgradeError &&
      upgradeAutoLoadCourseRef.current !== String(course.id)
    ) {
      upgradeAutoLoadCourseRef.current = String(course.id);
      void loadUpgradeQuote();
    }
  }, [
    assistantIncluded,
    course.id,
    loadUpgradeQuote,
    upgradeError,
    upgradeLoading,
    upgradeQuote,
    visible,
  ]);

  const pickAttachments = async () => {
    if (
      !course.chatAttachmentsEnabled ||
      attachments.length >= attachmentLimit ||
      attachmentPickerFlightRef.current
    )
      return;
    const pickerCourseId = String(course.id);
    const pickerGeneration = attachmentPickerGenerationRef.current;
    const ownsPicker = () =>
      activeAttachmentCourseRef.current === pickerCourseId &&
      attachmentPickerGenerationRef.current === pickerGeneration;
    attachmentPickerFlightRef.current = true;
    const selected: import('./types').ChatAttachmentDraft[] = [];
    try {
      const result = await DocumentPicker.getDocumentAsync({
        type: [
          'image/jpeg',
          'image/png',
          'image/webp',
          'application/pdf',
          'text/plain',
          'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
        multiple: true,
        copyToCacheDirectory: true,
      });
      if (result.canceled) return;
      if (!ownsPicker()) return;
      const remaining = Math.max(0, attachmentLimit - attachments.length);
      for (const asset of result.assets.slice(0, remaining)) {
        const cached = await cacheLearnerDraftFile(
          'course_chat',
          {
            uri: asset.uri,
            fileName: asset.name,
            type: asset.mimeType || 'application/octet-stream',
            size: asset.size,
          },
          8 * 1024 * 1024,
        );
        selected.push({
          uri: cached.uri,
          name: cached.fileName || asset.name,
          type: cached.type || asset.mimeType || 'application/octet-stream',
          size: cached.size,
          uploadId: secureRandomUuid(),
        });
        if (!ownsPicker()) {
          await Promise.all(selected.map(removeLearnerDraftFile));
          return;
        }
      }
      if (!ownsPicker()) {
        await Promise.all(selected.map(removeLearnerDraftFile));
        return;
      }
      setAttachments(current => {
        const combined = [...current, ...selected];
        const kept = combined.slice(0, attachmentLimit);
        const keptIds = new Set(kept.map(file => file.uploadId));
        void Promise.all(
          selected
            .filter(file => !keptIds.has(file.uploadId))
            .map(removeLearnerDraftFile),
        );
        return kept;
      });
    } catch (error: unknown) {
      await Promise.all(selected.map(removeLearnerDraftFile));
      if (!ownsPicker()) return;
      showMediaPickerFailure(
        error instanceof Error && error.message === 'LEARNER_DRAFT_STORAGE_FULL'
          ? error.message
          : 'document_picker_failed',
      );
    } finally {
      if (ownsPicker()) attachmentPickerFlightRef.current = false;
    }
  };

  useEffect(
    () => () => {
      if (copiedTimerRef.current) clearTimeout(copiedTimerRef.current);
    },
    [],
  );

  const copyMessage = (messageId: string, text: string) => {
    Clipboard.setString(text);
    setCopiedMessageId(messageId);
    if (copiedTimerRef.current) clearTimeout(copiedTimerRef.current);
    copiedTimerRef.current = setTimeout(() => {
      setCopiedMessageId(current =>
        current === messageId ? undefined : current,
      );
    }, 1400);
  };

  return (
    <Modal
      visible={visible}
      transparent
      animationType={reducedMotion ? 'none' : 'slide'}
      presentationStyle="overFullScreen"
      hardwareAccelerated={Platform.OS === 'android'}
      statusBarTranslucent
      onRequestClose={onClose}>
      <KeyboardAvoidingView
        style={styles.modal}
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
        <Pressable
          accessible={false}
          importantForAccessibility="no-hide-descendants"
          style={styles.backdrop}
          onPress={onClose}
        />
        <View
          accessibilityViewIsModal
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
              <View style={styles.presenceRow}>
                <View
                  style={[
                    styles.presenceDot,
                    assistantPresence === 'working' &&
                      styles.presenceDotConnecting,
                  ]}
                />
                <Text
                  accessibilityLiveRegion="polite"
                  style={styles.presenceText}>
                  {presenceLabel(assistantPresence)}
                </Text>
              </View>
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
                  ? 'الكورس كامل متاح بمنحتك'
                  : 'Rokn AI غير متاح مع هذا الوصول'}
              </Text>
              <Text style={styles.entitlementText}>
                {planLimitReached
                  ? 'تقدمك وإجاباتك محفوظة\nانتقل إلى الفئة التالية وادفع فرق السعر فقط'
                  : scholarshipAccess
                  ? 'محتوى الكورس متاح لك كاملًا\nيمكنك إضافة Rokn AI أو الشهادة دون أن تخسر منحتك'
                  : 'محتوى الكورس متاح لك\nراجع فئات الكورس لإضافة Rokn AI'}
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
                      ينقصك {formatArabicNumber(upgradeQuote.deficit)} رصيد
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
                      : 'راجع خيارات Rokn AI'}
                  </Text>
                )}
              </Pressable>
            </ScrollView>
          ) : (
            <>
              <ScrollView
                accessibilityLabel="محادثة Rokn AI"
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
                      assistantPresence === 'working' ? (
                        <WorkingIndicator />
                      ) : (
                        <ActivityIndicator color="#FFFFFF" size="small" />
                      )
                    ) : (
                      <>
                        <Text selectable={false} style={styles.bubbleText}>
                          {message.text}
                        </Text>
                        {message.attachments?.map(file => (
                          <Pressable
                            key={file.serverId || file.uploadId}
                            disabled={!file.serverId && !file.downloadUrl}
                            onPress={() =>
                              void openCourseAssistantAttachment(file).catch(
                                () =>
                                  Alert.alert(
                                    'تعذّر فتح الملف',
                                    'حاول مرة أخرى',
                                  ),
                              )
                            }>
                            <Text
                              numberOfLines={1}
                              style={styles.messageAttachment}>
                              {file.name}
                            </Text>
                          </Pressable>
                        ))}
                        {!!message.text && (
                          <Pressable
                            accessibilityRole="button"
                            accessibilityLabel="نسخ الرسالة"
                            hitSlop={6}
                            onPress={() =>
                              copyMessage(message.id, message.text)
                            }>
                            <Text style={styles.copyText}>
                              {copiedMessageId === message.id ? 'تم' : 'نسخ'}
                            </Text>
                          </Pressable>
                        )}
                        {message.role === 'assistant' &&
                          message.deliveryStatus === 'failed' &&
                          message.clientRequestId &&
                          message.errorCode !== 'chat_daily_limit_reached' && (
                            <Pressable
                              accessibilityRole="button"
                              disabled={sending}
                              onPress={() => retry(message.clientRequestId!)}>
                              <Text style={styles.retryText}>
                                {[
                                  'chat_answer_in_progress',
                                  'client_timeout',
                                  'interrupted_turn',
                                ].includes(message.errorCode || '')
                                  ? 'استعد الرد'
                                  : 'حاول مرة أخرى'}
                              </Text>
                            </Pressable>
                          )}
                      </>
                    )}
                  </View>
                ))}
              </ScrollView>

              {attachments.length > 0 && (
                <ScrollView
                  horizontal
                  style={styles.attachmentStrip}
                  showsHorizontalScrollIndicator={false}>
                  {attachments.map(file => (
                    <View key={file.uploadId} style={styles.attachmentChip}>
                      {file.type.startsWith('image/') && file.uri !== '' && (
                        <Image
                          source={{uri: file.uri}}
                          style={styles.attachmentPreview}
                        />
                      )}
                      <Text numberOfLines={1} style={styles.attachmentName}>
                        {file.name}
                      </Text>
                      <Pressable
                        accessibilityRole="button"
                        accessibilityLabel={`حذف ${file.name}`}
                        onPress={() => {
                          setAttachments(current =>
                            current.filter(
                              item => item.uploadId !== file.uploadId,
                            ),
                          );
                          void removeLearnerDraftFile(file);
                        }}>
                        <Text style={styles.attachmentRemove}>×</Text>
                      </Pressable>
                    </View>
                  ))}
                </ScrollView>
              )}
              {sending && (
                <Pressable
                  accessibilityRole="button"
                  style={styles.stopButton}
                  onPress={() => void stop()}>
                  <Text style={styles.stopButtonText}>إيقاف</Text>
                </Pressable>
              )}
              <View
                style={[
                  styles.composer,
                  {paddingBottom: Math.max(10, insets.bottom + 6)},
                ]}>
                <TextInput
                  accessibilityLabel="اكتب سؤالك إلى Rokn AI"
                  value={input}
                  onChangeText={value =>
                    setInput(truncateGraphemes(value, 1600))
                  }
                  placeholder="اكتب سؤالك"
                  placeholderTextColor="rgba(255,255,255,.42)"
                  multiline
                  style={styles.input}
                  onSubmitEditing={() => send()}
                  blurOnSubmit={false}
                />
                {course.chatAttachmentsEnabled && attachmentLimit > 0 && (
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel="إضافة صورة أو ملف"
                    disabled={sending || attachments.length >= attachmentLimit}
                    style={styles.attachButton}
                    onPress={() => void pickAttachments()}>
                    <Text style={styles.attachButtonText}>＋</Text>
                  </Pressable>
                )}
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="إرسال"
                  accessibilityState={{
                    busy: sending,
                    disabled: !hasSendableInput || sending,
                  }}
                  disabled={!hasSendableInput || sending}
                  style={[
                    styles.sendButton,
                    (!hasSendableInput || sending) && styles.sendButtonDisabled,
                  ]}
                  onPress={send}>
                  <SendIcon />
                </Pressable>
              </View>
            </>
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
  presenceRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 5,
    marginTop: 2,
  },
  presenceDot: {
    width: 7,
    height: 7,
    borderRadius: 4,
    backgroundColor: Palette.success,
  },
  presenceDotConnecting: {
    backgroundColor: Palette.coin,
  },
  presenceText: {
    color: 'rgba(255,255,255,.62)',
    fontFamily: Fonts.medium,
    fontSize: 10,
  },
  closeButton: {
    width: 48,
    height: 48,
    borderRadius: 24,
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
  retryText: {
    color: '#9FC0FF',
    fontFamily: Fonts.bold,
    fontSize: 12,
    marginTop: 8,
  },
  copyText: {
    color: 'rgba(255,255,255,.58)',
    fontFamily: Fonts.medium,
    fontSize: 11,
    marginTop: 7,
  },
  messageAttachment: {
    color: 'rgba(255,255,255,.78)',
    fontFamily: Fonts.medium,
    fontSize: 11,
    marginTop: 5,
  },
  workingIndicator: {
    minWidth: 42,
    minHeight: 20,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 5,
  },
  workingDot: {
    width: 7,
    height: 7,
    borderRadius: 4,
    backgroundColor: 'rgba(255,255,255,.86)',
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
    backgroundColor: Palette.primary,
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
  attachmentStrip: {
    flexGrow: 0,
    maxHeight: 58,
    paddingHorizontal: 10,
    paddingTop: 8,
    backgroundColor: '#0B1017',
  },
  stopButton: {
    alignSelf: 'center',
    marginVertical: 6,
    paddingHorizontal: 16,
    paddingVertical: 7,
    borderRadius: 14,
    backgroundColor: '#192230',
  },
  stopButtonText: {color: '#FFFFFF', fontFamily: Fonts.medium, fontSize: 12},
  attachmentChip: {
    height: 44,
    maxWidth: 190,
    marginEnd: 8,
    paddingHorizontal: 8,
    borderRadius: 12,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 7,
    backgroundColor: '#192230',
  },
  attachmentPreview: {width: 30, height: 30, borderRadius: 7},
  attachmentName: {
    maxWidth: 110,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 12,
  },
  attachmentRemove: {color: '#FFFFFF', fontSize: 20, lineHeight: 22},
  attachButton: {
    width: 42,
    height: 48,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#192230',
  },
  attachButtonText: {color: '#FFFFFF', fontSize: 24, lineHeight: 26},
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
