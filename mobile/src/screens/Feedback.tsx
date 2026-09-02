import React, {useEffect, useMemo, useRef, useState} from 'react';
import {
  Alert,
  Image,
  Modal,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import {useRoute} from '@react-navigation/native';
import type {RootRoute} from '../navigation/types';
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
  clearProductFeedbackDraft,
  loadProductFeedbackCases,
  loadProductFeedbackDraft,
  loadProductFeedbackDraftConflicts,
  loadProductFeedbackReplyDraft,
  ProductFeedbackCase,
  ProductFeedbackArtifact,
  ProductFeedbackCategory,
  replyToProductFeedback,
  restoreProductFeedbackDraftConflict,
  saveProductFeedbackDraft,
  saveProductFeedbackReplyDraft,
  submitProductFeedback,
} from '../services/productFeedback';
import {
  getSupportWhatsAppUrl,
  openSupportWhatsApp,
} from '../services/supportWhatsApp';
import {toArabicDigits} from '../constants/arabicFormatting';
import {secureRandomUuid} from '../utils/secureRandom';
import {
  cacheLearnerDraftFile,
  removeLearnerDraftFile,
} from '../services/learnerDraftFiles';
import {useAppActiveState} from '../hooks/useAppActiveState';
import {showMediaPickerFailure} from '../services/mediaPickerErrors';

const CATEGORIES: Array<{key: ProductFeedbackCategory; label: string}> = [
  {key: 'problem', label: 'مشكلة'},
  {key: 'idea', label: 'اقتراح'},
  {key: 'content', label: 'ملاحظة على المحتوى'},
  {key: 'playback', label: 'تشغيل الفيديو'},
];

const CASE_STATUS: Record<string, string> = {
  received: 'وصل إلى الدعم',
  in_progress: 'قيد المراجعة',
  waiting_for_you: 'بانتظار ردك',
  resolved: 'تم الحل',
  closed: 'مغلق',
};

