import React, {useCallback, useEffect, useRef, useState} from 'react';
import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import {
  ActivityIndicator,
  Alert,
  Image,
  KeyboardAvoidingView,
  NativeModules,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import * as DocumentPicker from 'expo-document-picker';
import Svg, {Path} from 'react-native-svg';
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import {rtlRowStyle, textDirection} from '../../constants/designSystem';
import {Fonts} from '../../constants/styleConstants';
import {openCourseAttachment} from './attachmentActions';
import {
  CourseProject,
  ProjectFeedbackThread,
  SelectedProjectFile,
  ChatAttachmentDraft,
} from './types';
import type {ProjectSubmissionOutcome} from './courseLearningApi';
import {
  loadProjectResolution,
  sendProjectFeedbackMessage,
  uploadProjectFeedbackAttachment,
  openProjectInputAttachment,
} from './courseLearningApi';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';
import {secureRandomUuid} from '../../utils/secureRandom';
import {
  PROJECT_SUBMISSION_FORMATS_LABEL,
  PROJECT_SUBMISSION_MAX_LABEL,
  validateProjectFile,
} from '../../config/projects';
import {learnerErrorMessage} from '../../utils/errorPayload';
import {cleanUnicodeText, truncateGraphemes} from '../../utils/unicodeText';
import {
  cacheProjectDraftFile,
  cacheProjectFeedbackFile,
  clearProjectFeedbackDraft,
  clearProjectSubmissionDraft,
  loadProjectFeedbackDraft,
  loadProjectSubmissionDraft,
  saveProjectFeedbackDraft,
  saveProjectSubmissionDraft,
} from '../../services/projectSubmissionDraft';
import {removeLearnerDraftFile} from '../../services/learnerDraftFiles';
import {useAppActiveState} from '../../hooks/useAppActiveState';
import {showMediaPickerFailure} from '../../services/mediaPickerErrors';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';

interface ProjectTransitionProps {
  active: boolean;
  project: CourseProject;
  moduleTitle: string;
  width: number;
  height: number;
  topInset?: number;
  bottomInset?: number;
  onSubmit: (
    files: SelectedProjectFile[],
    note?: string,
  ) => Promise<ProjectSubmissionOutcome>;
  onContinue?: () => void;
}

const UploadIcon = () => (
  <Svg width={27} height={27} viewBox="0 0 28 28">
    <Path
      d="M14 19V5m0 0L8.8 10.2M14 5l5.2 5.2M5.5 18.2v3.3c0 1.1.9 2 2 2h13c1.1 0 2-.9 2-2v-3.3"
      fill="none"
      stroke="#fff"
      strokeWidth={1.9}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const pickOwnedMediaFiles = async (
  mimeTypes: string[],
): Promise<{
  files: SelectedProjectFile[];
  ownerBoundary: AccountSessionBoundary;
}> => {
  const ownerBoundary = await captureAccountSessionBoundary();
  assertAccountSessionBoundary(ownerBoundary);
  try {
    const response = await DocumentPicker.getDocumentAsync({
      type: mimeTypes.length ? mimeTypes : [
        'image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      ],
      multiple: true,
      copyToCacheDirectory: true,
    });
    assertAccountSessionBoundary(ownerBoundary);
    return {
      files: response.canceled
        ? []
        : response.assets.filter(asset => asset.uri).map(asset => ({
            uri: asset.uri,
            name: asset.name || `rokn-project-${Date.now()}`,
            type: asset.mimeType || 'application/octet-stream',
            size: asset.size,
          })),
      ownerBoundary,
    };
  } catch (error) {
    if (
      error instanceof Error &&
      error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
    )
      throw error;
    showMediaPickerFailure('document_picker_failed');
    return {files: [], ownerBoundary};
  }
};

const pickMediaFiles = async (
  mimeTypes: string[],
): Promise<SelectedProjectFile[]> =>
  (await pickOwnedMediaFiles(mimeTypes)).files;

const ProjectTransition = ({
  active,
  project,
  moduleTitle,
  width,
  height,
  topInset = 0,
  bottomInset = 0,
  onSubmit,
  onContinue,
}: ProjectTransitionProps) => {
  const navigation = useNavigation<RootNavigation>();
  const appIsActive = useAppActiveState();
  const pickerFlightRef = useRef(false);
  const [selectedFiles, setSelectedFiles] = useState<SelectedProjectFile[]>([]);
  const [status, setStatus] = useState(project.status);
  const [submissionNote, setSubmissionNote] = useState('');
  const [submissionDraftReady, setSubmissionDraftReady] = useState(false);
  const [submissionDraftSaveError, setSubmissionDraftSaveError] =
    useState(false);
  const [syncNote, setSyncNote] = useState('');
  const [feedbackThread, setFeedbackThread] = useState<
    ProjectFeedbackThread | undefined
  >(project.feedbackThread);
  const [feedbackDraft, setFeedbackDraft] = useState('');
  const [feedbackAttachments, setFeedbackAttachments] = useState<ChatAttachmentDraft[]>([]);
  const [feedbackDraftReady, setFeedbackDraftReady] = useState(false);
  const normalizedSubmissionNote = cleanUnicodeText(submissionNote);
  const normalizedFeedbackDraft = cleanUnicodeText(feedbackDraft);
  const [feedbackSending, setFeedbackSending] = useState(false);
  const [feedbackError, setFeedbackError] = useState('');
  const [submissionSending, setSubmissionSending] = useState(false);
  const feedbackPending = Boolean(
    feedbackThread?.messages.some(message =>
      ['queued', 'sent', 'streaming'].includes(message.status),
    ),
  );
  const submissionInFlightRef = useRef(false);
  const feedbackRequestRef = useRef<{fingerprint: string; id: string} | null>(null);
  const feedbackSendFlightRef = useRef<symbol | null>(null);
  const feedbackPickerFlightRef = useRef<symbol | null>(null);
  const feedbackGenerationRef = useRef(0);
  const draftGenerationRef = useRef(0);
  const projectGenerationRef = useRef(0);
  const activeProjectIdRef = useRef(project.id);
  const activeFeedbackThreadIdRef = useRef<string | null>(
    project.feedbackThread?.id || null,
  );
  const submissionDraftSnapshotRef = useRef({
    files: selectedFiles,
    note: submissionNote,
  });
  submissionDraftSnapshotRef.current = {
    files: selectedFiles,
    note: submissionNote,
  };

  if (activeProjectIdRef.current !== project.id) {
    activeProjectIdRef.current = project.id;
    projectGenerationRef.current += 1;
  }
  activeFeedbackThreadIdRef.current = feedbackThread?.id || null;

  const ownsProject = useCallback(
    (projectId: string, generation: number) =>
      activeProjectIdRef.current === projectId &&
      projectGenerationRef.current === generation,
    [],
  );

  useEffect(() => {
    setStatus(project.status);
    if (project.status !== 'reviewing') {
      setSyncNote('');
    }
  }, [project.id, project.status]);

  useEffect(() => {
    const generation = ++draftGenerationRef.current;
    setSubmissionDraftReady(false);
    setSubmissionDraftSaveError(false);
    setSelectedFiles([]);
    setSubmissionNote('');
    setSubmissionSending(false);
    submissionInFlightRef.current = false;
    pickerFlightRef.current = false;
    if (project.status === 'passed') {
      setSelectedFiles([]);
      setSubmissionNote('');
      void clearProjectSubmissionDraft(project.id);
      setSubmissionDraftReady(true);
      return;
    }
    void loadProjectSubmissionDraft(project.id)
      .then(draft => {
        if (generation !== draftGenerationRef.current || !draft) return;
        setSelectedFiles(draft.files || []);
        setSubmissionNote(draft.note);
      })
      .catch(() => {
        if (generation === draftGenerationRef.current) {
          setSubmissionDraftSaveError(true);
        }
      })
      .finally(() => {
        if (generation === draftGenerationRef.current) {
          setSubmissionDraftReady(true);
        }
      });
    return () => {
      draftGenerationRef.current += 1;
    };
  }, [project.id, project.status]);

  useEffect(() => {
    if (project.status === 'passed' || !submissionDraftReady) return;
    const projectId = project.id;
    const projectGeneration = projectGenerationRef.current;
    const timer = setTimeout(() => {
      void saveProjectSubmissionDraft(projectId, {
        files: selectedFiles,
        note: submissionNote,
        updatedAt: Date.now(),
      })
        .then(() => {
          if (ownsProject(projectId, projectGeneration)) {
            setSubmissionDraftSaveError(false);
          }
        })
        .catch(() => {
          if (ownsProject(projectId, projectGeneration)) {
            setSubmissionDraftSaveError(true);
          }
        });
    }, 250);
    return () => clearTimeout(timer);
  }, [
    project.id,
    project.status,
    selectedFiles,
    submissionDraftReady,
    submissionNote,
    ownsProject,
  ]);

  useEffect(() => {
    if (
      appIsActive ||
      project.status === 'passed' ||
      !submissionDraftReady
    ) {
      return;
    }
    const projectId = project.id;
    const projectGeneration = projectGenerationRef.current;
    void saveProjectSubmissionDraft(projectId, {
      ...submissionDraftSnapshotRef.current,
      updatedAt: Date.now(),
    }).catch(() => {
      if (ownsProject(projectId, projectGeneration)) {
        setSubmissionDraftSaveError(true);
      }
    });
  }, [
    appIsActive,
    ownsProject,
    project.id,
    project.status,
    submissionDraftReady,
  ]);

  useEffect(() => {
    feedbackGenerationRef.current += 1;
    feedbackRequestRef.current = null;
    feedbackSendFlightRef.current = null;
    feedbackPickerFlightRef.current = null;
    setFeedbackSending(false);
    setFeedbackError('');
    setFeedbackDraft('');
    setFeedbackAttachments([]);
    setFeedbackDraftReady(false);
    return () => {
      feedbackGenerationRef.current += 1;
      feedbackPickerFlightRef.current = null;
    };
  }, [feedbackThread?.id, project.id]);

  useEffect(() => {
    setFeedbackThread(project.feedbackThread);
  }, [project.feedbackThread, project.id]);

  useEffect(() => {
    const threadId = feedbackThread?.id;
    if (!threadId) return;
    let cancelled = false;
    setFeedbackDraftReady(false);
    void loadProjectFeedbackDraft(threadId).then(draft => {
      if (cancelled || !draft) return;
      setFeedbackDraft(draft.text);
      setFeedbackAttachments(draft.attachments);
      if (draft.requestId && draft.fingerprint) {
        feedbackRequestRef.current = {id: draft.requestId, fingerprint: draft.fingerprint};
      }
    }).finally(() => {
      if (!cancelled) setFeedbackDraftReady(true);
    });
    return () => { cancelled = true; };
  }, [feedbackThread?.id]);

  useEffect(() => {
    const threadId = feedbackThread?.id;
    if (!threadId || !feedbackDraftReady) return;
    const timer = setTimeout(() => void saveProjectFeedbackDraft(threadId, {
      text: feedbackDraft,
      attachments: feedbackAttachments,
      requestId: feedbackRequestRef.current?.id,
      fingerprint: feedbackRequestRef.current?.fingerprint,
      updatedAt: Date.now(),
    }), 250);
    return () => clearTimeout(timer);
  }, [feedbackAttachments, feedbackDraft, feedbackDraftReady, feedbackThread?.id]);

  useEffect(() => {
    const threadId = feedbackThread?.id;
    const requestId = feedbackRequestRef.current?.id;
    if (!threadId || !requestId || !feedbackDraftReady) return;
    const serverOwnsRequest = feedbackThread.messages.some(message =>
      message.role === 'user' &&
      message.clientRequestId === requestId &&
      !['failed', 'cancelled'].includes(message.status),
    );
    if (!serverOwnsRequest) return;
    const localFiles = feedbackAttachments;
    feedbackRequestRef.current = null;
    setFeedbackDraft('');
    setFeedbackAttachments([]);
    void clearProjectFeedbackDraft(threadId, localFiles);
  }, [
    feedbackAttachments,
    feedbackDraftReady,
    feedbackThread?.id,
    feedbackThread?.messages,
  ]);

  useEffect(() => {
    const threadId = feedbackThread?.id;
    if (appIsActive || !threadId || !feedbackDraftReady) return;
    void saveProjectFeedbackDraft(threadId, {
      text: feedbackDraft, attachments: feedbackAttachments,
      requestId: feedbackRequestRef.current?.id,
      fingerprint: feedbackRequestRef.current?.fingerprint,
      updatedAt: Date.now(),
    });
  }, [appIsActive, feedbackAttachments, feedbackDraft, feedbackDraftReady, feedbackThread?.id]);

  useEffect(() => {
    if (!['passed', 'needs_retry'].includes(status) || !active || !appIsActive) return;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let missingAttempts = 0;
    let pollingAttempts = 0;
    const scheduleRefresh = (minimumMs: number) => {
      pollingAttempts += 1;
      const backoffMs = Math.min(
        12000,
        minimumMs * Math.pow(1.45, Math.min(8, pollingAttempts - 1)),
      );
      const jitterSeed = Array.from(project.id).reduce(
        (sum, character) => sum + character.charCodeAt(0),
        0,
      );
      const delay = Math.round(
        backoffMs * (0.85 + (jitterSeed % 31) / 100),
      );
      timer = setTimeout(() => void refresh(), delay);
    };

    const refresh = async () => {
      try {
        const resolution = await loadProjectResolution(project.id);
        const next = resolution.feedbackThread;
        if (cancelled) return;
        if (['passed', 'needs_retry'].includes(resolution.status)) {
          setStatus(resolution.status);
        }
        if (next) {
          setFeedbackThread(next);
          setFeedbackError('');
        } else {
          missingAttempts += 1;
        }
        const waiting = next?.messages.some(message =>
          ['queued', 'sent', 'streaming'].includes(message.status),
        );
        if (waiting || (!next && missingAttempts < 30)) {
          scheduleRefresh(2200);
        }
      } catch {
        if (!cancelled && feedbackThread?.id) {
          scheduleRefresh(3500);
        }
      }
    };
    void refresh();
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [
    active,
    appIsActive,
    feedbackPending,
    feedbackThread?.id,
    project.id,
    status,
  ]);

  const sendFeedback = async (
    text = feedbackDraft,
    clientRequestId?: string,
    forceNewRequest = false,
    files = feedbackAttachments,
  ) => {
    const value = cleanUnicodeText(text);
    const fingerprint = [value, ...files.map(file =>
      `${file.serverId || file.uploadId}:${file.name}:${file.size || 0}`)].join('|');
    if (
      !feedbackThread?.canReply ||
      (!value && files.length === 0) ||
      feedbackSending ||
      feedbackSendFlightRef.current ||
      feedbackPickerFlightRef.current
    )
      return;
    const flight = Symbol('project-feedback-send');
    const generation = feedbackGenerationRef.current;
    const projectId = project.id;
    const threadId = feedbackThread.id;
    const ownsFeedbackContext = () =>
      feedbackSendFlightRef.current === flight &&
      feedbackGenerationRef.current === generation &&
      activeProjectIdRef.current === projectId &&
      activeFeedbackThreadIdRef.current === threadId;
    feedbackSendFlightRef.current = flight;
    setFeedbackSending(true);
    setFeedbackError('');
    const requestId =
      clientRequestId ||
      (!forceNewRequest && feedbackRequestRef.current?.fingerprint === fingerprint
        ? feedbackRequestRef.current.id
        : secureRandomUuid());
    feedbackRequestRef.current = {fingerprint, id: requestId};
    try {
      const feedbackBoundary = await captureAccountSessionBoundary();
      const uploaded = await Promise.all(files.map(async file => ({
        ...file,
        serverId: file.serverId || await uploadProjectFeedbackAttachment(threadId, file),
      })));
      assertAccountSessionBoundary(feedbackBoundary);
      if (!ownsFeedbackContext()) return;
      const durableFingerprint = [value, ...uploaded.map(file =>
        `${file.serverId || file.uploadId}:${file.name}:${file.size || 0}`)].join('|');
      // Persist the server-owned ids before removing local copies. A process
      // death after upload can then resume the same logical message without
      // losing the attachment or uploading a second blob.
      await saveProjectFeedbackDraft(threadId, {
        text: value,
        attachments: uploaded,
        requestId,
        fingerprint: durableFingerprint,
        updatedAt: Date.now(),
      }, feedbackBoundary);
      assertAccountSessionBoundary(feedbackBoundary);
      if (!ownsFeedbackContext()) return;
      setFeedbackAttachments(uploaded);
      feedbackRequestRef.current = {
        id: requestId,
        fingerprint: durableFingerprint,
      };
      const next = await sendProjectFeedbackMessage(
        threadId,
        value,
        requestId,
        uploaded.map(file => file.serverId!).filter(Boolean),
      );
      assertAccountSessionBoundary(feedbackBoundary);
      if (!ownsFeedbackContext()) return;
      void clearProjectFeedbackDraft(
        threadId,
        uploaded,
        feedbackBoundary,
      ).catch(() => undefined);
      if (generation !== feedbackGenerationRef.current) return;
      // The server response is authoritative. Publish it before local cleanup:
      // a registry/AsyncStorage failure must never turn an accepted message
      // into a visible send failure or invite a second paid request.
      setFeedbackThread(next);
      setFeedbackDraft('');
      setFeedbackAttachments([]);
      feedbackRequestRef.current = null;
    } catch (error: unknown) {
      if (
        !ownsFeedbackContext() ||
        (error instanceof Error &&
          error.message === 'ACCOUNT_CHANGED_DURING_REQUEST')
      )
        return;
      setFeedbackError(
        learnerErrorMessage(error, 'لم تُرسل الرسالة\nحاول مرة أخرى'),
      );
    } finally {
      if (feedbackSendFlightRef.current === flight) {
        feedbackSendFlightRef.current = null;
        if (
          feedbackGenerationRef.current === generation &&
          activeProjectIdRef.current === projectId &&
          activeFeedbackThreadIdRef.current === threadId
        ) {
          setFeedbackSending(false);
        }
      }
    }
  };

  const pickFeedbackAttachments = async () => {
    if (
      !feedbackThread?.attachmentsEnabled ||
      feedbackPickerFlightRef.current ||
      feedbackSendFlightRef.current
    )
      return;
    const projectId = project.id;
    const threadId = feedbackThread.id;
    const generation = feedbackGenerationRef.current;
    const flight = Symbol('project-feedback-picker');
    const ownsPickerContext = () =>
      feedbackGenerationRef.current === generation &&
      activeProjectIdRef.current === projectId &&
      activeFeedbackThreadIdRef.current === threadId;
    const ownsPicker = () =>
      feedbackPickerFlightRef.current === flight && ownsPickerContext();
    feedbackPickerFlightRef.current = flight;
    const additions: ChatAttachmentDraft[] = [];
    try {
      const pickerBoundary = await captureAccountSessionBoundary();
      assertAccountSessionBoundary(pickerBoundary);
      const maximum = Math.max(0, feedbackThread.attachmentMaxFiles || 0);
      const result = await DocumentPicker.getDocumentAsync({
        type: [
          'image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain',
          'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
        multiple: true,
        copyToCacheDirectory: true,
      });
      assertAccountSessionBoundary(pickerBoundary);
      if (result.canceled) return;
      if (!ownsPicker()) return;
      const remaining = Math.max(0, maximum - feedbackAttachments.length);
      for (const asset of result.assets.slice(0, remaining)) {
        additions.push(
          await cacheProjectFeedbackFile(
            {
              uri: asset.uri,
              name: asset.name,
              type: asset.mimeType || 'application/octet-stream',
              size: asset.size,
              uploadId: secureRandomUuid(),
            },
            pickerBoundary,
          ),
        );
        assertAccountSessionBoundary(pickerBoundary);
        if (!ownsPicker()) {
          await Promise.all(additions.map(removeLearnerDraftFile));
          return;
        }
      }
      if (!ownsPicker()) {
        await Promise.all(additions.map(removeLearnerDraftFile));
        return;
      }
      setFeedbackAttachments(current => {
        if (!ownsPickerContext()) {
          void Promise.all(additions.map(removeLearnerDraftFile));
          return current;
        }
        const kept = [...current, ...additions].slice(0, maximum);
        const keptIds = new Set(kept.map(file => file.uploadId));
        void Promise.all(
          additions
            .filter(file => !keptIds.has(file.uploadId))
            .map(removeLearnerDraftFile),
        );
        return kept;
      });
    } catch (error: unknown) {
      await Promise.all(additions.map(removeLearnerDraftFile));
      if (!ownsPicker()) return;
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      )
        return;
      showMediaPickerFailure(
        error instanceof Error && error.message === 'LEARNER_DRAFT_STORAGE_FULL'
          ? error.message
          : 'document_picker_failed',
      );
    } finally {
      if (feedbackPickerFlightRef.current === flight) {
        feedbackPickerFlightRef.current = null;
      }
    }
  };

  const submitSelectedFiles = async (files: SelectedProjectFile[]) => {
    const projectId = project.id;
    const projectGeneration = projectGenerationRef.current;
    try {
      const validated = await Promise.all(files.map(async file => ({
        ...file, size: await validateProjectFile(file),
      })));
      if (!ownsProject(projectId, projectGeneration)) return;
      setSelectedFiles(validated);
    } catch (error: unknown) {
      if (!ownsProject(projectId, projectGeneration)) return;
      const code = error instanceof Error ? error.message : '';
      Alert.alert(
        code === 'LEARNER_DRAFT_STORAGE_FULL'
          ? 'اكتملت مساحة الملفات المعلّقة'
          : code === 'PROJECT_FILE_TOO_LARGE'
          ? 'حجم الملف كبير'
          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
          ? 'صيغة الملف غير مدعومة'
          : 'تعذّر قراءة حجم الملف',
        code === 'LEARNER_DRAFT_STORAGE_FULL'
          ? 'اتصل بالإنترنت لإرسال الملفات المعلّقة\nثم حاول مرة أخرى'
          : code === 'PROJECT_FILE_TOO_LARGE'
          ? `اختر ملفًا أصغر من ${PROJECT_SUBMISSION_MAX_LABEL}`
          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
          ? `اختر ${PROJECT_SUBMISSION_FORMATS_LABEL}`
          : 'اختر الملف مرة أخرى أو نسخة أصغر',
      );
      return;
    }
    if (Platform.OS === 'android' && NativeModules.RoknMediaInspector?.inspect) {
      try {
        for (const file of files.filter(candidate => candidate.type.startsWith('image/'))) {
          const inspection = await NativeModules.RoknMediaInspector.inspect(file.uri);
          if (inspection?.isBlank) {
            Alert.alert('الصورة غير واضحة', 'اختر صورة واضحة لعملك');
            return;
          }
        }
      } catch {
        // Inspection is a guardrail, never a reason to block a sincere learner.
      }
    }
    if (!ownsProject(projectId, projectGeneration)) return;
    setStatus('reviewing');
    setSyncNote('');
    const provisionalFallback: ProjectSubmissionOutcome = {
      passed: false,
      synced: false,
      provisional: true,
      canContinue: false,
    };
    let result = provisionalFallback;
    try {
      result = await onSubmit(files, normalizedSubmissionNote);
      if (!ownsProject(projectId, projectGeneration)) return;
      setSelectedFiles([]);
      setSubmissionNote('');
    } catch (error: unknown) {
      if (!ownsProject(projectId, projectGeneration)) return;
      if (
        error instanceof Error &&
        error.message === 'PROJECT_PENDING_CACHE_FULL'
      ) {
        setStatus(project.status);
        setSyncNote('');
        Alert.alert(
          'اكتملت مساحة المشروعات المعلّقة',
          'اتصل بالإنترنت لإرسالها\nثم حاول تسليم هذا المشروع',
        );
        return;
      }
      if (
        error instanceof Error &&
        error.message === 'PROJECT_FILE_COPY_FAILED'
      ) {
        setStatus(project.status);
        setSyncNote('');
        Alert.alert(
          'تعذّر تجهيز الملف',
          'اختر الملف مرة أخرى\nوتأكد من وجود مساحة كافية على الجهاز',
        );
        return;
      }
      const responseStatus = Number(
        error && typeof error === 'object'
          ? (error as {status?: unknown; response?: {status?: unknown}})
              .status ??
              (error as {response?: {status?: unknown}}).response?.status
          : 0,
      );
      setStatus('needs_retry');
      setSyncNote('');
      Alert.alert(
        'لم يكتمل التسليم',
        responseStatus === 401
          ? 'سجّل الدخول ثم حاول مرة أخرى'
          : responseStatus === 403
          ? 'لم يعد هذا المشروع متاحًا لحسابك'
          : responseStatus === 409
          ? 'أكمل المحتوى السابق ثم حاول مرة أخرى'
          : responseStatus === 422
          ? 'راجع الملف المختار ثم حاول مرة أخرى'
          : 'حاول تسليم المشروع مرة أخرى',
      );
      return;
    }
    if (!ownsProject(projectId, projectGeneration)) return;
    // A synced response or durable local outbox acceptance is the submission
    // acknowledgement. Cleanup is independent and idempotent; its failure
    // cannot overwrite the accepted project state below.
    void clearProjectSubmissionDraft(projectId, files).catch(() => undefined);
    setStatus(
      result.passed
        ? 'passed'
        : result.provisional
        ? 'reviewing'
        : 'needs_retry',
    );
    if (result.provisional) {
      setSyncNote(
        result.synced
          ? 'استلمنا مشروعك\nسنفتح المقطع التالي بعد المراجعة'
          : 'نحفظ محاولتك\nوسنرسلها عند استقرار الاتصال',
      );
    }
  };

  const submit = async () => {
    if (
      !submissionDraftReady ||
      submissionInFlightRef.current ||
      pickerFlightRef.current
    )
      return;
    if (selectedFiles.length === 0 && normalizedSubmissionNote.length < 10) {
      Alert.alert('أضف محاولتك', 'اكتب ما نفذته أو أضف ملفًا يوضحه');
      return;
    }
    const projectId = project.id;
    const projectGeneration = projectGenerationRef.current;
    submissionInFlightRef.current = true;
    setSubmissionSending(true);
    try {
      await submitSelectedFiles(selectedFiles);
    } finally {
      if (ownsProject(projectId, projectGeneration)) {
        submissionInFlightRef.current = false;
        setSubmissionSending(false);
      }
    }
  };

  const chooseProjectFile = async () => {
    if (pickerFlightRef.current || submissionInFlightRef.current) return;
    const projectId = project.id;
    const projectGeneration = projectGenerationRef.current;
    const cached: SelectedProjectFile[] = [];
    pickerFlightRef.current = true;
    try {
      const {files: picked, ownerBoundary} = await pickOwnedMediaFiles(
        project.submissionAllowedMimeTypes || [],
      );
      assertAccountSessionBoundary(ownerBoundary);
      if (picked.length === 0 || !ownsProject(projectId, projectGeneration)) return;
      const maximum = Math.max(1, Math.min(5, project.submissionMaxFiles || 3));
      const available = picked.slice(0, Math.max(0, maximum - selectedFiles.length));
      for (const file of available) {
        const size = await validateProjectFile(file);
        assertAccountSessionBoundary(ownerBoundary);
        cached.push(
          await cacheProjectDraftFile({...file, size}, ownerBoundary),
        );
        assertAccountSessionBoundary(ownerBoundary);
      }
      if (!ownsProject(projectId, projectGeneration)) {
        await Promise.all(cached.map(removeLearnerDraftFile));
        return;
      }
      setSelectedFiles(current => [...current, ...cached].slice(0, maximum));
    } catch (error: unknown) {
      await Promise.all(cached.map(removeLearnerDraftFile));
      if (!ownsProject(projectId, projectGeneration)) return;
      const code = error instanceof Error ? error.message : '';
      if (code === 'ACCOUNT_CHANGED_DURING_REQUEST') return;
      Alert.alert(
        code === 'PROJECT_FILE_TOO_LARGE'
          ? 'حجم الملف كبير'
          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
          ? 'صيغة الملف غير مدعومة'
          : 'تعذّر قراءة الملف',
        code === 'PROJECT_FILE_TOO_LARGE'
          ? `الحد الأقصى ${PROJECT_SUBMISSION_MAX_LABEL}\nاختر نسخة أصغر`
          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
          ? `اختر ${PROJECT_SUBMISSION_FORMATS_LABEL}`
          : 'اختر الملف مرة أخرى أو نسخة أصغر',
      );
    } finally {
      if (ownsProject(projectId, projectGeneration)) {
        pickerFlightRef.current = false;
      }
    }
  };

  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      keyboardVerticalOffset={topInset}
      style={[styles.page, {width, height}]}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="العودة"
        hitSlop={10}
        style={[styles.backButton, {top: topInset + 8}]}
        onPress={() => goBackOrHome(navigation)}>
        <Text style={styles.backSymbol}>›</Text>
      </Pressable>
      <ScrollView
        showsVerticalScrollIndicator={false}
        contentContainerStyle={[
          styles.content,
          {paddingTop: topInset + 36, paddingBottom: bottomInset + 38},
        ]}>
        <View style={styles.eyebrowRow}>
          <View style={styles.eyebrowLine} />
          <Text style={styles.eyebrow}>حان وقت التطبيق</Text>
        </View>
        <Text style={styles.moduleTitle}>
          {formatArabicDisplayText(moduleTitle)}
        </Text>

        <View style={styles.card}>
          <View style={styles.projectBadge}>
            <Text style={styles.projectBadgeText}>
              {project.isGraduationProject ? 'مشروع التخرج' : 'مشروع العبور'}
            </Text>
          </View>
          <Text style={styles.title}>
            {formatArabicDisplayText(project.title)}
          </Text>
          <Text style={styles.requirements}>
            {formatArabicDisplayText(project.requirements)}
          </Text>

          {!!project.attachments.length && (
            <View style={styles.attachmentsBlock}>
              <Text style={styles.blockLabel}>ملفات قد تحتاجها</Text>
              {project.attachments.map(attachment => (
                <Pressable
                  key={attachment.id}
                  accessibilityRole="button"
                  style={styles.attachmentRow}
                  onPress={() => void openCourseAttachment(attachment)}>
                  <View style={styles.attachmentCopy}>
                    <Text style={styles.attachmentTitle} numberOfLines={1}>
                      {formatArabicDisplayText(attachment.title)}
                    </Text>
                    <Text style={styles.attachmentMeta}>
                      {attachment.platform === 'computer'
                        ? 'انسخ الرابط وافتحه على الكمبيوتر'
                        : formatArabicDisplayText(
                            [attachment.fileType, attachment.fileSize]
                              .filter(Boolean)
                              .join(' · ') || 'تنزيل مباشر',
                          )}
                    </Text>
                  </View>
                  <Text style={styles.attachmentAction}>
                    {attachment.platform === 'computer'
                      ? 'نسخ الرابط'
                      : 'تنزيل'}
                  </Text>
                </Pressable>
              ))}
            </View>
          )}

          {status === 'needs_retry' && !!feedbackThread?.messages.length && (
            <View style={styles.feedbackThread}>
              <View style={styles.feedbackHeader}>
                <Text style={styles.feedbackTitle}>ملاحظات مشروعك</Text>
                <Text style={styles.feedbackAvailability}>عدّل ثم أرسل من جديد</Text>
              </View>
              {feedbackThread.messages
                .filter(message => message.status === 'completed')
                .map(message => (
                  <View
                    key={message.id}
                    style={[
                      styles.feedbackBubble,
                      message.role === 'user'
                        ? styles.feedbackBubbleUser
                        : styles.feedbackBubbleAssistant,
                    ]}>
                    {!!message.text && (
                      <Text style={styles.feedbackMessage}>
                        {formatArabicDisplayText(message.text)}
                      </Text>
                    )}
                    {message.attachments?.map(file => (
                      <Pressable
                        key={file.serverId || file.uploadId}
                        style={styles.feedbackMessageAttachment}
                        onPress={() =>
                          void openProjectInputAttachment({
                            projectId: project.id,
                            threadId: feedbackThread.id,
                            file,
                          }).catch(() =>
                            Alert.alert('تعذّر فتح الملف', 'حاول مرة أخرى'),
                          )
                        }>
                        <Text numberOfLines={1} style={styles.feedbackMessageAttachmentName}>
                          {file.name}
                        </Text>
                      </Pressable>
                    ))}
                  </View>
                ))}
            </View>
          )}

          {status === 'passed' ? (
            <View style={styles.successState}>
              <View style={styles.successIcon}>
                <Text style={styles.successCheck}>✓</Text>
              </View>
              <Text style={styles.successTitle}>تم اعتماد مشروعك</Text>
              <Text style={styles.successDescription}>
                {onContinue
                  ? 'فتحنا لك المقطع التالي'
                  : 'تم اعتماد النتيجة وحفظ تقدمك'}
              </Text>
              {!!syncNote && <Text style={styles.syncNote}>{syncNote}</Text>}
              {!!project.submissionAttachments?.length && (
                <View style={styles.feedbackMessageAttachments}>
                  <Text style={styles.blockLabel}>ملفات التسليم</Text>
                  {project.submissionAttachments.map(file => (
                    <Pressable
                      key={file.serverId || file.uploadId}
                      style={styles.feedbackMessageAttachment}
                      onPress={() =>
                        void openProjectInputAttachment({
                          projectId: project.id,
                          file,
                        }).catch(() =>
                          Alert.alert('تعذّر فتح الملف', 'حاول مرة أخرى'),
                        )
                      }>
                      <Text numberOfLines={1} style={styles.feedbackMessageAttachmentName}>
                        {file.name}
                      </Text>
                    </Pressable>
                  ))}
                </View>
              )}
              {!!onContinue && (
                <Pressable
                  accessibilityRole="button"
                  style={styles.primaryButton}
                  onPress={onContinue}>
                  <Text style={styles.primaryButtonText}>أكمل الكورس</Text>
                </Pressable>
              )}
              {!!feedbackThread && (
                <View style={styles.feedbackThread}>
                  <View style={styles.feedbackHeader}>
                    <Text style={styles.feedbackTitle}>شات ركن</Text>
                    <Text style={styles.feedbackAvailability}>
                      {feedbackThread.canReply ? 'متصل الآن' : 'تقرير مشروعك'}
                    </Text>
                  </View>
                  {feedbackThread.messages.map(message => (
                    <View
                      key={message.id}
                      style={[
                        styles.feedbackBubble,
                        message.role === 'user'
                          ? styles.feedbackBubbleUser
                          : styles.feedbackBubbleAssistant,
                      ]}>
                      {!!message.text && (
                        <Text style={styles.feedbackMessage}>
                          {formatArabicDisplayText(message.text)}
                        </Text>
                      )}
                      {!!message.attachments?.length && (
                        <View style={styles.feedbackMessageAttachments}>
                          {message.attachments.map(file => (
                            <Pressable
                              key={file.serverId || file.uploadId}
                              style={styles.feedbackMessageAttachment}
                              onPress={() =>
                                void openProjectInputAttachment({
                                  projectId: project.id,
                                  threadId: feedbackThread.id,
                                  file,
                                }).catch(() =>
                                  Alert.alert(
                                    'تعذّر فتح الملف',
                                    'حاول مرة أخرى',
                                  ),
                                )
                              }>
                              <Text numberOfLines={1} style={styles.feedbackMessageAttachmentName}>
                                {file.name}
                              </Text>
                            </Pressable>
                          ))}
                        </View>
                      )}
                      {message.role === 'assistant' &&
                        message.status === 'streaming' && (
                          <Text style={styles.feedbackState}>يكتب الآن</Text>
                        )}
                      {message.role === 'assistant' &&
                        message.status === 'failed' && (
                          <Text style={styles.feedbackState}>
                            {message.errorCode === 'plan_limit_reached'
                              ? 'اكتملت رسائل متابعة المشروع في هذه الفئة'
                              : 'تعذّر الرد الآن\nأرسل رسالتك مرة أخرى'}
                          </Text>
                        )}
                      {message.role === 'user' &&
                        message.status === 'queued' && (
                          <Text style={styles.feedbackState}>جارٍ الإرسال</Text>
                        )}
                      {message.status === 'failed' &&
                        message.role === 'user' &&
                        message.errorCode !== 'plan_limit_reached' && (
                          <Pressable
                            accessibilityRole="button"
                            disabled={feedbackSending}
                            onPress={() =>
                              void sendFeedback(
                                message.text || '',
                                undefined,
                                true,
                                message.attachments || [],
                              )
                            }>
                            <Text style={styles.feedbackRetry}>
                              إرسال مرة أخرى
                            </Text>
                          </Pressable>
                        )}
                    </View>
                  ))}
                  {feedbackAttachments.length > 0 && (
                    <View style={styles.feedbackAttachmentList}>
                      {feedbackAttachments.map(file => (
                        <View key={file.uploadId} style={styles.feedbackAttachmentChip}>
                          {file.type.startsWith('image/') && !!file.uri && (
                            <Image source={{uri: file.uri}} style={styles.feedbackAttachmentPreview} />
                          )}
                          <Text numberOfLines={1} style={styles.feedbackAttachmentName}>{file.name}</Text>
                          <Pressable onPress={() => {
                            if (feedbackSendFlightRef.current) return;
                            setFeedbackAttachments(current => current.filter(item => item.uploadId !== file.uploadId));
                            if (!file.serverId) void removeLearnerDraftFile(file);
                          }}>
                            <Text style={styles.feedbackAttachmentRemove}>×</Text>
                          </Pressable>
                        </View>
                      ))}
                    </View>
                  )}
                  {feedbackThread.canReply &&
                    feedbackThread.remainingMessages > 0 &&
                    !feedbackPending && (
                      <View style={styles.feedbackComposer}>
                        <TextInput
                          multiline
                          editable={!feedbackSending}
                          value={feedbackDraft}
                          onChangeText={value =>
                            !feedbackSendFlightRef.current &&
                            setFeedbackDraft(truncateGraphemes(value, 2000))
                          }
                          placeholder="اسأل عن مشروعك"
                          placeholderTextColor="rgba(255,255,255,.38)"
                          style={styles.feedbackInput}
                        />
                        {feedbackThread.attachmentsEnabled && (
                          <Pressable
                            accessibilityRole="button"
                            accessibilityLabel="إضافة مرفق"
                            disabled={feedbackSending || feedbackAttachments.length >= (feedbackThread.attachmentMaxFiles || 0)}
                            style={styles.feedbackAttach}
                            onPress={() => void pickFeedbackAttachments()}>
                            <Text style={styles.feedbackAttachText}>＋</Text>
                          </Pressable>
                        )}
                        <Pressable
                          accessibilityRole="button"
                          disabled={(!normalizedFeedbackDraft && feedbackAttachments.length === 0) || feedbackSending}
                          onPress={() => void sendFeedback()}
                          style={[
                            styles.feedbackSend,
                            ((!normalizedFeedbackDraft && feedbackAttachments.length === 0) || feedbackSending) &&
                              styles.disabledButton,
                          ]}>
                          {feedbackSending ? (
                            <ActivityIndicator color="#FFFFFF" size="small" />
                          ) : (
                            <Text style={styles.feedbackSendText}>إرسال</Text>
                          )}
                        </Pressable>
                      </View>
                    )}
                  {!!feedbackError && (
                    <Text
                      accessibilityRole="alert"
                      style={styles.feedbackError}>
                      {feedbackError}
                    </Text>
                  )}
                </View>
              )}
            </View>
          ) : status === 'reviewing' ? (
            <View style={styles.reviewState}>
              <View style={styles.reviewLoader}>
                <ActivityIndicator color="#76A9FF" size="large" />
              </View>
              <Text style={styles.reviewTitle}>مشروعك محفوظ</Text>
              <Text style={styles.reviewDescription}>سنحدّث النتيجة هنا</Text>
              {!!syncNote && <Text style={styles.syncNote}>{syncNote}</Text>}
            </View>
          ) : (
            <View style={styles.uploadBlock}>
              <Pressable
                accessibilityRole="button"
                disabled={!submissionDraftReady || submissionSending}
                style={styles.uploadTarget}
                onPress={() => void chooseProjectFile()}>
                <View style={styles.uploadIcon}>
                  <UploadIcon />
                </View>
                <View style={styles.uploadCopy}>
                  <Text style={styles.uploadTitle}>
                    {selectedFiles.length
                      ? `${selectedFiles.length} ملفات`
                      : 'أضف ملفات مشروعك'}
                  </Text>
                  <Text style={styles.uploadHint}>
                    {selectedFiles.length
                      ? `يمكنك إضافة ${Math.max(0, (project.submissionMaxFiles || 3) - selectedFiles.length)}`
                      : 'صور أو PDF أو Word أو PowerPoint'}
                  </Text>
                </View>
              </Pressable>
              {selectedFiles.length > 0 && (
                <View style={styles.feedbackAttachmentList}>
                  {selectedFiles.map((file, index) => (
                    <View key={`${file.uri}-${index}`} style={styles.feedbackAttachmentChip}>
                      {file.type.startsWith('image/') && !!file.uri && (
                        <Image source={{uri: file.uri}} style={styles.feedbackAttachmentPreview} />
                      )}
                      <Text numberOfLines={1} style={styles.feedbackAttachmentName}>{file.name}</Text>
                      <Pressable onPress={() => {
                        if (submissionInFlightRef.current) return;
                        setSelectedFiles(current => current.filter(candidate => candidate.uri !== file.uri));
                        void removeLearnerDraftFile(file);
                      }}>
                        <Text style={styles.feedbackAttachmentRemove}>×</Text>
                      </Pressable>
                    </View>
                  ))}
                </View>
              )}
              <TextInput
                multiline
                editable={!submissionSending}
                value={submissionNote}
                onChangeText={value =>
                  !submissionInFlightRef.current &&
                  setSubmissionNote(truncateGraphemes(value, 2000))
                }
                placeholder="اكتب ما نفذته أو أضف ملفًا"
                placeholderTextColor="rgba(255,255,255,.38)"
                style={styles.submissionNoteInput}
              />
              {submissionDraftSaveError && (
                <Text accessibilityRole="alert" style={styles.draftSaveError}>
                  تعذّر حفظ المسودة على الجهاز
                  {'\n'}اترك الصفحة مفتوحة حتى تسلّم المشروع
                </Text>
              )}
              <Pressable
                accessibilityRole="button"
                disabled={
                  !submissionDraftReady ||
                  submissionSending ||
                  (selectedFiles.length === 0 && normalizedSubmissionNote.length < 10)
                }
                style={[
                  styles.primaryButton,
                  ((selectedFiles.length === 0 && normalizedSubmissionNote.length < 10) ||
                    !submissionDraftReady ||
                    submissionSending) &&
                    styles.disabledButton,
                ]}
                onPress={submit}>
                {submissionSending ? (
                  <ActivityIndicator color="#FFFFFF" size="small" />
                ) : (
                  <Text style={styles.primaryButtonText}>سلّم المشروع</Text>
                )}
              </Pressable>
            </View>
          )}
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
};

export default ProjectTransition;
export const pickMedia = async (): Promise<SelectedProjectFile | null> =>
  (await pickMediaFiles([]))[0] || null;
export const pickProjectFiles = pickMediaFiles;
export const pickProjectFilesOwned = pickOwnedMediaFiles;

const styles = StyleSheet.create({
  page: {
    backgroundColor: '#070B11',
  },
  backButton: {
    position: 'absolute',
    start: 12,
    width: 42,
    height: 42,
    borderRadius: 21,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(5,9,14,.72)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.12)',
    zIndex: 20,
  },
  backSymbol: {
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 35,
    lineHeight: 37,
    marginBottom: 3,
  },
  content: {
    direction: 'rtl',
    flexGrow: 1,
    width: '100%',
    maxWidth: 700,
    alignSelf: 'center',
    paddingHorizontal: 18,
    justifyContent: 'center',
  },
  eyebrowRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 8,
  },
  eyebrowLine: {
    width: 24,
    height: 2,
    borderRadius: 1,
    backgroundColor: '#4B8EF7',
  },
  eyebrow: {
    ...textDirection,
    color: '#76A9FF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  moduleTitle: {
    ...textDirection,
    color: 'rgba(255,255,255,.58)',
    fontFamily: Fonts.medium,
    fontSize: 13,
    marginTop: 7,
    marginBottom: 18,
  },
  card: {
    direction: 'rtl',
    borderRadius: 26,
    padding: 20,
    backgroundColor: '#111923',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
  projectBadge: {
    alignSelf: 'flex-start',
    minHeight: 27,
    paddingHorizontal: 11,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(75,142,247,.14)',
    borderWidth: 1,
    borderColor: 'rgba(91,153,251,.25)',
  },
  projectBadgeText: {
    ...textDirection,
    color: '#8BB6FA',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  title: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 23,
    lineHeight: 35,
    marginTop: 13,
  },
  requirements: {
    ...textDirection,
    color: 'rgba(255,255,255,.72)',
    fontFamily: Fonts.regular,
    fontSize: 14,
    lineHeight: 24,
    marginTop: 7,
  },
  attachmentsBlock: {
    marginTop: 20,
    gap: 8,
  },
  blockLabel: {
    ...textDirection,
    color: 'rgba(255,255,255,.52)',
    fontFamily: Fonts.medium,
    fontSize: 11,
  },
  attachmentRow: {
    minHeight: 58,
    borderRadius: 15,
    paddingHorizontal: 12,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 10,
    backgroundColor: 'rgba(255,255,255,.045)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.07)',
  },
  attachmentCopy: {
    flex: 1,
    minWidth: 0,
  },
  attachmentTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.medium,
    fontSize: 12,
  },
  attachmentMeta: {
    ...textDirection,
    color: 'rgba(255,255,255,.43)',
    fontFamily: Fonts.regular,
    fontSize: 10,
    marginTop: 2,
  },
  attachmentAction: {
    ...textDirection,
    color: '#76A9FF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  uploadBlock: {
    marginTop: 22,
    gap: 12,
  },
  uploadTarget: {
    minHeight: 78,
    borderRadius: 18,
    padding: 13,
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 12,
    backgroundColor: 'rgba(255,255,255,.035)',
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: 'rgba(118,169,255,.4)',
  },
  submissionNoteInput: {
    ...textDirection,
    minHeight: 64,
    maxHeight: 120,
    borderRadius: 16,
    paddingHorizontal: 13,
    paddingVertical: 11,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
    backgroundColor: 'rgba(255,255,255,.045)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.09)',
  },
  uploadIcon: {
    width: 48,
    height: 48,
    borderRadius: 15,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(35,111,232,.2)',
  },
  uploadCopy: {
    flex: 1,
    minWidth: 0,
  },
  uploadTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 13,
  },
  uploadHint: {
    ...textDirection,
    color: 'rgba(255,255,255,.48)',
    fontFamily: Fonts.regular,
    fontSize: 10,
    lineHeight: 17,
    marginTop: 3,
  },
  primaryButton: {
    width: '100%',
    minHeight: 50,
    borderRadius: 17,
    paddingHorizontal: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
  },
  disabledButton: {
    opacity: 0.38,
  },
  primaryButtonText: {
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 14,
  },
  reviewState: {
    minHeight: 190,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 18,
  },
  reviewLoader: {
    width: 66,
    height: 66,
    borderRadius: 33,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(52,120,246,.10)',
    borderWidth: 1,
    borderColor: 'rgba(118,169,255,.24)',
  },
  reviewTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 17,
    marginTop: 15,
    textAlign: 'center',
  },
  reviewDescription: {
    direction: 'rtl',
    writingDirection: 'rtl',
    color: 'rgba(255,255,255,.55)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    marginTop: 4,
    textAlign: 'center',
  },
  successState: {
    minHeight: 215,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 18,
  },
  successIcon: {
    width: 54,
    height: 54,
    borderRadius: 27,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(70,196,135,.15)',
    borderWidth: 1,
    borderColor: 'rgba(90,218,156,.3)',
  },
  successCheck: {
    color: '#67D39B',
    fontFamily: Fonts.bold,
    fontSize: 25,
  },
  successTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 18,
    marginTop: 12,
    textAlign: 'center',
  },
  successDescription: {
    direction: 'rtl',
    writingDirection: 'rtl',
    color: 'rgba(255,255,255,.56)',
    fontFamily: Fonts.regular,
    fontSize: 12,
    marginTop: 3,
    textAlign: 'center',
  },
  syncNote: {
    direction: 'rtl',
    writingDirection: 'rtl',
    color: '#8BB6FA',
    fontFamily: Fonts.regular,
    fontSize: 10,
    lineHeight: 16,
    textAlign: 'center',
    marginTop: 8,
  },
  draftSaveError: {
    ...textDirection,
    color: '#F3A3A3',
    fontFamily: Fonts.medium,
    fontSize: 12,
    lineHeight: 19,
    marginTop: 10,
  },
  feedbackThread: {
    width: '100%',
    marginTop: 18,
    padding: 12,
    borderRadius: 18,
    gap: 8,
    backgroundColor: '#0B111A',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
  feedbackHeader: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 2,
  },
  feedbackTitle: {color: '#FFFFFF', fontFamily: Fonts.bold, fontSize: 14},
  feedbackAvailability: {
    color: '#67D39B',
    fontFamily: Fonts.regular,
    fontSize: 10,
  },
  feedbackBubble: {
    maxWidth: '90%',
    borderRadius: 15,
    paddingHorizontal: 12,
    paddingVertical: 9,
  },
  feedbackBubbleAssistant: {
    alignSelf: 'flex-end',
    backgroundColor: '#17202C',
    borderTopRightRadius: 5,
  },
  feedbackBubbleUser: {
    alignSelf: 'flex-start',
    backgroundColor: '#236FE8',
    borderTopLeftRadius: 5,
  },
  feedbackMessage: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 12,
    lineHeight: 20,
  },
  feedbackState: {
    ...textDirection,
    color: 'rgba(255,255,255,.58)',
    fontFamily: Fonts.regular,
    fontSize: 10,
  },
  feedbackRetry: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 10,
    marginTop: 4,
  },
  feedbackAttachmentList: {gap: 6, marginTop: 3},
  feedbackAttachmentChip: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: 8,
    borderRadius: 11,
    paddingHorizontal: 10,
    paddingVertical: 7,
    backgroundColor: 'rgba(255,255,255,.07)',
  },
  feedbackAttachmentName: {
    ...textDirection,
    flex: 1,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 11,
  },
  feedbackAttachmentRemove: {color: '#FFFFFF', fontSize: 20, lineHeight: 20},
  feedbackAttachmentPreview: {width: 34, height: 34, borderRadius: 8},
  feedbackAttach: {
    width: 44, height: 44, borderRadius: 14,
    alignItems: 'center', justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.08)',
  },
  feedbackAttachText: {color: '#FFFFFF', fontSize: 22, lineHeight: 24},
  feedbackMessageAttachments: {gap: 5, marginTop: 6},
  feedbackMessageAttachment: {
    borderRadius: 9, paddingHorizontal: 8, paddingVertical: 5,
    backgroundColor: 'rgba(255,255,255,.1)',
  },
  feedbackMessageAttachmentName: {
    ...textDirection, color: '#FFFFFF', fontFamily: Fonts.regular, fontSize: 10,
  },
  feedbackComposer: {
    ...rtlRowStyle,
    alignItems: 'flex-end',
    gap: 7,
    marginTop: 3,
  },
  feedbackInput: {
    ...textDirection,
    flex: 1,
    minHeight: 44,
    maxHeight: 110,
    borderRadius: 14,
    paddingHorizontal: 12,
    paddingVertical: 10,
    color: '#FFFFFF',
    fontFamily: Fonts.regular,
    fontSize: 12,
    backgroundColor: 'rgba(255,255,255,.06)',
  },
  feedbackSend: {
    minWidth: 64,
    height: 44,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#236FE8',
  },
  feedbackSendText: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 11,
  },
  feedbackError: {
    ...textDirection,
    color: '#FF9A9A',
    fontFamily: Fonts.regular,
    fontSize: 10,
    lineHeight: 16,
  },
});
