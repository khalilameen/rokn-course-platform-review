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
import Video from 'react-native-video';
import {launchImageLibrary, type MediaType} from 'react-native-image-picker';
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
  appendPortfolioMedia,
  deletePortfolioMedia,
  deletePortfolioItem,
  finalizePortfolioItem,
  getEligibleProjects,
  getPortfolio,
  getPortfolioItem,
  hasSession,
  updatePortfolioItem,
  type EligibleProject,
  type PortfolioItem,
  type PortfolioMedia,
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
import {
  completePortfolioMediaUpload,
  discardPortfolioMediaUploads,
  listPortfolioMediaUploads,
  stagePortfolioMediaUpload,
  type PortfolioMediaOutboxEntry,
} from '../../services/portfolioMediaOutbox';

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
  media: PortfolioMedia[];
  uploadState?: PortfolioItem['uploadState'];
  uploadedMediaCount?: number;
  expectedMediaCount?: number;
};

const fallbackCover = require('../../assets/images/courseSliderBackground.jpg');

const responseStatus = (error: unknown) =>
  Number(
    (error as {status?: unknown; response?: {status?: unknown}})?.status ??
      (error as {response?: {status?: unknown}})?.response?.status ??
      0,
  );

const portfolioMediaRequestId = (projectRequestId: string, index: number) => {
  const hex = projectRequestId.replace(/-/g, '').toLowerCase();
  if (!/^[0-9a-f]{32}$/.test(hex)) return secureRandomUuid();
  const derived = `${hex.slice(0, 30)}${Math.max(0, index)
    .toString(16)
    .padStart(2, '0')
    .slice(-2)}`;
  return `${derived.slice(0, 8)}-${derived.slice(8, 12)}-${derived.slice(
    12,
    16,
  )}-${derived.slice(16, 20)}-${derived.slice(20)}`;
};

const remoteProject = (item: PortfolioItem): Project => ({
  id: item.id,
  title: item.title,
  summary: item.summary,
  cover: item.coverUri ? {uri: item.coverUri} : fallbackCover,
  skills: item.skills,
  source: 'remote',
  courseName: item.courseName,
  courseId: item.courseId,
  sourceProjectId: item.sourceProjectId,
  media: item.media,
  uploadState: item.uploadState,
  uploadedMediaCount: item.uploadedMediaCount,
  expectedMediaCount: item.expectedMediaCount,
});