export default function Feedback() {
  const appActive = useAppActiveState();
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
  const [supportAvailable, setSupportAvailable] = useState(false);
  const [draftReady, setDraftReady] = useState(false);
  const [draftSaveError, setDraftSaveError] = useState(false);
  const [clientRequestId, setClientRequestId] = useState(secureRandomUuid);
  const [receiptId, setReceiptId] = useState('');
  const [receiptPublicId, setReceiptPublicId] = useState('');
  const [supportCases, setSupportCases] = useState<ProductFeedbackCase[]>([]);
  const [casesBusy, setCasesBusy] = useState(true);
  const [casesError, setCasesError] = useState('');
  const [selectedCaseId, setSelectedCaseId] = useState('');
  const [replyStateOwnerId, setReplyStateOwnerId] = useState('');
  const [replyMessage, setReplyMessage] = useState('');
  const [replyRequestId, setReplyRequestId] = useState(secureRandomUuid);
  const [replyAttachment, setReplyAttachment] = useState<FeedbackAttachment>();
  const [replyPendingCaseIds, setReplyPendingCaseIds] = useState<Set<string>>(
    new Set(),
  );
  const [replyError, setReplyError] = useState('');
  const [previewArtifact, setPreviewArtifact] =
    useState<ProductFeedbackArtifact>();
  const mountedRef = useRef(true);
  const submitFlightRef = useRef(false);
  const replyFlightsRef = useRef(new Map<string, symbol>());
  const replyGenerationRef = useRef(0);
  const replyDraftEpochRef = useRef(0);
  const casesGenerationRef = useRef(0);
  const pickerFlightRef = useRef(false);
  const draftSnapshotRef = useRef({
    attachment,
    category,
    clientRequestId,
    message,
    updatedAt: Date.now(),
  });
  draftSnapshotRef.current = {
    attachment,
    category,
    clientRequestId,
    message,
    updatedAt: Date.now(),
  };
  const canSubmit = useMemo(
    () => message.trim().length >= 10 && !busy,
    [busy, message],
  );
  const selectedCase = supportCases.find(
    item => item.publicId === selectedCaseId,
  );
  const replyBusy = selectedCaseId
    ? replyPendingCaseIds.has(selectedCaseId)
    : false;
  const requestedCaseId = route.params?.caseId?.trim().toUpperCase() || '';

  const reloadCases = async () => {
    const generation = ++casesGenerationRef.current;
    setCasesBusy(true);
    setCasesError('');
    try {
      const loaded = await loadProductFeedbackCases();
      if (!mountedRef.current || generation !== casesGenerationRef.current)
        return;
      setSupportCases(loaded);
      const targetCaseId = requestedCaseId || receiptPublicId;
      if (targetCaseId && loaded.some(item => item.publicId === targetCaseId)) {
        setSelectedCaseId(targetCaseId);
      }
    } catch {
      if (mountedRef.current && generation === casesGenerationRef.current)
        setCasesError('تعذّر تحديث الحالات الآن');
    } finally {
      if (mountedRef.current && generation === casesGenerationRef.current)
        setCasesBusy(false);
    }
  };

  useEffect(() => {
    void reloadCases();
    // The list is refreshed explicitly after every write.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [requestedCaseId]);

  useEffect(() => {
    let active = true;
    const generation = ++replyGenerationRef.current;
    setReplyStateOwnerId('');
    setReplyError('');
    setReplyMessage('');
    setReplyRequestId(secureRandomUuid());
    setReplyAttachment(undefined);
    if (!selectedCaseId)
      return () => {
        active = false;
      };
    void Promise.all([
      loadProductFeedbackReplyDraft(selectedCaseId),
      loadProductFeedbackDraftConflicts(),
    ])
      .then(([draft, conflicts]) => {
        if (!active || generation !== replyGenerationRef.current) return;
        if (draft) {
          setReplyMessage(draft.message);
          setReplyAttachment(draft.attachment);
          setReplyRequestId(draft.clientRequestId || secureRandomUuid());
        }
        setReplyStateOwnerId(selectedCaseId);
        const alternative = conflicts.find(
          conflict => conflict.type === 'reply' && conflict.publicId === selectedCaseId,
        );
        if (alternative) {
          Alert.alert('توجد مسودة رد أخرى', 'يمكنك استعادة الرد الذي كتبته قبل تسجيل الدخول', [
            {text: 'الاحتفاظ بالحالي', style: 'cancel'},
            {
              text: 'استعادة الآخر',
              onPress: () => {
                void restoreProductFeedbackDraftConflict(alternative.id).then(async restored => {
                  if (!restored || !mountedRef.current) return;
                  const value = await loadProductFeedbackReplyDraft(selectedCaseId);
                  if (!value || !mountedRef.current) return;
                  setReplyMessage(value.message);
                  setReplyAttachment(value.attachment);
                  setReplyRequestId(value.clientRequestId || secureRandomUuid());
                });
              },
            },
          ]);
        }
      })
      .catch(() => {
        if (active && generation === replyGenerationRef.current) {
          setReplyStateOwnerId(selectedCaseId);
        }
      });
    return () => {
      active = false;
    };
  }, [selectedCaseId]);

  useEffect(() => {
    if (!selectedCaseId || replyStateOwnerId !== selectedCaseId) return;
    const draftEpoch = replyDraftEpochRef.current;
    const persistReplyDraft = () => {
      if (draftEpoch !== replyDraftEpochRef.current) return;
      void saveProductFeedbackReplyDraft(
        selectedCaseId,
        replyMessage.trim() || replyAttachment
          ? {
              attachment: replyAttachment,
              clientRequestId: replyRequestId,
              message: replyMessage,
            }
          : null,
      );
    };
    const timer = setTimeout(() => {
      persistReplyDraft();
    }, 300);
    return () => {
      clearTimeout(timer);
      persistReplyDraft();
    };
  }, [
    replyAttachment,
    replyMessage,
    replyRequestId,
    replyStateOwnerId,
    selectedCaseId,
  ]);

  useEffect(() => {
    let active = true;
    void getSupportWhatsAppUrl()
      .then(() => {
        if (active) setSupportAvailable(true);
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    let active = true;
    void Promise.all([loadProductFeedbackDraft(), loadProductFeedbackDraftConflicts()])
      .then(([draft, conflicts]) => {
        if (!active) return;
        if (draft) {
          setCategory(draft.category);
          setMessage(draft.message);
          setAttachment(draft.attachment);
          setClientRequestId(draft.clientRequestId);
        }
        const alternative = conflicts.find(conflict => conflict.type === 'new');
        if (alternative) {
          Alert.alert('توجد مسودة أخرى', 'يمكنك استعادة المسودة التي كتبتها قبل تسجيل الدخول', [
            {text: 'الاحتفاظ بالحالية', style: 'cancel'},
            {
              text: 'استعادة الأخرى',
              onPress: () => {
                void restoreProductFeedbackDraftConflict(alternative.id).then(restored => {
                  if (restored && mountedRef.current) {
                    setDraftReady(false);
                    void loadProductFeedbackDraft().then(value => {
                      if (!value || !mountedRef.current) return;
                      setCategory(value.category);
                      setMessage(value.message);
                      setAttachment(value.attachment);
                      setClientRequestId(value.clientRequestId);
                      setDraftReady(true);
                    });
                  }
                });
              },
            },
          ]);
        }
      })
      .catch(() => {
        if (active) setDraftSaveError(true);
      })
      .finally(() => {
        if (active) setDraftReady(true);
      });
    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    if (!draftReady || sent) return;
    const timer = setTimeout(() => {
      void saveProductFeedbackDraft({
        attachment,
        category,
        clientRequestId,
        message,
        updatedAt: Date.now(),
      })
        .then(() => {
          if (mountedRef.current) setDraftSaveError(false);
        })
        .catch(() => {
          if (mountedRef.current) setDraftSaveError(true);
        });
    }, 250);
    return () => clearTimeout(timer);
  }, [attachment, category, clientRequestId, draftReady, message, sent]);

  useEffect(() => {
    if (appActive || !draftReady || sent) return;
    void saveProductFeedbackDraft({
      ...draftSnapshotRef.current,
      updatedAt: Date.now(),
    }).catch(() => {
      if (mountedRef.current) setDraftSaveError(true);
    });
  }, [appActive, draftReady, sent]);

  const changeDraft = (change: () => void) => {
    change();
    setClientRequestId(secureRandomUuid());
    setError('');
  };

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      replyGenerationRef.current += 1;
      casesGenerationRef.current += 1;
    };
  }, []);

  const chooseScreenshot = async () => {
    if (pickerFlightRef.current || busy) return;
    pickerFlightRef.current = true;
    try {
      const result = await launchImageLibrary({
        mediaType: 'photo',
        quality: 0.8 as PhotoQuality,
        selectionLimit: 1,
      });
      if (!mountedRef.current) return;
      if (result.errorCode === 'permission') {
        showMediaPickerFailure(result.errorCode);
        return;
      }
      if (result.errorCode) {
        showMediaPickerFailure(result.errorCode);
        return;
      }
      const asset = result.assets?.[0];
      if (!asset?.uri) return;
      if (Number(asset.fileSize || 0) > 4 * 1024 * 1024) {
        Alert.alert('الصورة كبيرة', 'اختر صورة أصغر من ٤ ميجابايت');
        return;
      }
      const cached = await cacheLearnerDraftFile(
        'feedback',
        {
          fileName: asset.fileName,
          size: asset.fileSize,
          type: asset.type,
          uri: asset.uri,
        },
        4 * 1024 * 1024,
      );
      const previous = attachment;
      changeDraft(() =>
        setAttachment({
          fileName: cached.fileName,
          size: cached.size,
          type: cached.type,
          uri: cached.uri,
        }),
      );
      await removeLearnerDraftFile(previous);
    } catch (pickerError: unknown) {
      if (mountedRef.current) {
        showMediaPickerFailure(
          typeof pickerError === 'object' &&
            pickerError &&
            'errorCode' in pickerError
            ? String(pickerError.errorCode)
            : undefined,
        );
      }
    } finally {
      pickerFlightRef.current = false;
    }
  };

  const removeScreenshot = () => {
    const previous = attachment;
    changeDraft(() => setAttachment(undefined));
    void removeLearnerDraftFile(previous);
  };

  const chooseReplyScreenshot = async () => {
    if (pickerFlightRef.current || replyBusy) return;
    const ownerCaseId = selectedCaseId;
    const ownerGeneration = replyGenerationRef.current;
    pickerFlightRef.current = true;
    try {
      const result = await launchImageLibrary({
        mediaType: 'photo',
        quality: 0.8 as PhotoQuality,
        selectionLimit: 1,
      });
      if (!mountedRef.current || result.didCancel) return;
      if (result.errorCode) {
        showMediaPickerFailure(result.errorCode);
        return;
      }
      const asset = result.assets?.[0];
      if (!asset?.uri) return;
      if (Number(asset.fileSize || 0) > 4 * 1024 * 1024) {
        Alert.alert('الصورة كبيرة', 'اختر صورة أصغر من ٤ ميجابايت');
        return;
      }
      const cached = await cacheLearnerDraftFile(
        'feedback',
        {
          fileName: asset.fileName,
          size: asset.fileSize,
          type: asset.type,
          uri: asset.uri,
        },
        4 * 1024 * 1024,
      );
      if (
        !mountedRef.current ||
        ownerGeneration !== replyGenerationRef.current ||
        ownerCaseId !== selectedCaseId
      ) {
        await removeLearnerDraftFile(cached);
        return;
      }
      const previous = replyAttachment;
      setReplyAttachment(cached);
      setReplyRequestId(secureRandomUuid());
      await removeLearnerDraftFile(previous);
    } catch (pickerError: unknown) {
      if (mountedRef.current) {
        showMediaPickerFailure(
          typeof pickerError === 'object' &&
            pickerError &&
            'errorCode' in pickerError
            ? String(pickerError.errorCode)
            : undefined,
        );
      }
    } finally {
      pickerFlightRef.current = false;
    }
  };

  const submit = async () => {
    if (!canSubmit || submitFlightRef.current) return;
    submitFlightRef.current = true;
    setBusy(true);
    setError('');
    try {
      const receipt = await submitProductFeedback({
        attachment,
        category,
        clientRequestId,
        context: {
          locale: i18n.resolvedLanguage || i18n.language || 'ar',
          sourceScreen: route.params?.sourceScreen || 'feedback',
        },
        message,
      });
      await clearProductFeedbackDraft().catch(() => undefined);
      if (!mountedRef.current) return;
      setReceiptId(receipt.caseNumber);
      setReceiptPublicId(receipt.publicId);
      setSent(true);
    } catch {
      if (!mountedRef.current) return;
      setError('لم تصل الرسالة\nتحقق من الاتصال ثم حاول مرة أخرى\nنصك محفوظ');
    } finally {
      submitFlightRef.current = false;
      if (mountedRef.current) setBusy(false);
    }
  };

  const sendCaseReply = async () => {
    if (
      !selectedCase ||
      replyStateOwnerId !== selectedCase.publicId ||
      replyBusy ||
      replyFlightsRef.current.has(selectedCase.publicId) ||
      replyMessage.trim().length < 2
    )
      return;
    const generation = replyGenerationRef.current;
    const caseId = selectedCase.publicId;
    const messageToSend = replyMessage;
    const attachmentToSend = replyAttachment;
    const requestId = replyRequestId;
    const flight = Symbol(`support-reply-${caseId}`);
    replyFlightsRef.current.set(caseId, flight);
    setReplyPendingCaseIds(current => new Set(current).add(caseId));
    setReplyError('');
    try {
      const updated = await replyToProductFeedback({
        accessToken: selectedCase.accessToken,
        attachment: attachmentToSend,
        clientRequestId: requestId,
        message: messageToSend,
        publicId: caseId,
      });
      await saveProductFeedbackReplyDraft(caseId, null).catch(() => undefined);
      if (!mountedRef.current) return;
      setSupportCases(current =>
        current.map(item =>
          item.publicId === updated.publicId
            ? {...updated, accessToken: item.accessToken}
            : item,
        ),
      );
      void removeLearnerDraftFile(attachmentToSend);
      if (generation !== replyGenerationRef.current) return;
      replyDraftEpochRef.current += 1;
      setReplyAttachment(undefined);
      setReplyMessage('');
      setReplyRequestId(secureRandomUuid());
    } catch {
      if (mountedRef.current && generation === replyGenerationRef.current) {
        setReplyError(
          'لم يصل الرد\nتحقق من الاتصال ثم حاول مرة أخرى\nنصك محفوظ',
        );
      }
    } finally {
      if (replyFlightsRef.current.get(caseId) === flight) {
        replyFlightsRef.current.delete(caseId);
      }
      if (mountedRef.current) {
        setReplyPendingCaseIds(current => {
          const next = new Set(current);
          next.delete(caseId);
          return next;
        });
      }
    }
  };

  if (sent) {
    return (
      <Container noPadding>
        <Content noPadding>
          <ResponsiveFrame>
            <HeaderWithBack title="إرسال ملاحظة" />
            <StatusView
              actionLabel="فتح المتابعة"
              description="وصلتنا رسالتك\nيمكنك متابعة الرد من هنا"
              onAction={() => {
                setSent(false);
                setSelectedCaseId(receiptPublicId);
                void reloadCases();
              }}
              title="تم إرسال البلاغ"
            />
            {!!receiptId && (
              <Text style={styles.receipt}>رقم المتابعة {receiptId}</Text>
            )}
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

          {(casesBusy || supportCases.length > 0 || casesError) && (
            <PremiumCard style={styles.casesCard}>
              <View style={styles.casesHeader}>
                <Text style={styles.label}>حالاتك</Text>
                <Pressable
                  accessibilityLabel="تحديث الحالات"
                  accessibilityRole="button"
                  disabled={casesBusy}
                  onPress={() => void reloadCases()}>
                  <Text style={styles.refreshCases}>
                    {casesBusy ? 'جارٍ التحديث' : 'تحديث'}
                  </Text>
                </Pressable>
              </View>
              {casesBusy && supportCases.length === 0 ? (
                <Text style={styles.caseMuted}>جارٍ تحميل الحالات</Text>
              ) : (
                supportCases.map(item => {
                  const selected = item.publicId === selectedCaseId;
                  return (
                    <Pressable
                      accessibilityLabel={`الحالة ${item.caseNumber} ${
                        CASE_STATUS[item.status] || 'قيد المتابعة'
                      }`}
                      accessibilityRole="button"
                      key={item.publicId}
                      onPress={() =>
                        setSelectedCaseId(selected ? '' : item.publicId)
                      }
                      style={({pressed}) => [
                        styles.caseRow,
                        selected && styles.caseRowSelected,
                        pressed && styles.pressed,
                      ]}>
                      <View style={styles.caseCopy}>
                        <Text style={styles.caseNumber}>
                          الحالة {item.caseNumber}
                        </Text>
                        <Text numberOfLines={1} style={styles.caseMessage}>
                          {item.message}
                        </Text>
                      </View>
                      <Text style={styles.caseStatus}>
                        {CASE_STATUS[item.status] || 'قيد المتابعة'}
                      </Text>
                    </Pressable>
                  );
                })
              )}
              {!!casesError && (
                <Text accessibilityRole="alert" style={styles.error}>
                  {casesError}
                </Text>
              )}

              {!!selectedCase && (
                <View style={styles.timeline}>
                  {selectedCase.messages.map(item => (
                    <View
                      key={item.publicId}
                      style={[
                        styles.timelineMessage,
                        item.author === 'support' && styles.timelineSupport,
                      ]}>
                      <Text style={styles.timelineAuthor}>
                        {item.author === 'support' ? 'فريق الدعم' : 'أنت'}
                      </Text>
                      <Text style={styles.timelineText}>{item.text}</Text>
                      {item.attachments.map(file => (
                        <Pressable
                          accessibilityLabel="فتح الصورة المرفقة"
                          accessibilityRole="button"
                          key={file.id}
                          onPress={() => setPreviewArtifact(file)}
                          style={({pressed}) => [
                            styles.timelineAttachment,
                            pressed && styles.pressed,
                          ]}>
                          <Image
                            accessibilityIgnoresInvertColors
                            source={{uri: file.url}}
                            style={styles.timelineAttachmentImage}
                          />
                          <Text style={styles.timelineAttachmentLabel}>
                            فتح الصورة
                          </Text>
                        </Pressable>
                      ))}
                    </View>
                  ))}
                  <TextInput
                    accessibilityLabel="ردك على الحالة"
                    maxLength={2000}
                    multiline
                    onChangeText={value => {
                      setReplyMessage(value);
                      setReplyRequestId(secureRandomUuid());
                      setReplyError('');
                    }}
                    placeholder="اكتب ردك"
                    placeholderTextColor={Palette.textFaint}
                    style={styles.replyInput}
                    textAlignVertical="top"
                    value={replyMessage}
                  />
                  {replyAttachment ? (
                    <View style={styles.attachmentRow}>
                      <Image
                        accessibilityLabel="الصورة المرفقة بالرد"
                        source={{uri: replyAttachment.uri}}
                        style={styles.attachmentImage}
                      />
                      <Pressable
                        accessibilityLabel="حذف صورة الرد"
                        accessibilityRole="button"
                        onPress={() => {
                          const previous = replyAttachment;
                          setReplyAttachment(undefined);
                          setReplyRequestId(secureRandomUuid());
                          void removeLearnerDraftFile(previous);
                        }}>
                        <Text style={styles.removeAttachment}>حذف الصورة</Text>
                      </Pressable>
                    </View>
                  ) : (
                    <Pressable
                      accessibilityLabel="إضافة صورة إلى الرد"
                      accessibilityRole="button"
                      onPress={() => void chooseReplyScreenshot()}
                      style={({pressed}) => [
                        styles.replyAttachmentButton,
                        pressed && styles.pressed,
                      ]}>
                      <Text style={styles.attachmentButtonText}>أضف صورة</Text>
                    </Pressable>
                  )}
                  {!!replyError && (
                    <Text accessibilityRole="alert" style={styles.error}>
                      {replyError}
                    </Text>
                  )}
                  <Pressable
                    accessibilityRole="button"
                    accessibilityState={{
                      busy: replyBusy,
                      disabled: replyBusy || replyMessage.trim().length < 2,
                    }}
                    disabled={replyBusy || replyMessage.trim().length < 2}
                    onPress={() => void sendCaseReply()}
                    style={({pressed}) => [
                      styles.replyButton,
                      (replyBusy || replyMessage.trim().length < 2) &&
                        styles.submitDisabled,
                      pressed && styles.pressed,
                    ]}>
                    <Text style={styles.submitText}>
                      {replyBusy ? 'جارٍ الإرسال' : 'إرسال الرد'}
                    </Text>
                  </Pressable>
                </View>
              )}
            </PremiumCard>
          )}

          <SectionHeading style={styles.heading} title="ماذا حدث" />
          <Text style={styles.intro}>اكتب المشكلة أو الاقتراح بوضوح</Text>

          <View accessibilityRole="radiogroup" style={styles.categories}>
            {CATEGORIES.map(item => {
              const selected = item.key === category;
              return (
                <Pressable
                  accessibilityLabel={item.label}
                  accessibilityRole="radio"
                  accessibilityState={{checked: selected}}
                  key={item.key}
                  onPress={() => changeDraft(() => setCategory(item.key))}
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
              accessibilityHint="اكتب عشرة أحرف على الأقل"
              accessibilityLabel="تفاصيل الملاحظة"
              multiline
              maxLength={1600}
              onChangeText={value => changeDraft(() => setMessage(value))}
              placeholder="أين كنت وما الذي ظهر لك"
              placeholderTextColor={Palette.textFaint}
              selectionColor={Palette.primary}
              style={styles.input}
              textAlignVertical="top"
              value={message}
            />
            <Text style={styles.counter}>
              {toArabicDigits(message.length)} من ١٦٠٠
            </Text>

            {attachment ? (
              <View style={styles.attachmentRow}>
                <Image
                  accessibilityLabel="الصورة المرفقة"
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
                    onPress={removeScreenshot}>
                    <Text style={styles.removeAttachment}>حذف الصورة</Text>
                  </Pressable>
                </View>
              </View>
            ) : (
              <Pressable
                accessibilityLabel="إضافة صورة توضح المشكلة"
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

          {!!error && (
            <View>
              <Text accessibilityRole="alert" style={styles.error}>
                {error}
              </Text>
              {supportAvailable && (
                <Pressable
                  accessibilityRole="button"
                  onPress={() =>
                    void openSupportWhatsApp(
                      `مرحبًا فريق ركن\n\n${message.trim()}`,
                    ).catch(() => setSupportAvailable(false))
                  }
                  style={({pressed}) => [
                    styles.supportFallback,
                    pressed && styles.pressed,
                  ]}>
                  <Text style={styles.supportFallbackText}>
                    إرسالها للدعم على واتساب
                  </Text>
                </Pressable>
              )}
            </View>
          )}
          {draftSaveError && !error && (
            <Text accessibilityRole="alert" style={styles.error}>
              لم تُحفظ المسودة على الجهاز
              {'\n'}يمكنك إرسالها الآن أو تفريغ بعض المساحة
            </Text>
          )}
          <Pressable
            accessibilityLabel="إرسال الملاحظة"
            accessibilityRole="button"
            accessibilityState={{busy, disabled: !canSubmit}}
            disabled={!canSubmit}
            onPress={() => void submit()}
            style={({pressed}) => [
              styles.submit,
              !canSubmit && styles.submitDisabled,
              pressed && styles.pressed,
            ]}>
            <Text style={styles.submitText}>
              {busy ? 'جارٍ الإرسال' : 'إرسال'}
            </Text>
          </Pressable>
          <Modal
            animationType="fade"
            onRequestClose={() => setPreviewArtifact(undefined)}
            transparent
            visible={Boolean(previewArtifact)}>
            <View
              accessibilityViewIsModal
              importantForAccessibility="yes"
              style={styles.previewBackdrop}>
              <Pressable
                accessibilityLabel="إغلاق الصورة"
                accessibilityRole="button"
                onPress={() => setPreviewArtifact(undefined)}
                style={styles.previewClose}>
                <Text style={styles.previewCloseText}>إغلاق</Text>
              </Pressable>
              {previewArtifact && (
                <Image
                  accessibilityLabel={previewArtifact.name}
                  accessibilityIgnoresInvertColors
                  resizeMode="contain"
                  source={{uri: previewArtifact.url}}
                  style={styles.previewImage}
                />
              )}
            </View>
          </Modal>
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  frame: {paddingBottom: Spacing.section},
  casesCard: {padding: Spacing.lg, marginTop: Spacing.md},
  casesHeader: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  refreshCases: {
    ...Type.caption,
    color: '#9ABFFF',
    paddingVertical: Spacing.sm,
  },
  caseMuted: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.sm,
  },
  caseRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    minHeight: Accessibility.minTouchTarget,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.line,
    paddingVertical: Spacing.sm,
    gap: Spacing.sm,
  },
  caseRowSelected: {backgroundColor: Palette.primarySoft},
  caseCopy: {flex: 1, alignItems: 'flex-start'},
  caseNumber: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  caseMessage: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xxs,
  },
  caseStatus: {...Type.caption, color: '#9ABFFF'},
  timeline: {marginTop: Spacing.lg},
  timelineMessage: {
    alignSelf: 'stretch',
    borderRadius: Radius.md,
    backgroundColor: Palette.canvasSoft,
    padding: Spacing.md,
    marginBottom: Spacing.sm,
  },
  timelineSupport: {backgroundColor: Palette.primarySoft},
  timelineAuthor: {...Type.caption, ...textDirection, color: Palette.textMuted},
  timelineText: {
    ...Type.body,
    ...textDirection,
    color: Palette.text,
    marginTop: Spacing.xxs,
  },
  timelineAttachment: {
    alignSelf: 'flex-start',
    marginTop: Spacing.sm,
  },
  timelineAttachmentImage: {
    width: 112,
    height: 84,
    borderRadius: Radius.md,
    backgroundColor: Palette.canvasSoft,
  },
  timelineAttachmentLabel: {
    ...Type.caption,
    color: '#9ABFFF',
    marginTop: Spacing.xxs,
  },
  replyInput: {
    ...Type.body,
    ...textDirection,
    color: Palette.text,
    minHeight: 100,
    borderWidth: 1,
    borderColor: Palette.line,
    borderRadius: Radius.md,
    padding: Spacing.md,
    marginTop: Spacing.sm,
  },
  replyButton: {
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
    marginTop: Spacing.sm,
  },
  replyAttachmentButton: {
    minHeight: Accessibility.minTouchTarget,
    alignSelf: 'flex-start',
    justifyContent: 'center',
    marginTop: Spacing.xs,
  },
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
  receipt: {
    ...Type.caption,
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: Spacing.sm,
  },
  previewBackdrop: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.94)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: Spacing.md,
  },
  previewClose: {
    position: 'absolute',
    top: Spacing.xl,
    right: Spacing.lg,
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    zIndex: 2,
  },
  previewCloseText: {...Type.bodyStrong, color: '#FFFFFF'},
  previewImage: {width: '100%', height: '82%'},
});
