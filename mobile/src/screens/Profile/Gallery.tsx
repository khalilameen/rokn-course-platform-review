import React, {useCallback, useEffect, useRef, useState} from 'react';
import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import {learnerErrorMessage} from '../../utils/errorPayload';
import {
  ActivityIndicator,
  Alert,
  Image,
  ImageSourcePropType,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import {launchImageLibrary} from 'react-native-image-picker';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {useReducedMotion} from '../../hooks/useReducedMotion';
import Button from '../../components/touchables/Button';
import {
  MetaPill,
  SectionHeading,
  StatusView,
} from '../../components/ui/PremiumUI';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import {
  createPortfolioItem,
  deletePortfolioItem,
  getEligibleProjects,
  getPortfolio,
  hasSession,
  type EligibleProject,
} from '../../services/roknApi';
import {LOCAL_DEMO_ENABLED} from '../../config/runtime';
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import {
  readLocalPortfolioDrafts,
  writeLocalPortfolioDrafts,
} from '../../services/localUiState';
import {
  clearPortfolioEditorDraft,
  readPortfolioEditorDraft,
  writePortfolioEditorDraft,
} from '../../services/portfolioDraft';
import {
  cacheLearnerDraftFile,
  removeLearnerDraftFile,
} from '../../services/learnerDraftFiles';
import {secureRandomUuid} from '../../utils/secureRandom';
import {useAppActiveState} from '../../hooks/useAppActiveState';
import {showMediaPickerFailure} from '../../services/mediaPickerErrors';

type Project = {
  id: string;
  title: string;
  summary: string;
  cover: ImageSourcePropType;
  skills: string[];
  source: 'demo' | 'local' | 'remote';
  courseName?: string;
  courseId?: string;
  sourceProjectId?: string;
};

const initialProjects: Project[] = [
  {
    id: 'portfolio-marketplace',
    title: 'تجربة متجر محلي',
    summary: 'تصميم رحلة شراء أبسط لمتجر يربط الحرفيين بعملائهم',
    cover: require('../../assets/images/demo-course/portfolio-marketplace.jpg'),
    skills: ['UX', 'واجهات', 'نموذج أولي'],
    source: 'demo',
  },
  {
    id: 'portfolio-finance',
    title: 'لوحة متابعة مالية',
    summary: 'واجهة واضحة تساعد المستقل على فهم دخله ومصروفاته بسرعة',
    cover: require('../../assets/images/demo-course/portfolio-finance.jpg'),
    skills: ['بحث', 'نظم تصميم', 'Figma'],
    source: 'demo',
  },
];

export default function Gallery() {
  const reducedMotion = useReducedMotion();
  const appActive = useAppActiveState();
  const navigation = useNavigation<RootNavigation>();
  const insets = useSafeAreaInsets();
  const {contentWidth, gutter, gridColumns, gridGap} = useResponsiveLayout();
  const columns = Math.max(1, Math.min(gridColumns, 3));
  const cardWidth =
    (contentWidth - gutter * 2 - gridGap * (columns - 1)) / columns;
  const [projects, setProjects] = useState<Project[]>(
    LOCAL_DEMO_ENABLED ? initialProjects : [],
  );
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [selected, setSelected] = useState<Project | null>(null);
  const [adding, setAdding] = useState(false);
  const [saving, setSaving] = useState(false);
  const [eligibleProjects, setEligibleProjects] = useState<EligibleProject[]>(
    [],
  );
  const [eligibleLoading, setEligibleLoading] = useState(false);
  const [selectedSourceProject, setSelectedSourceProject] =
    useState<EligibleProject | null>(null);
  const [draftTitle, setDraftTitle] = useState('');
  const [draftSummary, setDraftSummary] = useState('');
  const [draftCover, setDraftCover] = useState<ImageSourcePropType | null>(
    null,
  );
  const [draftCoverAsset, setDraftCoverAsset] = useState<
    {uri: string; type?: string; fileName?: string; size?: number} | undefined
  >();
  const [draftReady, setDraftReady] = useState(false);
  const [clientRequestId, setClientRequestId] = useState(secureRandomUuid);
  const [draftSaveError, setDraftSaveError] = useState(false);
  const mountedRef = useRef(true);
  const loadGenerationRef = useRef(0);
  const eligibleGenerationRef = useRef(0);
  const addFlightRef = useRef(false);
  const deleteFlightRef = useRef(false);
  const pickerFlightRef = useRef(false);
  const draftSnapshotRef = useRef({
    clientRequestId,
    cover: draftCoverAsset,
    selectedSource: selectedSourceProject || undefined,
    summary: draftSummary,
    title: draftTitle,
    updatedAt: Date.now(),
  });
  draftSnapshotRef.current = {
    clientRequestId,
    cover: draftCoverAsset,
    selectedSource: selectedSourceProject || undefined,
    summary: draftSummary,
    title: draftTitle,
    updatedAt: Date.now(),
  };

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      loadGenerationRef.current += 1;
      eligibleGenerationRef.current += 1;
    };
  }, []);

  useEffect(() => {
    let active = true;
    void readPortfolioEditorDraft()
      .then(draft => {
        if (!active || !draft) return;
        setDraftTitle(draft.title);
        setDraftSummary(draft.summary);
        setDraftCoverAsset(draft.cover);
        setDraftCover(draft.cover ? {uri: draft.cover.uri} : null);
        setSelectedSourceProject(draft.selectedSource || null);
        setClientRequestId(draft.clientRequestId);
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
    if (!draftReady) return;
    const timer = setTimeout(() => {
      void writePortfolioEditorDraft({
        clientRequestId,
        cover: draftCoverAsset,
        selectedSource: selectedSourceProject || undefined,
        summary: draftSummary,
        title: draftTitle,
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
  }, [
    clientRequestId,
    draftCoverAsset,
    draftReady,
    draftSummary,
    draftTitle,
    selectedSourceProject,
  ]);

  useEffect(() => {
    if (appActive || !draftReady) return;
    void writePortfolioEditorDraft({
      ...draftSnapshotRef.current,
      updatedAt: Date.now(),
    }).catch(() => {
      if (mountedRef.current) setDraftSaveError(true);
    });
  }, [appActive, draftReady]);

  const changeDraft = (change: () => void) => {
    change();
    setClientRequestId(secureRandomUuid());
  };

  const loadProjects = useCallback(async () => {
    const generation = ++loadGenerationRef.current;
    const isCurrent = () =>
      mountedRef.current && generation === loadGenerationRef.current;
    setLoading(true);
    let sessionAvailable = false;
    try {
      sessionAvailable = await hasSession();
    } catch {
      if (isCurrent()) {
        setLoadError('تعذّر تحميل البورتفوليو\nأعد فتح الصفحة');
        setLoading(false);
      }
      return;
    }
    if (!isCurrent()) return;
    setServerSession(sessionAvailable);
    if (sessionAvailable) {
      try {
        const items = await getPortfolio();
        if (!isCurrent()) return;
        setProjects(
          items.map(item => ({
            id: item.id,
            title: item.title,
            summary: item.summary,
            cover: item.coverUri
              ? {uri: item.coverUri}
              : require('../../assets/images/courseSliderBackground.jpg'),
            skills: item.skills,
            source: 'remote' as const,
            courseName: item.courseName,
            courseId: item.courseId,
            sourceProjectId: item.sourceProjectId,
          })),
        );
        setLoadError('');
      } catch {
        if (isCurrent()) {
          setLoadError('تعذّر تحميل البورتفوليو\nمشروعاتك محفوظة');
        }
      } finally {
        if (isCurrent()) setLoading(false);
      }
      return;
    }

    if (!LOCAL_DEMO_ENABLED) {
      setProjects([]);
      setLoadError('');
      setLoading(false);
      return;
    }

    try {
      setProjects(initialProjects);
      const saved = await readLocalPortfolioDrafts<Project>();
      if (!isCurrent()) return;
      setProjects([
        ...saved
          .filter(item => item?.id?.startsWith('local-'))
          .map(item => ({...item, source: 'local' as const})),
        ...initialProjects,
      ]);
    } catch {
      // Invalid local drafts leave verified demo portfolio items visible.
    } finally {
      if (isCurrent()) setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadProjects();
  }, [loadProjects]);

  const persistCustomProjects = (next: Project[]) =>
    writeLocalPortfolioDrafts(
      next.filter(item => item.id.startsWith('local-')),
    );

  const pickCover = async () => {
    if (pickerFlightRef.current || saving) return;
    pickerFlightRef.current = true;
    try {
      const result = await launchImageLibrary({
        mediaType: 'photo',
        selectionLimit: 1,
        quality: 0.8,
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
      if (asset?.fileSize && asset.fileSize > 8 * 1024 * 1024) {
        Alert.alert('الصورة كبيرة', 'اختر غلافًا أصغر من ٨ ميجابايت');
        return;
      }
      if (asset?.uri) {
        const cached = await cacheLearnerDraftFile(
          'portfolio',
          {
            uri: asset.uri,
            type: asset.type,
            fileName: asset.fileName,
            size: asset.fileSize,
          },
          8 * 1024 * 1024,
        );
        const previous = draftCoverAsset;
        changeDraft(() => {
          setDraftCover({uri: cached.uri});
          setDraftCoverAsset(cached);
        });
        await removeLearnerDraftFile(previous);
      }
    } catch (error: unknown) {
      if (mountedRef.current) {
        showMediaPickerFailure(
          typeof error === 'object' && error && 'errorCode' in error
            ? String(error.errorCode)
            : undefined,
        );
      }
    } finally {
      pickerFlightRef.current = false;
    }
  };

  const clearDraft = () => {
    const previous = draftCoverAsset;
    setDraftTitle('');
    setDraftSummary('');
    setDraftCover(null);
    setDraftCoverAsset(undefined);
    setSelectedSourceProject(null);
    setClientRequestId(secureRandomUuid());
    setDraftSaveError(false);
    void Promise.all([
      clearPortfolioEditorDraft(),
      removeLearnerDraftFile(previous),
    ]).catch(() => {
      if (mountedRef.current) setDraftSaveError(true);
    });
  };

  const openAddProject = useCallback(() => {
    setAdding(true);
    if (!serverSession) return;
    const generation = ++eligibleGenerationRef.current;
    setEligibleLoading(true);
    void getEligibleProjects()
      .then(eligibleItems => {
        if (
          mountedRef.current &&
          eligibleGenerationRef.current === generation
        ) {
          setEligibleProjects(eligibleItems);
        }
      })
      .catch(() => {
        if (
          mountedRef.current &&
          eligibleGenerationRef.current === generation
        ) {
          setEligibleProjects([]);
        }
      })
      .finally(() => {
        if (
          mountedRef.current &&
          eligibleGenerationRef.current === generation
        ) {
          setEligibleLoading(false);
        }
      });
  }, [serverSession]);

  const closeAddProject = () => {
    if (saving) return;
    eligibleGenerationRef.current += 1;
    setEligibleLoading(false);
    setAdding(false);
  };

  const chooseSourceProject = (project: EligibleProject) => {
    const previous = draftCoverAsset;
    changeDraft(() => {
      setSelectedSourceProject(project);
      setDraftTitle(project.title);
      setDraftSummary(project.summary);
      setDraftCover(project.courseImage ? {uri: project.courseImage} : null);
      // Remote course covers are visual previews, not local upload assets.
      setDraftCoverAsset(undefined);
    });
    void removeLearnerDraftFile(previous);
  };

  const addProject = async () => {
    if (!draftTitle.trim() || saving || addFlightRef.current) return;
    addFlightRef.current = true;
    setSaving(true);
    try {
      if (serverSession) {
        const item = await createPortfolioItem({
          title: draftTitle.trim(),
          summary: draftSummary.trim(),
          cover: draftCoverAsset,
          sourceProjectId: selectedSourceProject?.projectId,
          courseId: selectedSourceProject?.courseId,
          clientRequestId,
        });
        if (mountedRef.current)
          setProjects(current => [
            {
              id: item.id,
              title: item.title,
              summary: item.summary,
              cover: item.coverUri
                ? {uri: item.coverUri}
                : draftCover ??
                  require('../../assets/images/courseSliderBackground.jpg'),
              skills: item.skills,
              source: 'remote',
              courseName: item.courseName,
              courseId: item.courseId,
              sourceProjectId: item.sourceProjectId,
            },
            ...current,
          ]);
        if (selectedSourceProject && mountedRef.current) {
          setEligibleProjects(current =>
            current.filter(
              candidate =>
                candidate.projectId !== selectedSourceProject.projectId,
            ),
          );
        }
      } else {
        if (mountedRef.current)
          setProjects(current => {
            const next: Project[] = [
              {
                id: `local-${Date.now()}`,
                title: draftTitle.trim(),
                summary: draftSummary.trim() || 'مشروع أضفته إلى بورتفوليو ركن',
                cover:
                  draftCover ??
                  require('../../assets/images/demo-course/portfolio-marketplace.jpg'),
                skills: ['مشروع جديد'],
                source: 'local',
              },
              ...current,
            ];
            void persistCustomProjects(next).catch(() => {
              if (mountedRef.current) setDraftSaveError(true);
            });
            return next;
          });
      }
      if (mountedRef.current) {
        clearDraft();
        setAdding(false);
      } else {
        await clearPortfolioEditorDraft().catch(() => undefined);
      }
    } catch (error: unknown) {
      if (mountedRef.current) {
        Alert.alert(
          'تعذّر إضافة المشروع',
          learnerErrorMessage(error, 'لم يُضف المشروع\nحاول مرة أخرى'),
        );
      }
    } finally {
      addFlightRef.current = false;
      if (mountedRef.current) setSaving(false);
    }
  };

  const deleteSelectedProject = async () => {
    if (
      !selected ||
      saving ||
      selected.source === 'demo' ||
      deleteFlightRef.current
    )
      return;
    deleteFlightRef.current = true;
    setSaving(true);
    try {
      if (selected.source === 'remote') {
        await deletePortfolioItem(selected.id);
      }
      if (mountedRef.current)
        setProjects(current => {
          const next = current.filter(item => item.id !== selected.id);
          if (selected.source === 'local') {
            void persistCustomProjects(next).catch(() => {
              if (mountedRef.current) setDraftSaveError(true);
            });
          }
          return next;
        });
      if (mountedRef.current) setSelected(null);
    } catch {
      if (mountedRef.current) {
        Alert.alert(
          'تعذّر حذف المشروع',
          'المشروع ما زال محفوظًا\nحاول مرة أخرى',
        );
      }
    } finally {
      deleteFlightRef.current = false;
      if (mountedRef.current) setSaving(false);
    }
  };

  const confirmDeleteSelectedProject = () => {
    if (!selected || selected.source === 'demo' || saving) return;
    Alert.alert(
      'حذف المشروع',
      `سيُحذف ${selected.title} من البورتفوليو\nلا يمكن التراجع`,
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'حذف المشروع',
          style: 'destructive',
          onPress: () => void deleteSelectedProject(),
        },
      ],
    );
  };

  return (
    <View style={styles.container}>
      <SectionHeading
        actionLabel={
          loading || (serverSession === false && !LOCAL_DEMO_ENABLED)
            ? undefined
            : 'إضافة مشروع'
        }
        onAction={openAddProject}
        title="مشاريعي"
      />
      {loading && !projects.length ? (
        <StatusView state="loading" title="جارٍ تحميل مشروعاتك" />
      ) : loadError && !projects.length ? (
        <StatusView
          actionLabel="إعادة المحاولة"
          description={loadError}
          onAction={loadProjects}
          state="error"
          title="تعذّر تحميل البورتفوليو"
        />
      ) : serverSession === false && !LOCAL_DEMO_ENABLED ? (
        <StatusView
          actionLabel="تسجيل الدخول"
          description="سجّل الدخول لإضافة مشروعاتك ومشاركة البورتفوليو"
          onAction={() =>
            navigation.navigate('Login', {
              returnTo: {name: 'Profile'},
            })
          }
          state="empty"
          title="بورتفوليوك مرتبط بحسابك"
        />
      ) : !projects.length ? (
        <StatusView
          actionLabel="إضافة أول مشروع"
          description="أضف عملًا أو أكمل مشروع كورس ليظهر هنا"
          onAction={openAddProject}
          title="أضف أول مشروع"
        />
      ) : (
        <>
          {!!loadError && (
            <Text accessibilityRole="alert" style={styles.loadNotice}>
              {loadError}
            </Text>
          )}
          <View style={[styles.grid, {gap: gridGap}]}>
            {projects.map(project => (
              <Pressable
                accessibilityLabel={`فتح مشروع ${project.title}`}
                accessibilityRole="button"
                key={project.id}
                onPress={() => setSelected(project)}
                style={({pressed}) => [
                  styles.projectCard,
                  {width: cardWidth},
                  pressed && styles.pressed,
                ]}>
                <Image source={project.cover} style={styles.cover} />
                <View style={styles.projectCopy}>
                  <Text numberOfLines={2} style={styles.projectTitle}>
                    {formatArabicDisplayText(project.title)}
                  </Text>
                  <Text numberOfLines={2} style={styles.projectSummary}>
                    {formatArabicDisplayText(project.summary)}
                  </Text>
                  <View style={styles.skillsRow}>
                    {project.skills.slice(0, 2).map(skill => (
                      <MetaPill key={skill} label={skill} />
                    ))}
                  </View>
                </View>
              </Pressable>
            ))}
          </View>
        </>
      )}

      <Modal
        animationType={reducedMotion ? 'none' : 'slide'}
        onRequestClose={() => setSelected(null)}
        statusBarTranslucent
        transparent
        visible={!!selected}>
        <View style={styles.overlay}>
          <View accessibilityViewIsModal style={styles.sheet}>
            <ScrollView
              contentInsetAdjustmentBehavior="automatic"
              showsVerticalScrollIndicator={false}>
              {selected && (
                <>
                  <Image
                    accessible={false}
                    importantForAccessibility="no"
                    source={selected.cover}
                    style={styles.detailCover}
                  />
                  <View
                    style={[
                      styles.detailCopy,
                      {
                        paddingBottom: Math.max(
                          Spacing.xl,
                          insets.bottom + Spacing.md,
                        ),
                        paddingLeft: Math.max(
                          Spacing.xl,
                          insets.left + Spacing.md,
                        ),
                        paddingRight: Math.max(
                          Spacing.xl,
                          insets.right + Spacing.md,
                        ),
                      },
                    ]}>
                    <MetaPill
                      label={
                        selected.courseName
                          ? `من كورس ${selected.courseName}`
                          : 'مشروع مهني'
                      }
                      tone="primary"
                    />
                    <Text style={styles.detailTitle}>
                      {formatArabicDisplayText(selected.title)}
                    </Text>
                    <Text style={styles.detailSummary}>
                      {formatArabicDisplayText(selected.summary)}
                    </Text>
                    <View style={styles.skillsRow}>
                      {selected.skills.map(skill => (
                        <MetaPill key={skill} label={skill} />
                      ))}
                    </View>
                    <Button
                      onPress={() => setSelected(null)}
                      title="إغلاق"
                      useGradient={false}
                    />
                    {selected.source !== 'demo' && (
                      <Button
                        disable={saving}
                        loader={saving}
                        onPress={confirmDeleteSelectedProject}
                        title="حذف المشروع"
                        useGradient={false}
                      />
                    )}
                  </View>
                </>
              )}
            </ScrollView>
          </View>
        </View>
      </Modal>

      <Modal
        animationType={reducedMotion ? 'none' : 'slide'}
        onRequestClose={closeAddProject}
        statusBarTranslucent
        transparent
        visible={adding}>
        <View style={styles.overlay}>
          <View accessibilityViewIsModal style={styles.sheet}>
            <ScrollView
              automaticallyAdjustKeyboardInsets
              contentInsetAdjustmentBehavior="automatic"
              keyboardDismissMode="interactive"
              keyboardShouldPersistTaps="handled">
              <View
                style={[
                  styles.detailCopy,
                  {
                    paddingBottom: Math.max(
                      Spacing.xl,
                      insets.bottom + Spacing.md,
                    ),
                    paddingLeft: Math.max(Spacing.xl, insets.left + Spacing.md),
                    paddingRight: Math.max(
                      Spacing.xl,
                      insets.right + Spacing.md,
                    ),
                  },
                ]}>
                <Text style={styles.detailTitle}>أضف مشروعًا</Text>
                {serverSession && (
                  <View style={styles.eligibleSection}>
                    <Text style={styles.fieldLabel}>من مشروعاتك المكتملة</Text>
                    {eligibleLoading ? (
                      <ActivityIndicator
                        color={Palette.primary}
                        style={styles.eligibleLoader}
                      />
                    ) : eligibleProjects.length ? (
                      <ScrollView
                        horizontal
                        contentContainerStyle={styles.eligibleList}
                        showsHorizontalScrollIndicator={false}>
                        {eligibleProjects.map(project => {
                          const active =
                            selectedSourceProject?.projectId ===
                            project.projectId;
                          return (
                            <Pressable
                              accessibilityRole="button"
                              key={project.projectId}
                              onPress={() => chooseSourceProject(project)}
                              style={({pressed}) => [
                                styles.eligibleCard,
                                active && styles.eligibleCardActive,
                                pressed && styles.pressed,
                              ]}>
                              {project.courseImage ? (
                                <Image
                                  source={{uri: project.courseImage}}
                                  style={styles.eligibleImage}
                                />
                              ) : null}
                              <View style={styles.eligibleCopy}>
                                <Text
                                  numberOfLines={1}
                                  style={styles.eligibleCourse}>
                                  {formatArabicDisplayText(project.courseName)}
                                </Text>
                                <Text
                                  numberOfLines={2}
                                  style={styles.eligibleTitle}>
                                  {formatArabicDisplayText(project.title)}
                                </Text>
                              </View>
                            </Pressable>
                          );
                        })}
                      </ScrollView>
                    ) : (
                      <Text style={styles.eligibleEmpty}>
                        أي مشروع تجتازه سيظهر هنا لتضيفه بضغطة
                      </Text>
                    )}
                    {selectedSourceProject && (
                      <Pressable
                        accessibilityRole="button"
                        onPress={() => {
                          changeDraft(() => {
                            setSelectedSourceProject(null);
                            setDraftCover(null);
                          });
                        }}
                        style={styles.manualEntryButton}>
                        <Text style={styles.manualEntryLabel}>
                          إضافة مشروع مستقل بدلًا منه
                        </Text>
                      </Pressable>
                    )}
                  </View>
                )}
                <Text style={styles.fieldLabel}>اسم المشروع</Text>
                <TextInput
                  accessibilityLabel="اسم المشروع"
                  onChangeText={value =>
                    changeDraft(() => setDraftTitle(value))
                  }
                  placeholder="هوية لمقهى محلي"
                  placeholderTextColor={Palette.textFaint}
                  style={styles.input}
                  value={draftTitle}
                />
                <Text style={styles.fieldLabel}>وصف مختصر</Text>
                <TextInput
                  accessibilityLabel="وصف المشروع"
                  multiline
                  onChangeText={value =>
                    changeDraft(() => setDraftSummary(value))
                  }
                  placeholder="المشكلة التي حللتها والنتيجة"
                  placeholderTextColor={Palette.textFaint}
                  style={[styles.input, styles.multiline]}
                  value={draftSummary}
                />
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="اختيار غلاف المشروع"
                  onPress={pickCover}
                  style={styles.coverPicker}>
                  {draftCover ? (
                    <Image source={draftCover} style={styles.pickedCover} />
                  ) : (
                    <Text style={styles.coverPickerLabel}>
                      اختيار غلاف المشروع
                    </Text>
                  )}
                </Pressable>
                <Button
                  disable={!draftTitle.trim() || saving}
                  loader={saving}
                  onPress={addProject}
                  title="إضافة للبورتفوليو"
                />
                <Button
                  disable={saving}
                  onPress={closeAddProject}
                  title="إلغاء"
                  useGradient={false}
                />
                {saving && (
                  <ActivityIndicator
                    color={Palette.primary}
                    style={styles.savingIndicator}
                  />
                )}
                {draftSaveError && !saving && (
                  <Text accessibilityRole="alert" style={styles.draftError}>
                    لم تُحفظ المسودة على الجهاز
                    {'\n'}يمكنك المتابعة أو تفريغ بعض المساحة
                  </Text>
                )}
              </View>
            </ScrollView>
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {paddingBottom: Spacing.xl},
  loadNotice: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.sm,
  },
  grid: {
    direction: 'rtl',
    flexDirection: 'row',
    flexWrap: 'wrap',
    marginTop: Spacing.md,
  },
  projectCard: {
    borderRadius: Radius.lg,
    overflow: 'hidden',
    backgroundColor: Palette.surface,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
  },
  cover: {width: '100%', aspectRatio: 1.15, resizeMode: 'cover'},
  projectCopy: {padding: Spacing.md},
  projectTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  projectSummary: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
    minHeight: 40,
  },
  skillsRow: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    gap: Spacing.xs,
    marginTop: Spacing.sm,
  },
  overlay: {
    flex: 1,
    backgroundColor: Palette.overlay,
    justifyContent: 'flex-end',
    alignItems: 'center',
  },
  sheet: {
    width: '100%',
    maxWidth: 760,
    maxHeight: '90%',
    backgroundColor: Palette.canvasSoft,
    borderTopLeftRadius: Radius.xl,
    borderTopRightRadius: Radius.xl,
    overflow: 'hidden',
    borderWidth: 1,
    borderColor: Palette.lineSoft,
  },
  detailCover: {width: '100%', aspectRatio: 1.5, resizeMode: 'cover'},
  detailCopy: {padding: Spacing.xl},
  detailTitle: {
    ...Type.title,
    ...textDirection,
    color: Palette.text,
    marginTop: Spacing.md,
  },
  detailSummary: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.sm,
  },
  fieldLabel: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.md,
    marginBottom: Spacing.xs,
  },
  input: {
    ...Type.body,
    ...textDirection,
    minHeight: 52,
    color: Palette.text,
    backgroundColor: Palette.surface,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.line,
    paddingHorizontal: Spacing.md,
  },
  multiline: {minHeight: 112, textAlignVertical: 'top', paddingTop: Spacing.md},
  coverPicker: {
    minHeight: 120,
    marginTop: Spacing.md,
    borderRadius: Radius.md,
    borderStyle: 'dashed',
    borderWidth: 1,
    borderColor: Palette.textFaint,
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
  },
  coverPickerLabel: {...Type.bodyStrong, color: Palette.textMuted},
  pickedCover: {width: '100%', height: 160, resizeMode: 'cover'},
  eligibleSection: {marginTop: Spacing.sm},
  eligibleLoader: {alignSelf: 'flex-end', marginVertical: Spacing.md},
  eligibleList: {
    direction: 'rtl',
    flexDirection: 'row',
    gap: Spacing.sm,
    paddingVertical: Spacing.xs,
  },
  eligibleCard: {
    width: 220,
    minHeight: 88,
    ...rtlRowStyle,
    alignItems: 'center',
    overflow: 'hidden',
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.line,
    backgroundColor: Palette.surface,
  },
  eligibleCardActive: {
    borderColor: Palette.primary,
    backgroundColor: Palette.primarySoft,
  },
  eligibleImage: {width: 72, alignSelf: 'stretch', resizeMode: 'cover'},
  eligibleCopy: {flex: 1, minWidth: 0, padding: Spacing.sm},
  eligibleCourse: {...Type.caption, ...textDirection, color: Palette.primary},
  eligibleTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    marginTop: 2,
  },
  eligibleEmpty: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    paddingVertical: Spacing.sm,
  },
  manualEntryButton: {
    minHeight: 48,
    alignSelf: 'flex-end',
    justifyContent: 'center',
  },
  manualEntryLabel: {...Type.caption, color: Palette.primary},
  savingIndicator: {marginTop: Spacing.sm},
  draftError: {
    ...Type.caption,
    ...textDirection,
    color: Palette.danger,
    marginTop: Spacing.sm,
  },
  pressed: {opacity: 0.8, transform: [{scale: 0.99}]},
});
