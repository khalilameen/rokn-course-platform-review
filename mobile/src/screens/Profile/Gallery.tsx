import AsyncStorage from '@react-native-async-storage/async-storage';
import React, {useCallback, useEffect, useState} from 'react';
import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import {errorPayload} from '../../utils/errorPayload';
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

const CUSTOM_PROJECTS_KEY = '@rokn/portfolio/custom-projects/v1';

const initialProjects: Project[] = [
  {
    id: 'portfolio-marketplace',
    title: 'تجربة متجر محلي',
    summary: 'تصميم رحلة شراء أبسط لمتجر يربط الحرفيين بعملائهم.',
    cover: require('../../assets/images/demo-course/portfolio-marketplace.jpg'),
    skills: ['UX', 'واجهات', 'نموذج أولي'],
    source: 'demo',
  },
  {
    id: 'portfolio-finance',
    title: 'لوحة متابعة مالية',
    summary: 'واجهة واضحة تساعد المستقل على فهم دخله ومصروفاته بسرعة.',
    cover: require('../../assets/images/demo-course/portfolio-finance.jpg'),
    skills: ['بحث', 'نظم تصميم', 'Figma'],
    source: 'demo',
  },
];

export default function Gallery() {
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
    {uri: string; type?: string; fileName?: string} | undefined
  >();

  const loadProjects = useCallback(async () => {
    setLoading(true);
    const sessionAvailable = await hasSession();
    setServerSession(sessionAvailable);
    if (sessionAvailable) {
      try {
        const items = await getPortfolio();
        setProjects(
          items.map(item => ({
            id: item.id,
            title: item.title,
            summary: item.summary,
            cover: item.coverUri
              ? {uri: item.coverUri}
              : require('../../assets/images/courseSliderBackground.jpg'),
            skills: item.skills.length ? item.skills : ['مشروع عملي'],
            source: 'remote' as const,
            courseName: item.courseName,
            courseId: item.courseId,
            sourceProjectId: item.sourceProjectId,
          })),
        );
        setLoadError('');
      } catch {
        setProjects([]);
        setLoadError('تعذّر تحميل البورتفوليو الآن. مشروعاتك لم تُفقد.');
      } finally {
        setLoading(false);
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
      const value = await AsyncStorage.getItem(CUSTOM_PROJECTS_KEY);
      if (value) {
        const saved = JSON.parse(value) as Project[];
        if (Array.isArray(saved)) {
          setProjects([
            ...saved
              .filter(item => item?.id?.startsWith('local-'))
              .map(item => ({...item, source: 'local' as const})),
            ...initialProjects,
          ]);
        }
      }
    } catch {
      // Invalid local drafts leave verified demo portfolio items visible.
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadProjects();
  }, [loadProjects]);

  const persistCustomProjects = (next: Project[]) =>
    AsyncStorage.setItem(
      CUSTOM_PROJECTS_KEY,
      JSON.stringify(next.filter(item => item.id.startsWith('local-'))),
    );

  const pickCover = async () => {
    const result = await launchImageLibrary({
      mediaType: 'photo',
      selectionLimit: 1,
      quality: 0.8,
    });
    const asset = result.assets?.[0];
    if (asset?.fileSize && asset.fileSize > 8 * 1024 * 1024) {
      Alert.alert('الصورة كبيرة', 'اختر غلافًا أصغر من ٨ ميجابايت.');
      return;
    }
    if (asset?.uri) {
      setDraftCover({uri: asset.uri});
      setDraftCoverAsset({
        uri: asset.uri,
        type: asset.type,
        fileName: asset.fileName,
      });
    }
  };

  const clearDraft = () => {
    setDraftTitle('');
    setDraftSummary('');
    setDraftCover(null);
    setDraftCoverAsset(undefined);
    setSelectedSourceProject(null);
  };

  const openAddProject = useCallback(() => {
    setAdding(true);
    if (!serverSession) return;
    setEligibleLoading(true);
    void getEligibleProjects()
      .then(setEligibleProjects)
      .catch(() => setEligibleProjects([]))
      .finally(() => setEligibleLoading(false));
  }, [serverSession]);

  const chooseSourceProject = (project: EligibleProject) => {
    setSelectedSourceProject(project);
    setDraftTitle(project.title);
    setDraftSummary(project.summary);
    setDraftCover(project.courseImage ? {uri: project.courseImage} : null);
    // Remote course covers are visual previews, not local upload assets.
    setDraftCoverAsset(undefined);
  };

  const addProject = async () => {
    if (!draftTitle.trim() || saving) return;
    setSaving(true);
    try {
      if (serverSession) {
        const item = await createPortfolioItem({
          title: draftTitle.trim(),
          summary: draftSummary.trim(),
          cover: draftCoverAsset,
          sourceProjectId: selectedSourceProject?.projectId,
          courseId: selectedSourceProject?.courseId,
        });
        setProjects(current => [
          {
            id: item.id,
            title: item.title,
            summary: item.summary,
            cover: item.coverUri
              ? {uri: item.coverUri}
              : draftCover ??
                require('../../assets/images/courseSliderBackground.jpg'),
            skills: item.skills.length ? item.skills : ['مشروع عملي'],
            source: 'remote',
            courseName: item.courseName,
            courseId: item.courseId,
            sourceProjectId: item.sourceProjectId,
          },
          ...current,
        ]);
        if (selectedSourceProject) {
          setEligibleProjects(current =>
            current.filter(
              candidate =>
                candidate.projectId !== selectedSourceProject.projectId,
            ),
          );
        }
      } else {
        setProjects(current => {
          const next: Project[] = [
            {
              id: `local-${Date.now()}`,
              title: draftTitle.trim(),
              summary: draftSummary.trim() || 'مشروع أضفته إلى بورتفوليو ركن.',
              cover:
                draftCover ??
                require('../../assets/images/demo-course/portfolio-marketplace.jpg'),
              skills: ['مشروع جديد'],
              source: 'local',
            },
            ...current,
          ];
          void persistCustomProjects(next);
          return next;
        });
      }
      clearDraft();
      setAdding(false);
    } catch (error: unknown) {
      const payload = errorPayload(error);
      Alert.alert(
        'تعذّر إضافة المشروع',
        String(
          payload.message || 'احتفظنا بالبيانات في الشاشة. حاول مرة أخرى.',
        ),
      );
    } finally {
      setSaving(false);
    }
  };

  const deleteSelectedProject = async () => {
    if (!selected || saving || selected.source === 'demo') return;
    setSaving(true);
    try {
      if (selected.source === 'remote') {
        await deletePortfolioItem(selected.id);
      }
      setProjects(current => {
        const next = current.filter(item => item.id !== selected.id);
        if (selected.source === 'local') void persistCustomProjects(next);
        return next;
      });
      setSelected(null);
    } catch {
      Alert.alert(
        'تعذّر حذف المشروع',
        'لم نحذف شيئًا. حاول مرة أخرى بعد قليل.',
      );
    } finally {
      setSaving(false);
    }
  };

  const confirmDeleteSelectedProject = () => {
    if (!selected || selected.source === 'demo' || saving) return;
    Alert.alert(
      'حذف المشروع',
      `هل تريد حذف «${selected.title}» من البورتفوليو؟ لا يمكن التراجع عن ذلك.`,
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
      {loading ? (
        <StatusView state="loading" title="نرتّب مشروعاتك…" />
      ) : loadError ? (
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
          description="سجّل دخولك علشان تضيف مشروعاتك وتشارك رابط بورتفوليو ثابت."
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
          description="أضف عملًا أو أكمل مشروع كورس ليظهر هنا."
          onAction={openAddProject}
          title="أضف أول مشروع"
        />
      ) : (
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
      )}

      <Modal
        animationType="slide"
        onRequestClose={() => setSelected(null)}
        transparent
        visible={!!selected}>
        <View style={styles.overlay}>
          <View style={styles.sheet}>
            <ScrollView showsVerticalScrollIndicator={false}>
              {selected && (
                <>
                  <Image source={selected.cover} style={styles.detailCover} />
                  <View
                    style={[
                      styles.detailCopy,
                      {
                        paddingBottom: Math.max(
                          Spacing.xl,
                          insets.bottom + Spacing.md,
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
        animationType="slide"
        onRequestClose={() => setAdding(false)}
        transparent
        visible={adding}>
        <View style={styles.overlay}>
          <View style={styles.sheet}>
            <ScrollView keyboardShouldPersistTaps="handled">
              <View
                style={[
                  styles.detailCopy,
                  {
                    paddingBottom: Math.max(
                      Spacing.xl,
                      insets.bottom + Spacing.md,
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
                        أي مشروع تجتازه سيظهر هنا لتضيفه بضغطة.
                      </Text>
                    )}
                    {selectedSourceProject && (
                      <Pressable
                        accessibilityRole="button"
                        onPress={() => {
                          setSelectedSourceProject(null);
                          setDraftCover(null);
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
                  onChangeText={setDraftTitle}
                  placeholder="مثال: هوية لمقهى محلي"
                  placeholderTextColor={Palette.textFaint}
                  style={styles.input}
                  value={draftTitle}
                />
                <Text style={styles.fieldLabel}>وصف مختصر</Text>
                <TextInput
                  multiline
                  onChangeText={setDraftSummary}
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
                  onPress={() => setAdding(false)}
                  title="إلغاء"
                  useGradient={false}
                />
                {saving && (
                  <ActivityIndicator
                    color={Palette.primary}
                    style={styles.savingIndicator}
                  />
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
    minHeight: 42,
    alignSelf: 'flex-end',
    justifyContent: 'center',
  },
  manualEntryLabel: {...Type.caption, color: Palette.primary},
  savingIndicator: {marginTop: Spacing.sm},
  pressed: {opacity: 0.8, transform: [{scale: 0.99}]},
});