const initialProjects: Project[] = [
  {
    id: 'portfolio-marketplace',
    title: 'تجربة متجر محلي',
    summary: 'تصميم رحلة شراء أبسط لمتجر يربط الحرفيين بعملائهم',
    cover: require('../../assets/images/demo-course/portfolio-marketplace.jpg'),
    skills: ['UX', 'واجهات', 'نموذج أولي'],
    source: 'demo',
    media: [],
  },
  {
    id: 'portfolio-finance',
    title: 'لوحة متابعة مالية',
    summary: 'واجهة واضحة تساعد المستقل على فهم دخله ومصروفاته بسرعة',
    cover: require('../../assets/images/demo-course/portfolio-finance.jpg'),
    skills: ['بحث', 'نظم تصميم', 'Figma'],
    source: 'demo',
    media: [],
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
  const [previewMedia, setPreviewMedia] = useState<PortfolioMedia | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [editing, setEditing] = useState(false);
  const [editTitle, setEditTitle] = useState('');
  const [editSummary, setEditSummary] = useState('');
  const [adding, setAdding] = useState(false);
  const [saving, setSaving] = useState(false);
  const [uploadProgress, setUploadProgress] = useState<{
    completed: number;
    total: number;
  } | null>(null);
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
  const [draftMediaAssets, setDraftMediaAssets] = useState<
    Array<{uri: string; type?: string; fileName?: string; size?: number}>
  >([]);
  const [draftReady, setDraftReady] = useState(false);
  const [clientRequestId, setClientRequestId] = useState(secureRandomUuid);
  const [draftSaveError, setDraftSaveError] = useState(false);
  const mountedRef = useRef(true);
  const loadGenerationRef = useRef(0);
  const eligibleGenerationRef = useRef(0);
  const addFlightRef = useRef(false);
  const deleteFlightRef = useRef(false);
  const detailGenerationRef = useRef(0);
  const mediaFlightRef = useRef(false);
  const pickerFlightRef = useRef(false);
  const pickerGenerationRef = useRef(0);
  const mediaReplayProjectsRef = useRef(new Set<string>());
  const draftSnapshotRef = useRef({
    clientRequestId,
    cover: draftCoverAsset,
    media: draftMediaAssets,
    selectedSource: selectedSourceProject || undefined,
    summary: draftSummary,
    title: draftTitle,
    updatedAt: Date.now(),
  });
  draftSnapshotRef.current = {
    clientRequestId,
    cover: draftCoverAsset,
    media: draftMediaAssets,
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
      detailGenerationRef.current += 1;
      pickerGenerationRef.current += 1;
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
        const restoredMedia = draft.media?.length
          ? draft.media
          : draft.cover
          ? [draft.cover]
          : [];
        setDraftMediaAssets(restoredMedia);
        const restoredCover = restoredMedia.find(
          file =>
            !String(file.type || '')
              .toLowerCase()
              .startsWith('video/'),
        );
        setDraftCover(restoredCover ? {uri: restoredCover.uri} : null);
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
        media: draftMediaAssets,
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
    draftMediaAssets,
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
        const failedProjects = new Set<string>();
        for (const entry of await listPortfolioMediaUploads()) {
          if (failedProjects.has(entry.projectId)) continue;
          try {
            await appendPortfolioMedia(
              entry.projectId,
              entry.file,
              entry.clientRequestId,
            );
            await completePortfolioMediaUpload(entry);
          } catch {
            failedProjects.add(entry.projectId);
          }
        }
        const items = await getPortfolio();
        if (!isCurrent()) return;
        setProjects(items.map(remoteProject));
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
          .map(item => ({
            ...item,
            media: item.media || [],
            source: 'local' as const,
          })),
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
    const generation = ++pickerGenerationRef.current;
    try {
      const result = await launchImageLibrary({
        mediaType: 'mixed' as MediaType,
        selectionLimit: 12,
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
      const assets = (result.assets || []).filter(asset => asset.uri);
      if (!assets.length) return;
      const cached = [] as Array<{
        uri: string;
        type?: string;
        fileName?: string;
        size?: number;
      }>;
      try {
        for (const asset of assets) {
          cached.push(
            await cacheLearnerDraftFile(
              'portfolio',
              {
                uri: String(asset.uri),
                type: asset.type,
                fileName: asset.fileName,
                size: asset.fileSize,
              },
              50 * 1024 * 1024,
            ),
          );
          if (
            !mountedRef.current ||
            pickerGenerationRef.current !== generation
          ) {
            await Promise.all(cached.map(removeLearnerDraftFile));
            return;
          }
        }
      } catch (error) {
        await Promise.all(cached.map(removeLearnerDraftFile));
        throw error;
      }
      if (!mountedRef.current || pickerGenerationRef.current !== generation) {
        await Promise.all(cached.map(removeLearnerDraftFile));
        return;
      }
      const previous = draftMediaAssets;
      const cover = cached.find(
        file =>
          !String(file.type || '')
            .toLowerCase()
            .startsWith('video/'),
      );
      changeDraft(() => {
        setDraftMediaAssets(cached);
        setDraftCover(cover ? {uri: cover.uri} : null);
        setDraftCoverAsset(cover);
      });
      await Promise.all(previous.map(removeLearnerDraftFile));
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
    const previousMedia = draftMediaAssets;
    setDraftTitle('');
    setDraftSummary('');
    setDraftCover(null);
    setDraftCoverAsset(undefined);
    setDraftMediaAssets([]);
    setSelectedSourceProject(null);
    setClientRequestId(secureRandomUuid());
    setDraftSaveError(false);
    void Promise.all([
      clearPortfolioEditorDraft(),
      removeLearnerDraftFile(previous),
      ...previousMedia.map(removeLearnerDraftFile),
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
    pickerGenerationRef.current += 1;
    setEligibleLoading(false);
    setAdding(false);
  };

  const chooseSourceProject = (project: EligibleProject) => {
    const previous = draftCoverAsset;
    const previousMedia = draftMediaAssets;
    changeDraft(() => {
      setSelectedSourceProject(project);
      setDraftTitle(project.title);
      setDraftSummary(project.summary);
      setDraftCover(project.courseImage ? {uri: project.courseImage} : null);
      // Remote course covers are visual previews, not local upload assets.
      setDraftCoverAsset(undefined);
      setDraftMediaAssets([]);
    });
    void Promise.all([
      removeLearnerDraftFile(previous),
      ...previousMedia.map(removeLearnerDraftFile),
    ]);
  };

  const addProject = async () => {
    if (!draftTitle.trim() || saving || addFlightRef.current) return;
    addFlightRef.current = true;
    setSaving(true);
    let remoteProjectCreated = false;
    try {
      if (serverSession) {
        const item = await createPortfolioItem({
          title: draftTitle.trim(),
          summary: draftSummary.trim(),
          sourceProjectId: selectedSourceProject?.projectId,
          courseId: selectedSourceProject?.courseId,
          clientRequestId,
          expectedMediaCount: draftMediaAssets.length,
        });
        remoteProjectCreated = true;
        if (mountedRef.current) {
          setProjects(current => [
            remoteProject(item),
            ...current.filter(project => project.id !== item.id),
          ]);
        }
        if (selectedSourceProject && mountedRef.current) {
          setEligibleProjects(current =>
            current.filter(
              candidate =>
                candidate.projectId !== selectedSourceProject.projectId,
            ),
          );
        }

        const staged: PortfolioMediaOutboxEntry[] = [];
        for (const [index, source] of draftMediaAssets.entries()) {
          const cached = await cacheLearnerDraftFile(
            'portfolio',
            source,
            50 * 1024 * 1024,
          );
          try {
            const entry: PortfolioMediaOutboxEntry = {
              projectId: item.id,
              clientRequestId: portfolioMediaRequestId(clientRequestId, index),
              file: cached,
              createdAt: Date.now() + index,
            };
            staged.push(await stagePortfolioMediaUpload(entry));
          } catch (error) {
            await removeLearnerDraftFile(cached);
            throw error;
          }
        }

        if (mountedRef.current && staged.length) {
          setUploadProgress({completed: 0, total: staged.length});
        }
        for (const [index, entry] of staged.entries()) {
          const uploaded = await uploadStagedMedia(
            entry,
            detailGenerationRef.current,
          );
          if (!uploaded) {
            if (mountedRef.current) {
              Alert.alert(
                'لم يكتمل الرفع',
                'أُضيف المشروع واحتفظنا بالملفات\nسنكملها عند فتحه',
              );
            }
            break;
          }
          if (mountedRef.current) {
            setUploadProgress({completed: index + 1, total: staged.length});
          }
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
                media: [],
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
        if (remoteProjectCreated) {
          Alert.alert(
            'حُفظ المشروع كمسودة',
            'تعذّر تجهيز بعض الملفات\nستبقى محفوظة لإكمال الرفع',
          );
        } else {
          Alert.alert(
            'تعذّر إضافة المشروع',
            learnerErrorMessage(error, 'لم يُضف المشروع\nحاول مرة أخرى'),
          );
        }
      }
    } finally {
      addFlightRef.current = false;
      if (mountedRef.current) {
        setSaving(false);
        setUploadProgress(null);
      }
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
        await discardPortfolioMediaUploads(selected.id).catch(() => undefined);
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

  const appendMediaToProject = (
    project: Project,
    uploaded: PortfolioMedia,
  ): Project => {
    if (project.media.some(media => media.id === uploaded.id)) return project;
    const next = {...project, media: [...project.media, uploaded]};
    const firstImage = next.media.find(
      media => media.type === 'image' && media.uri,
    );
    if (firstImage?.uri) next.cover = {uri: firstImage.uri};
    return next;
  };

  const applyUploadedMedia = (
    projectId: string,
    uploaded: PortfolioMedia,
    generation: number,
  ) => {
    if (!mountedRef.current) return;
    setProjects(current =>
      current.map(project =>
        project.id === projectId
          ? appendMediaToProject(project, uploaded)
          : project,
      ),
    );
    if (detailGenerationRef.current !== generation) return;
    setSelected(current =>
      current?.id === projectId
        ? appendMediaToProject(current, uploaded)
        : current,
    );
    if (uploaded.uri) setPreviewMedia(current => current || uploaded);
  };

  async function uploadStagedMedia(
    entry: PortfolioMediaOutboxEntry,
    generation: number,
  ): Promise<boolean> {
    try {
      const uploaded = await appendPortfolioMedia(
        entry.projectId,
        entry.file,
        entry.clientRequestId,
      );
      await completePortfolioMediaUpload(entry);
      applyUploadedMedia(entry.projectId, uploaded, generation);
      return true;
    } catch (error: unknown) {
      if (responseStatus(error) === 404) {
        await discardPortfolioMediaUploads(entry.projectId);
      }
      return false;
    }
  }

  const replayPendingMedia = async (
    projectId: string,
    generation: number,
  ): Promise<void> => {
    if (mediaReplayProjectsRef.current.has(projectId)) return;
    mediaReplayProjectsRef.current.add(projectId);
    try {
      const pending = await listPortfolioMediaUploads(projectId);
      for (const entry of pending) {
        if (!(await uploadStagedMedia(entry, generation))) break;
      }
    } finally {
      mediaReplayProjectsRef.current.delete(projectId);
    }
  };

  const openProject = (project: Project) => {
    setSelected(project);
    setPreviewMedia(project.media.find(media => media.uri) || null);
    setEditing(false);
    if (project.source !== 'remote') return;
    const generation = ++detailGenerationRef.current;
    setDetailLoading(true);
    void replayPendingMedia(project.id, generation)
      .catch(() => undefined)
      .then(() => getPortfolioItem(project.id))
      .then(item => {
        if (!mountedRef.current || detailGenerationRef.current !== generation)
          return;
        const next = remoteProject(item);
        setSelected(next);
        setPreviewMedia(next.media.find(media => media.uri) || null);
        setProjects(current =>
          current.map(candidate =>
            candidate.id === next.id ? next : candidate,
          ),
        );
      })
      .catch(() => undefined)
      .finally(() => {
        if (mountedRef.current && detailGenerationRef.current === generation) {
          setDetailLoading(false);
        }
      });
  };

  const closeProject = () => {
    detailGenerationRef.current += 1;
    setDetailLoading(false);
    setEditing(false);
    setSelected(null);
    setPreviewMedia(null);
  };

  const beginEdit = () => {
    if (!selected || selected.source !== 'remote') return;
    setEditTitle(selected.title);
    setEditSummary(selected.summary);
    setEditing(true);
  };

  const saveProjectEdits = async () => {
    if (
      !selected ||
      selected.source !== 'remote' ||
      !editTitle.trim() ||
      saving
    )
      return;
    const projectId = selected.id;
    const generation = detailGenerationRef.current;
    setSaving(true);
    try {
      const item = await updatePortfolioItem(projectId, {
        title: editTitle.trim(),
        summary: editSummary.trim(),
      });
      if (!mountedRef.current) return;
      const next = remoteProject(item);
      setProjects(current =>
        current.map(candidate => (candidate.id === next.id ? next : candidate)),
      );
      if (detailGenerationRef.current === generation) {
        setSelected(current => (current?.id === projectId ? next : current));
        setEditing(false);
      }
    } catch (error: unknown) {
      if (mountedRef.current) {
        Alert.alert(
          'تعذّر حفظ التعديل',
          learnerErrorMessage(error, 'مشروعك لم يتغير\nحاول مرة أخرى'),
        );
      }
    } finally {
      if (mountedRef.current) setSaving(false);
    }
  };

  const finalizeSelectedProject = async () => {
    if (!selected || selected.source !== 'remote' || saving) return;
    const projectId = selected.id;
    const generation = detailGenerationRef.current;
    setSaving(true);
    try {
      const item = await finalizePortfolioItem(projectId);
      await discardPortfolioMediaUploads(projectId).catch(() => undefined);
      if (!mountedRef.current) return;
      const next = remoteProject(item);
      setProjects(current =>
        current.map(project => (project.id === projectId ? next : project)),
      );
      if (detailGenerationRef.current === generation) setSelected(next);
    } catch (error: unknown) {
      if (mountedRef.current) {
        Alert.alert(
          'تعذّر نشر المشروع',
          learnerErrorMessage(error, 'حاول مرة أخرى'),
        );
      }
    } finally {
      if (mountedRef.current) setSaving(false);
    }
  };

  const addSelectedMedia = async () => {
    if (
      !selected ||
      selected.source !== 'remote' ||
      saving ||
      mediaFlightRef.current
    )
      return;
    mediaFlightRef.current = true;
    const projectId = selected.id;
    const generation = detailGenerationRef.current;
    try {
      const result = await launchImageLibrary({
        mediaType: 'mixed' as MediaType,
        selectionLimit: Math.max(1, 12 - selected.media.length),
        quality: 0.8,
      });
      if (result.errorCode) {
        showMediaPickerFailure(result.errorCode);
        return;
      }
      const assets = (result.assets || []).filter(asset => asset.uri);
      if (!assets.length) return;
      setSaving(true);
      const staged: PortfolioMediaOutboxEntry[] = [];
      for (const asset of assets) {
        const cached = await cacheLearnerDraftFile(
          'portfolio',
          {
            uri: String(asset.uri),
            type: asset.type,
            fileName: asset.fileName,
            size: asset.fileSize,
          },
          50 * 1024 * 1024,
        );
        try {
          const entry: PortfolioMediaOutboxEntry = {
            projectId,
            clientRequestId: secureRandomUuid(),
            file: cached,
            createdAt: Date.now(),
          };
          staged.push(await stagePortfolioMediaUpload(entry));
        } catch (error) {
          await removeLearnerDraftFile(cached);
          throw error;
        }
      }
      for (const entry of staged) {
        if (!(await uploadStagedMedia(entry, generation))) {
          if (mountedRef.current) {
            Alert.alert(
              'لم يكتمل الرفع',
              'احتفظنا بالملفات وسنكملها عند فتح المشروع',
            );
          }
          break;
        }
      }
    } catch (error: unknown) {
      if (mountedRef.current) {
        Alert.alert(
          'تعذّر رفع الملف',
          learnerErrorMessage(error, 'حاول مرة أخرى'),
        );
      }
    } finally {
      mediaFlightRef.current = false;
      if (mountedRef.current) setSaving(false);
    }
  };

  const removeSelectedMedia = (media: PortfolioMedia) => {
    if (!selected || selected.source !== 'remote' || saving) return;
    Alert.alert('حذف الملف', 'سيُحذف من المشروع', [
      {text: 'إلغاء', style: 'cancel'},
      {
        text: 'حذف',
        style: 'destructive',
        onPress: () => {
          const projectId = selected.id;
          const generation = detailGenerationRef.current;
          setSaving(true);
          void deletePortfolioMedia(projectId, media.id)
            .then(() => {
              if (!mountedRef.current) return;
              const withoutMedia = (project: Project): Project => {
                const remaining = project.media.filter(
                  candidate => candidate.id !== media.id,
                );
                const firstImage = remaining.find(
                  candidate => candidate.type === 'image' && candidate.uri,
                );
                return {
                  ...project,
                  media: remaining,
                  cover: firstImage?.uri
                    ? {uri: firstImage.uri}
                    : fallbackCover,
                };
              };
              setProjects(current =>
                current.map(project =>
                  project.id === projectId ? withoutMedia(project) : project,
                ),
              );
              if (detailGenerationRef.current === generation) {
                setSelected(current =>
                  current?.id === projectId ? withoutMedia(current) : current,
                );
                setPreviewMedia(current =>
                  current?.id === media.id
                    ? selected.media.find(
                        candidate => candidate.id !== media.id && candidate.uri,
                      ) || null
                    : current,
                );
              }
            })
            .catch(error => {
              if (mountedRef.current) {
                Alert.alert(
                  'تعذّر حذف الملف',
                  learnerErrorMessage(error, 'حاول مرة أخرى'),
                );
              }
            })
            .finally(() => {
              if (mountedRef.current) setSaving(false);
            });
        },
      },
    ]);
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
              returnTo: {name: 'Profile', params: {tab: 'portfolio'}},
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
                onPress={() => openProject(project)}
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
        onRequestClose={closeProject}
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
                  {previewMedia?.type === 'video' && previewMedia.uri ? (
                    <Video
                      controls
                      paused={!appActive}
                      resizeMode="contain"
                      source={{uri: previewMedia.uri}}
                      style={styles.detailCover}
                    />
                  ) : (
                    <Image
                      accessible={false}
                      importantForAccessibility="no"
                      source={
                        previewMedia?.type === 'image' && previewMedia.uri
                          ? {uri: previewMedia.uri}
                          : selected.cover
                      }
                      style={styles.detailCover}
                    />
                  )}
                  {detailLoading && (
                    <ActivityIndicator
                      color={Palette.primary}
                      style={styles.detailLoader}
                    />
                  )}
                  {!!selected.media.length && (
                    <ScrollView
                      horizontal
                      contentContainerStyle={styles.mediaStrip}
                      showsHorizontalScrollIndicator={false}>
                      {selected.media.map(media => (
                        <View key={media.id} style={styles.mediaThumbGroup}>
                          <Pressable
                            accessibilityLabel={
                              media.type === 'video'
                                ? 'عرض الفيديو'
                                : 'عرض الصورة'
                            }
                            accessibilityRole="button"
                            onPress={() => media.uri && setPreviewMedia(media)}
                            style={[
                              styles.mediaThumb,
                              previewMedia?.id === media.id &&
                                styles.mediaThumbActive,
                            ]}>
                            {media.type === 'image' && media.uri ? (
                              <Image
                                source={{uri: media.uri}}
                                style={styles.mediaThumbImage}
                              />
                            ) : (
                              <View style={styles.mediaThumbPlaceholder}>
                                <Text style={styles.mediaThumbLabel}>
                                  {media.status === 'processing'
                                    ? 'يُجهز'
                                    : media.type === 'video'
                                    ? 'فيديو'
                                    : 'ملف'}
                                </Text>
                              </View>
                            )}
                          </Pressable>
                          {selected.source === 'remote' && (
                            <Pressable
                              accessibilityLabel="حذف الملف"
                              accessibilityRole="button"
                              disabled={saving}
                              onPress={() => removeSelectedMedia(media)}
                              style={styles.mediaDelete}>
                              <Text style={styles.mediaDeleteText}>حذف</Text>
                            </Pressable>
                          )}
                        </View>
                      ))}
                    </ScrollView>
                  )}
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
                    {editing ? (
                      <>
                        <Text style={styles.fieldLabel}>اسم المشروع</Text>
                        <TextInput
                          accessibilityLabel="اسم المشروع"
                          onChangeText={setEditTitle}
                          style={styles.input}
                          value={editTitle}
                        />
                        <Text style={styles.fieldLabel}>وصف مختصر</Text>
                        <TextInput
                          accessibilityLabel="وصف المشروع"
                          multiline
                          onChangeText={setEditSummary}
                          style={[styles.input, styles.multiline]}
                          value={editSummary}
                        />
                      </>
                    ) : (
                      <>
                        <Text style={styles.detailTitle}>
                          {formatArabicDisplayText(selected.title)}
                        </Text>
                        <Text style={styles.detailSummary}>
                          {formatArabicDisplayText(selected.summary)}
                        </Text>
                      </>
                    )}
                    <View style={styles.skillsRow}>
                      {selected.skills.map(skill => (
                        <MetaPill key={skill} label={skill} />
                      ))}
                    </View>
                    {selected.source === 'remote' && editing ? (
                      <>
                        <Button
                          disable={!editTitle.trim() || saving}
                          loader={saving}
                          onPress={saveProjectEdits}
                          title="حفظ التعديل"
                        />
                        <Button
                          disable={saving}
                          onPress={() => setEditing(false)}
                          title="إلغاء"
                          useGradient={false}
                        />
                      </>
                    ) : (
                      <>
                        {selected.source === 'remote' && (
                          <>
                            <Button
                              disable={saving || selected.media.length >= 12}
                              loader={saving}
                              onPress={addSelectedMedia}
                              title="إضافة صور أو فيديو"
                              useGradient={false}
                            />
                            {selected.uploadState !== 'ready' &&
                            (selected.uploadedMediaCount ||
                              selected.media.length) > 0 ? (
                              <Button
                                disable={saving}
                                onPress={finalizeSelectedProject}
                                title="نشر الملفات المكتملة"
                                useGradient={false}
                              />
                            ) : null}
                            <Button
                              disable={saving}
                              onPress={beginEdit}
                              title="تعديل المشروع"
                              useGradient={false}
                            />
                          </>
                        )}
                        <Button
                          onPress={closeProject}
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
                      </>
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
                          const previous = draftMediaAssets;
                          changeDraft(() => {
                            setSelectedSourceProject(null);
                            setDraftCover(null);
                            setDraftCoverAsset(undefined);
                            setDraftMediaAssets([]);
                          });
                          void Promise.all(
                            previous.map(removeLearnerDraftFile),
                          );
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
                  accessibilityLabel="اختيار صور وفيديوهات المشروع"
                  onPress={pickCover}
                  style={styles.coverPicker}>
                  {draftMediaAssets.length ? (
                    <View style={styles.pickedMediaPreview}>
                      {draftCover ? (
                        <Image source={draftCover} style={styles.pickedCover} />
                      ) : (
                        <View style={styles.pickedCoverFallback}>
                          <Text style={styles.coverPickerLabel}>فيديو</Text>
                        </View>
                      )}
                      <Text style={styles.pickedMediaCount}>
                        {draftMediaAssets.length} ملفات
                      </Text>
                    </View>
                  ) : (
                    <Text style={styles.coverPickerLabel}>
                      إضافة صور أو فيديوهات
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
                  <View style={styles.savingIndicator}>
                    <ActivityIndicator color={Palette.primary} />
                    {uploadProgress ? (
                      <Text style={styles.uploadProgressText}>
                        رفع {uploadProgress.completed} من {uploadProgress.total}
                      </Text>
                    ) : null}
                  </View>
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
  detailLoader: {position: 'absolute', top: Spacing.lg, alignSelf: 'center'},
  mediaStrip: {
    direction: 'rtl',
    gap: Spacing.sm,
    paddingHorizontal: Spacing.xl,
    paddingVertical: Spacing.md,
  },
  mediaThumbGroup: {alignItems: 'center', gap: Spacing.xs},
  mediaThumb: {
    width: 76,
    height: 76,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    overflow: 'hidden',
    backgroundColor: Palette.surface,
  },
  mediaThumbActive: {borderColor: Palette.primary, borderWidth: 2},
  mediaThumbImage: {width: '100%', height: '100%', resizeMode: 'cover'},
  mediaThumbPlaceholder: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
  },
  mediaThumbLabel: {...Type.caption, color: Palette.textMuted},
  mediaDelete: {paddingHorizontal: Spacing.sm, paddingVertical: Spacing.xs},
  mediaDeleteText: {...Type.caption, color: Palette.danger},
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
  pickedMediaPreview: {width: '100%', position: 'relative'},
  pickedCover: {width: '100%', height: 160, resizeMode: 'cover'},
  pickedCoverFallback: {
    height: 160,
    alignItems: 'center',
    justifyContent: 'center',
  },
  pickedMediaCount: {
    ...Type.caption,
    color: Palette.text,
    position: 'absolute',
    bottom: Spacing.sm,
    right: Spacing.sm,
    backgroundColor: Palette.overlay,
    borderRadius: Radius.pill,
    paddingHorizontal: Spacing.sm,
    paddingVertical: Spacing.xs,
  },
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
  savingIndicator: {
    marginTop: Spacing.sm,
    alignItems: 'center',
    gap: Spacing.xs,
  },
  uploadProgressText: {...Type.caption, color: Palette.textMuted},
  draftError: {
    ...Type.caption,
    ...textDirection,
    color: Palette.danger,
    marginTop: Spacing.sm,
  },
  pressed: {opacity: 0.8, transform: [{scale: 0.99}]},
});
