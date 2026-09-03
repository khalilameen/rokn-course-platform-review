import {useCallback, useEffect, useRef, useState} from 'react';
import type {ScrollView} from 'react-native';
import {
  getCourseChatUpgradeQuote,
  purchaseCourseChatUpgrade,
  type CourseChatUpgradeQuote,
} from '../../../services/roknApi';
import {
  askCourseAssistant,
  courseIncludesAssistant,
  loadCourseAssistantHistory,
  pollCourseAssistantTurn,
  uploadCourseAssistantAttachment,
  cancelCourseAssistantTurn,
} from '../courseLearningApi';
import {isGrantCourseAccess} from '../courseEntitlements';
import type {
  ChatAttachmentDraft,
  ChatMessage,
  CourseLearningData,
  CourseReel,
} from '../types';
import {courseChatErrorCode} from './policy';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {cleanUnicodeText} from '../../../utils/unicodeText';
import {isLocalDemoId} from '../../../config/runtime';
import {loadCourseChatHistory, saveCourseChatHistory} from './persistence';
import {useAppActiveState} from '../../../hooks/useAppActiveState';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getCurrentAccountStorageScope,
} from '../../../constants/helpers';
import {removeLearnerDraftFile} from '../../../services/learnerDraftFiles';

export type AssistantPresence = 'online' | 'working';
const MAX_IN_MEMORY_MESSAGES = 37;
// Keep the foreground wait short. Slow accepted turns remain pending and are
// resumed with the same request id when the chat stays open or is reopened.
const FOREGROUND_TURN_POLL_ATTEMPTS = 8;

const welcomeMessage = (courseId: string): ChatMessage => ({
  id: `welcome-${courseId}`,
  role: 'assistant',
  text: 'اسألني عن أي جزء في المقطع\nاطلب شرحًا أو مثالًا أو تلخيصًا',
  createdAt: Date.now(),
  deliveryStatus: 'completed',
  contextEligible: false,
});

const trimConversation = (messages: ChatMessage[]): ChatMessage[] => {
  if (messages.length <= MAX_IN_MEMORY_MESSAGES) return messages;
  const welcome = messages.find(message => message.id.startsWith('welcome-'));
  const recent = messages
    .filter(message => !message.id.startsWith('welcome-'))
    .slice(-(MAX_IN_MEMORY_MESSAGES - (welcome ? 1 : 0)));
  return welcome ? [welcome, ...recent] : recent;
};

export const useCourseChat = ({
  visible,
  course,
  reel,
  onOpenWallet,
}: {
  visible: boolean;
  course: CourseLearningData;
  reel?: CourseReel;
  onOpenWallet: () => void;
}) => {
  const courseId = course.id;
  const lessonId = reel?.lessonId;
  const conversationScope = `${courseId}:${lessonId || 'course'}`;
  const appIsActive = useAppActiveState();
  const interactive = visible && appIsActive;
  const [serverBlocked, setServerBlocked] = useState(false);
  const [serverBlockCode, setServerBlockCode] = useState('');
  const [upgraded, setUpgraded] = useState(false);
  const [upgradeQuote, setUpgradeQuote] =
    useState<CourseChatUpgradeQuote | null>(null);
  const [upgradeLoading, setUpgradeLoading] = useState(false);
  const [upgradeError, setUpgradeError] = useState('');
  const assistantIncluded =
    (courseIncludesAssistant(course) || upgraded) && !serverBlocked;
  const scholarshipAccess = isGrantCourseAccess(course.accessType);
  const planLimitReached = serverBlockCode === 'chat_plan_limit_reached';
  const chatAccessUnavailable = [
    'course_not_available',
    'course_access_required',
    'chat_disabled_for_course',
  ].includes(serverBlockCode);
  const [messages, setMessages] = useState<ChatMessage[]>(() => [
    welcomeMessage(courseId),
  ]);
  const [accountEpoch, setAccountEpoch] = useState(0);
  const [input, setInput] = useState('');
  const [attachments, setAttachments] = useState<ChatAttachmentDraft[]>([]);
  const [sending, setSending] = useState(false);
  const assistantPresence: AssistantPresence =
    sending ||
    messages.some(
      message =>
        message.role === 'assistant' &&
        ['queued', 'sent', 'streaming'].includes(
          String(message.deliveryStatus || ''),
        ),
    )
      ? 'working'
      : 'online';
  const scrollRef = useRef<ScrollView>(null);
  const scrollTimersRef = useRef(new Set<ReturnType<typeof setTimeout>>());
  const conversationGenerationRef = useRef(0);
  const activeCourseIdRef = useRef(courseId);
  const activeConversationRef = useRef(conversationScope);
  const hydratedConversationRef = useRef<string | null>(null);
  const activeAccountScopeRef = useRef<string | null>(null);
  const visibleRef = useRef(interactive);
  const sendFlightRef = useRef<symbol | null>(null);
  const sendGenerationRef = useRef(0);
  const inFlightAttachmentIdsRef = useRef(new Set<string>());
  const stopFlightRef = useRef<{
    conversation: string;
    flight: symbol;
  } | null>(null);
  const upgradeFlightRef = useRef<symbol | null>(null);
  const upgradeGenerationRef = useRef(0);
  const resumeInterruptedTurnRef = useRef(false);
  const messagesRef = useRef(messages);
  const attachmentsRef = useRef(attachments);
  const runTurnRef = useRef<
    (
      clientRequestId?: string,
      message?: string,
      files?: ChatAttachmentDraft[],
    ) => Promise<void>
  >(async () => undefined);
  activeCourseIdRef.current = courseId;
  activeConversationRef.current = conversationScope;
  visibleRef.current = interactive;
  messagesRef.current = messages;
  attachmentsRef.current = attachments;

  const scheduleScrollToEnd = useCallback(
    (animated: boolean, delayMs: number) => {
      const timer = setTimeout(() => {
        scrollTimersRef.current.delete(timer);
        scrollRef.current?.scrollToEnd({animated});
      }, delayMs);
      scrollTimersRef.current.add(timer);
    },
    [],
  );

  useEffect(() => {
    conversationGenerationRef.current += 1;
    const conversationGeneration = conversationGenerationRef.current;
    hydratedConversationRef.current = null;
    sendFlightRef.current = null;
    setMessages([welcomeMessage(courseId)]);
    setInput('');
    void Promise.all(
      attachmentsRef.current
        .filter(
          file => !inFlightAttachmentIdsRef.current.has(file.uploadId),
        )
        .map(removeLearnerDraftFile),
    );
    setAttachments([]);
    setSending(false);
    void (async () => {
      const accountScope = await getCurrentAccountStorageScope();
      const localHistory = await loadCourseChatHistory(courseId, lessonId);
      if (
        conversationGeneration !== conversationGenerationRef.current ||
        activeConversationRef.current !== conversationScope ||
        (await getCurrentAccountStorageScope()) !== accountScope
      ) {
        return;
      }
      activeAccountScopeRef.current = accountScope;
      hydratedConversationRef.current = conversationScope;
      const initialMessages = [
        welcomeMessage(courseId),
        ...trimConversation(localHistory),
      ];
      resumeInterruptedTurnRef.current = initialMessages.some(
        message =>
          message.role === 'assistant' &&
          Boolean(message.clientRequestId) &&
          ['queued', 'sent', 'streaming'].includes(
            String(message.deliveryStatus || ''),
          ),
      );
      messagesRef.current = initialMessages;
      setMessages(initialMessages);

      try {
        const remoteHistory = await loadCourseAssistantHistory(
          courseId,
          lessonId,
        );
        const currentAccountScope = await getCurrentAccountStorageScope();
        if (
          conversationGeneration !== conversationGenerationRef.current ||
          activeConversationRef.current !== conversationScope ||
          currentAccountScope !== accountScope
        )
          return;
        const renderedLocalHistory = messagesRef.current.filter(
          message => !message.id.startsWith('welcome-'),
        );
        const currentLocalHistory =
          renderedLocalHistory.length > 0 ? renderedLocalHistory : localHistory;
        const remoteKeys = new Set(
          remoteHistory.map(
            message =>
              `${message.role}:${message.clientRequestId || message.id}`,
          ),
        );
        const history = [
          ...remoteHistory,
          ...currentLocalHistory.filter(
            message =>
              !remoteKeys.has(
                `${message.role}:${message.clientRequestId || message.id}`,
              ),
          ),
        ];
        const reconciled = [
          welcomeMessage(courseId),
          ...trimConversation(history),
        ];
        resumeInterruptedTurnRef.current = reconciled.some(
          message =>
            message.role === 'assistant' &&
            Boolean(message.clientRequestId) &&
            ['queued', 'sent', 'streaming'].includes(
              String(message.deliveryStatus || ''),
            ),
        );
        messagesRef.current = reconciled;
        setMessages(reconciled);
      } catch {
        // The account-scoped outbox remains usable offline. A later focus
        // reload reconciles it with the server-owned transcript.
      }
    })();
    return () => {
      conversationGenerationRef.current += 1;
      sendFlightRef.current = null;
      resumeInterruptedTurnRef.current = false;
    };
  }, [accountEpoch, conversationScope, courseId, lessonId]);

  useEffect(() => {
    if (!appIsActive) return;
    let cancelled = false;
    void getCurrentAccountStorageScope().then(accountScope => {
      if (
        cancelled ||
        activeAccountScopeRef.current === null ||
        activeAccountScopeRef.current === accountScope
      ) {
        return;
      }
      setAccountEpoch(value => value + 1);
    });
    return () => {
      cancelled = true;
    };
  }, [appIsActive]);

  useEffect(() => {
    if (hydratedConversationRef.current !== conversationScope) return;
    void saveCourseChatHistory(courseId, messages, lessonId).catch(
      () => undefined,
    );
  }, [conversationScope, courseId, lessonId, messages]);

  useEffect(() => {
    if (messages.length <= MAX_IN_MEMORY_MESSAGES) return;
    setMessages(current => {
      const trimmed = trimConversation(current);
      const retained = new Set(trimmed.map(message => message.id));
      const discardedFiles = current
        .filter(message => !retained.has(message.id))
        .flatMap(message => message.attachments || [])
        .filter(file => !file.serverId);
      void Promise.all(discardedFiles.map(removeLearnerDraftFile));
      return trimmed;
    });
  }, [messages.length]);

  useEffect(() => {
    upgradeGenerationRef.current += 1;
    upgradeFlightRef.current = null;
    setServerBlocked(false);
    setServerBlockCode('');
    setUpgraded(false);
    setUpgradeQuote(null);
    setUpgradeError('');
    setUpgradeLoading(false);
    return () => {
      upgradeFlightRef.current = null;
    };
  }, [accountEpoch, course.accessType, course.chatAvailable, courseId]);

  useEffect(() => {
    const scrollTimers = scrollTimersRef.current;
    if (visible) scheduleScrollToEnd(false, 100);
    return () => {
      scrollTimers.forEach(clearTimeout);
      scrollTimers.clear();
    };
  }, [scheduleScrollToEnd, visible]);

  const runTurn = async (
    retryClientRequestId?: string,
    retryMessage?: string,
    retryAttachments?: ChatAttachmentDraft[],
  ) => {
    const cleanMessage = cleanUnicodeText(retryMessage ?? input);
    const selectedAttachments = retryAttachments ?? attachments;
    if (
      (!cleanMessage && selectedAttachments.length === 0) ||
      sending ||
      sendFlightRef.current ||
      !assistantIncluded ||
      hydratedConversationRef.current !== conversationScope
    ) {
      return;
    }
    const flight = Symbol('course-chat-send');
    const sendGeneration = ++sendGenerationRef.current;
    let clientRequestId = retryClientRequestId || secureRandomUuid();
    sendFlightRef.current = flight;
    selectedAttachments.forEach(file =>
      inFlightAttachmentIdsRef.current.add(file.uploadId),
    );
    const existingUser = retryClientRequestId
      ? messages.find(
          item =>
            item.role === 'user' &&
            item.clientRequestId === retryClientRequestId,
        )
      : undefined;
    const existingAssistant = retryClientRequestId
      ? messages.find(
          item =>
            item.role === 'assistant' &&
            item.clientRequestId === retryClientRequestId,
        )
      : undefined;
    const userMessage: ChatMessage = existingUser || {
      id: `user-${clientRequestId}`,
      role: 'user',
      text: cleanMessage || 'راجع المرفق',
      createdAt: Date.now(),
      clientRequestId,
      deliveryStatus: 'sent',
      contextEligible: true,
      attachments: selectedAttachments,
    };
    const pendingId = existingAssistant?.id || `assistant-${clientRequestId}`;
    const conversationGeneration = conversationGenerationRef.current;
    let queuedMessages: ChatMessage[] =
      existingUser && existingAssistant
        ? messages.map(item =>
            item.id === existingUser.id
              ? {
                  ...item,
                  clientRequestId,
                  deliveryStatus: 'sent',
                  contextEligible: true,
                }
              : item.id === existingAssistant.id
              ? {
                  ...item,
                  text: '',
                  pending: true,
                  clientRequestId,
                  deliveryStatus: 'queued',
                  errorCode: undefined,
                  contextEligible: false,
                }
              : item,
          )
        : [
            ...messages,
            userMessage,
            {
              id: pendingId,
              role: 'assistant',
              text: '',
              createdAt: Date.now(),
              pending: true,
              clientRequestId,
              deliveryStatus: 'queued',
              contextEligible: false,
            },
          ];
    messagesRef.current = queuedMessages;
    setMessages(queuedMessages);
    if (!retryMessage) {
      setInput('');
      setAttachments([]);
    }
    setSending(true);
    scheduleScrollToEnd(true, 80);

    try {
      const turnBoundary = await captureAccountSessionBoundary();
      if (activeAccountScopeRef.current !== turnBoundary.scope) {
        throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
      }
      // Persist the outbox before the paid request leaves the phone. If the
      // process dies after this point, the same client id can recover the
      // accepted server response without another provider call or debit.
      await saveCourseChatHistory(
        courseId,
        queuedMessages,
        lessonId,
        turnBoundary,
      );
      const uploadedWithLocalFiles = await Promise.all(
        selectedAttachments.map(async file => ({
          ...file,
          serverId:
            file.serverId ||
            (await uploadCourseAssistantAttachment({courseId, file})),
        })),
      );
      const uploadedAttachments = uploadedWithLocalFiles.map(file => ({
        ...file,
        uri: '',
      }));
      queuedMessages = queuedMessages.map(item =>
        item.id === userMessage.id
          ? {...item, attachments: uploadedAttachments}
          : item,
      );
      // The upload mapping is the recovery source of truth. Commit it before
      // removing app-owned files; a crash can then resume with the same server
      // ids and immutable client request id.
      assertAccountSessionBoundary(turnBoundary);
      await saveCourseChatHistory(
        courseId,
        queuedMessages,
        lessonId,
        turnBoundary,
      );
      assertAccountSessionBoundary(turnBoundary);
      await Promise.all(
        selectedAttachments
          .filter(file => file.uri && !file.serverId)
          .map(removeLearnerDraftFile),
      );
      if (
        conversationGeneration !== conversationGenerationRef.current ||
        sendGeneration !== sendGenerationRef.current
      )
        return;
      messagesRef.current = queuedMessages;
      setMessages(queuedMessages);
      let response = await askCourseAssistant({
        course: upgraded
          ? {...course, accessType: 'paid', chatAvailable: true}
          : course,
        reel,
        message: cleanMessage,
        clientRequestId,
        attachmentIds: uploadedAttachments
          .map(file => file.serverId)
          .filter((id): id is string => Boolean(id)),
      });
      if (
        conversationGeneration !== conversationGenerationRef.current ||
        sendGeneration !== sendGenerationRef.current
      )
        return;

      // A failed server turn cannot reuse its immutable usage id. First ask
      // for the old id to recover a response lost after completion, then move
      // the same visible bubble to one fresh id only when the server confirms
      // that the old turn really failed.
      if (retryClientRequestId && response.code === 'chat_turn_failed') {
        clientRequestId = secureRandomUuid();
        queuedMessages = queuedMessages.map(item =>
          item.id === userMessage.id || item.id === pendingId
            ? {...item, clientRequestId}
            : item,
        );
        setMessages(queuedMessages);
        await saveCourseChatHistory(
          courseId,
          queuedMessages,
          lessonId,
          turnBoundary,
        ).catch(() => undefined);
        assertAccountSessionBoundary(turnBoundary);
        response = await askCourseAssistant({
          course: upgraded
            ? {...course, accessType: 'paid', chatAvailable: true}
            : course,
          reel,
          message: cleanMessage,
          clientRequestId,
          attachmentIds: uploadedAttachments
            .map(file => file.serverId)
            .filter((id): id is string => Boolean(id)),
        });
      }

      let recoveryAttempts = 0;
      let observedPartialLength = 0;
      while (
        response.code === 'chat_answer_in_progress' &&
        recoveryAttempts < FOREGROUND_TURN_POLL_ATTEMPTS &&
        visibleRef.current &&
        sendGeneration === sendGenerationRef.current &&
        conversationGeneration === conversationGenerationRef.current
      ) {
        setMessages(current =>
          current.map(item =>
            item.id === pendingId
              ? {...item, pending: true, deliveryStatus: 'streaming'}
              : item,
          ),
        );
        // Spread queued clients over time instead of making every open chat
        // hit the status endpoint on the same fixed three-second boundary.
        const baseWaitMs = Math.max(
          response.partial ? 900 : 1500,
          (response.retryAfterSeconds || 3) * 1000,
        );
        const backoffMs = Math.min(
          6000,
          baseWaitMs * Math.pow(1.45, recoveryAttempts),
        );
        const jitterSeed = Array.from(clientRequestId).reduce(
          (sum, character) => sum + character.charCodeAt(0),
          0,
        );
        const waitMs = Math.round(backoffMs * (0.85 + (jitterSeed % 31) / 100));
        await new Promise<void>(resolve => setTimeout(resolve, waitMs));
        recoveryAttempts += 1;
        if (
          !visibleRef.current ||
          sendGeneration !== sendGenerationRef.current ||
          conversationGeneration !== conversationGenerationRef.current
        )
          break;
        response = await pollCourseAssistantTurn(clientRequestId);
        if (
          sendGeneration !== sendGenerationRef.current ||
          conversationGeneration !== conversationGenerationRef.current
        )
          return;
        if (response.partial && response.text) {
          const partialLength = response.text.length;
          if (partialLength > observedPartialLength) {
            observedPartialLength = partialLength;
            recoveryAttempts = 0;
          }
          setMessages(current =>
            current.map(item =>
              item.id === pendingId
                ? {
                    ...item,
                    text: response.text,
                    pending: true,
                    deliveryStatus: 'streaming',
                  }
                : item,
            ),
          );
        }
      }
      if (response.code === 'chat_answer_in_progress') {
        response = {
          ...response,
          text:
            response.partial && response.text
              ? response.text
              : 'الرد قيد التجهيز\nاستعده عند فتح الشات',
          unavailable: true,
          turnStatus: 'queued',
        };
      }
      if (
        conversationGeneration !== conversationGenerationRef.current ||
        sendGeneration !== sendGenerationRef.current
      )
        return;
      if (response.blocked) {
        setServerBlocked(true);
        setServerBlockCode(response.code || 'chat_upgrade_required');
      }
      const completed =
        !response.unavailable &&
        !response.blocked &&
        (!response.offline || isLocalDemoId(course.id));
      const acceptedPending =
        response.code === 'chat_answer_in_progress' &&
        ['queued', 'streaming'].includes(response.turnStatus || '');
      if (acceptedPending) {
        // The provider may legitimately outlive one foreground polling
        // window. Keep recovering this exact accepted turn when the chat is
        // still visible or when the learner opens it again; never require a
        // second send (and therefore a second paid provider call) merely
        // because the first answer was slow.
        resumeInterruptedTurnRef.current = true;
      }
      setMessages(current =>
        current.map(item => {
          if (item.id === userMessage.id) {
            if (completed) return item;
            if (acceptedPending) {
              return {...item, deliveryStatus: 'sent', contextEligible: false};
            }
            return {...item, deliveryStatus: 'failed', contextEligible: false};
          }
          return item.id === pendingId
            ? {
                ...item,
                text: response.text,
                pending: acceptedPending,
                clientRequestId: response.clientRequestId || clientRequestId,
                deliveryStatus: completed
                  ? 'completed'
                  : response.turnStatus || 'failed',
                errorCode: completed ? undefined : response.code,
                contextEligible: completed,
              }
            : item;
        }),
      );
    } catch (error: unknown) {
      if (
        conversationGeneration !== conversationGenerationRef.current ||
        sendGeneration !== sendGenerationRef.current ||
        (error instanceof Error &&
          error.message === 'ACCOUNT_CHANGED_DURING_REQUEST')
      )
        return;
      setMessages(current =>
        current.map(item =>
          item.id === userMessage.id
            ? {...item, deliveryStatus: 'failed', contextEligible: false}
            : item.id === pendingId
            ? {
                ...item,
                text: 'Rokn AI غير متاح الآن\nحاول مرة أخرى بعد قليل',
                pending: false,
                deliveryStatus: 'failed',
                errorCode: 'network_unavailable',
                contextEligible: false,
              }
            : item,
        ),
      );
    } finally {
      selectedAttachments.forEach(file =>
        inFlightAttachmentIdsRef.current.delete(file.uploadId),
      );
      if (sendFlightRef.current === flight) {
        sendFlightRef.current = null;
        if (conversationGeneration === conversationGenerationRef.current) {
          setSending(false);
          scheduleScrollToEnd(true, 80);
        }
      }
    }
  };

  const send = () => void runTurn();
  const isSendInFlight = () => Boolean(sendFlightRef.current);

  const retry = (clientRequestId: string) => {
    const userMessage = messages.find(
      item => item.role === 'user' && item.clientRequestId === clientRequestId,
    );
    if (userMessage) {
      void runTurn(clientRequestId, userMessage.text, userMessage.attachments);
    }
  };

  const stop = async () => {
    if (stopFlightRef.current?.conversation === conversationScope) return;
    const pending = [...messagesRef.current]
      .reverse()
      .find(
        item =>
          item.role === 'assistant' &&
          item.clientRequestId &&
          ['queued', 'sent', 'streaming'].includes(
            String(item.deliveryStatus || ''),
          ),
      );
    if (!pending?.clientRequestId) return;
    const stopFlight = Symbol('course-chat-stop');
    stopFlightRef.current = {
      conversation: conversationScope,
      flight: stopFlight,
    };
    const stoppedRequestId = pending.clientRequestId;
    const stopConversationGeneration = conversationGenerationRef.current;
    // Stop the local polling loop immediately. The cancellation request may
    // itself wait on a weak connection; keeping the composer frozen until its
    // timeout makes a working chat look hung.
    sendGenerationRef.current += 1;
    sendFlightRef.current = null;
    setSending(false);
    setMessages(current =>
      current.map(item =>
        item.clientRequestId === stoppedRequestId && item.role === 'assistant'
          ? {
              ...item,
              text: 'جارٍ إيقاف الرد',
              pending: false,
              deliveryStatus: 'queued',
              errorCode: 'chat_answer_in_progress',
              contextEligible: false,
            }
          : item,
      ),
    );
    try {
      let cancelledAtServer = false;
      try {
        cancelledAtServer = await cancelCourseAssistantTurn(stoppedRequestId);
      } catch {
        // Cancellation has an unknown outcome on a broken connection. The
        // accepted turn still belongs to this immutable request id, so resume
        // reconciliation instead of leaving the bubble at "stopping" forever
        // or sending a second paid turn.
        resumeInterruptedTurnRef.current = true;
      }
      if (!cancelledAtServer) {
        // The API intentionally reports transport errors as `false`, so this
        // path is the common unknown-outcome case as well as the thrown one.
        resumeInterruptedTurnRef.current = true;
      }
      if (
        stopConversationGeneration !== conversationGenerationRef.current ||
        activeConversationRef.current !== conversationScope
      ) {
        return;
      }
      setMessages(current =>
        current.map(item =>
          item.clientRequestId === stoppedRequestId
            ? cancelledAtServer
              ? {
                  ...item,
                  text:
                    item.role === 'assistant' ? 'تم إيقاف الرد' : item.text,
                  pending: false,
                  deliveryStatus: 'cancelled',
                  errorCode: 'learner_cancelled',
                  contextEligible: false,
                }
              : item.role === 'assistant'
              ? {
                  ...item,
                  text: 'الرد قيد التجهيز\nسيظهر عند فتح الشات',
                  pending: false,
                  deliveryStatus: 'queued',
                  errorCode: 'chat_answer_in_progress',
                }
              : {...item, deliveryStatus: 'sent', contextEligible: false}
            : item,
        ),
      );
    } finally {
      if (stopFlightRef.current?.flight === stopFlight) {
        stopFlightRef.current = null;
      }
    }
  };

  runTurnRef.current = runTurn;

  useEffect(() => {
    if (!interactive) {
      if (sendFlightRef.current) resumeInterruptedTurnRef.current = true;
      return;
    }
    if (!resumeInterruptedTurnRef.current || sending || sendFlightRef.current) {
      return;
    }

    // A provider turn may finish while Android shows a permission/system
    // surface or while the app is backgrounded. Recover the same immutable
    // client id on return; never submit a second paid turn merely because the
    // polling loop was suspended.
    resumeInterruptedTurnRef.current = false;
    const assistant = [...messagesRef.current]
      .reverse()
      .find(
        message =>
          message.role === 'assistant' &&
          Boolean(message.clientRequestId) &&
          (['queued', 'sent', 'streaming'].includes(
            String(message.deliveryStatus || ''),
          ) ||
            ['chat_answer_in_progress', 'interrupted_turn'].includes(
              String(message.errorCode || ''),
            )),
      );
    if (!assistant?.clientRequestId) return;
    const user = messagesRef.current.find(
      message =>
        message.role === 'user' &&
        message.clientRequestId === assistant.clientRequestId,
    );
    if (user) {
      void runTurnRef.current(
        assistant.clientRequestId,
        user.text,
        user.attachments,
      );
    }
  }, [interactive, messages, sending]);

  const loadUpgradeQuote = async () => {
    if (upgradeLoading || upgradeFlightRef.current) return;
    const flight = Symbol('course-chat-upgrade-quote');
    const upgradeGeneration = upgradeGenerationRef.current;
    upgradeFlightRef.current = flight;
    setUpgradeLoading(true);
    setUpgradeError('');
    try {
      const quote = await getCourseChatUpgradeQuote(courseId);
      if (
        activeCourseIdRef.current !== courseId ||
        upgradeGenerationRef.current !== upgradeGeneration
      )
        return;
      if (quote.alreadyUpgraded || quote.chatAvailable) {
        setUpgraded(true);
        setServerBlocked(false);
        setServerBlockCode('');
        setUpgradeQuote(null);
        return;
      }
      setUpgradeQuote(quote);
    } catch (error: unknown) {
      if (
        activeCourseIdRef.current !== courseId ||
        upgradeGenerationRef.current !== upgradeGeneration
      )
        return;
      const code = courseChatErrorCode(error);
      setUpgradeError(
        code === 'chat_upgrade_not_priced' ||
          code === 'full_track_upgrade_not_priced'
          ? 'الترقية غير متاحة لهذا الكورس الآن'
          : code === 'course_access_required'
          ? 'افتح الكورس أولًا ثم عد إلى Rokn AI'
          : 'تعذّر تحميل تفاصيل الترقية\nحاول مرة أخرى',
      );
    } finally {
      if (
        upgradeGenerationRef.current === upgradeGeneration &&
        upgradeFlightRef.current === flight
      ) {
        upgradeFlightRef.current = null;
        setUpgradeLoading(false);
      }
    }
  };

  const confirmUpgrade = async () => {
    if (!upgradeQuote || upgradeLoading || upgradeFlightRef.current) return;
    if (upgradeQuote.deficit > 0) {
      onOpenWallet();
      return;
    }
    const flight = Symbol('course-chat-upgrade-purchase');
    const upgradeGeneration = upgradeGenerationRef.current;
    upgradeFlightRef.current = flight;
    setUpgradeLoading(true);
    setUpgradeError('');
    try {
      const result = await purchaseCourseChatUpgrade(
        courseId,
        upgradeQuote.targetPlanCode,
        upgradeQuote.price,
        upgradeQuote.courseRevision,
      );
      if (
        activeCourseIdRef.current !== courseId ||
        upgradeGenerationRef.current !== upgradeGeneration
      )
        return;
      if (result.alreadyUpgraded || result.chatAvailable) {
        setUpgraded(true);
        setServerBlocked(false);
        setServerBlockCode('');
        setUpgradeQuote(null);
        return;
      }
      setUpgradeQuote(result);
    } catch (error: unknown) {
      if (
        activeCourseIdRef.current !== courseId ||
        upgradeGenerationRef.current !== upgradeGeneration
      )
        return;
      const code = courseChatErrorCode(error);
      setUpgradeError(
        code === 'course_price_changed' || code === 'course_terms_changed'
          ? 'تغيرت تفاصيل الفئة\nراجعها قبل الترقية'
          : code === 'insufficient_coins'
          ? 'تغيّر الرصيد المتاح\nراجع المبلغ الناقص قبل الترقية'
          : 'تعذّر تأكيد النتيجة\nنتحقق منها الآن',
      );
      try {
        const refreshedQuote = await getCourseChatUpgradeQuote(courseId);
        if (
          activeCourseIdRef.current !== courseId ||
          upgradeGenerationRef.current !== upgradeGeneration
        )
          return;
        if (refreshedQuote.alreadyUpgraded || refreshedQuote.chatAvailable) {
          setUpgraded(true);
          setServerBlocked(false);
          setServerBlockCode('');
          setUpgradeQuote(null);
          setUpgradeError('');
          return;
        }
        setUpgradeQuote(refreshedQuote);
        setUpgradeError(
          code === 'course_price_changed' || code === 'course_terms_changed'
            ? 'تغيرت تفاصيل الفئة\nراجعها قبل الترقية'
            : code === 'insufficient_coins'
            ? 'تغيّر الرصيد المتاح\nراجع المبلغ الناقص قبل الترقية'
            : 'لم تكتمل العملية\nحاول مرة أخرى',
        );
      } catch {
        if (
          activeCourseIdRef.current !== courseId ||
          upgradeGenerationRef.current !== upgradeGeneration
        )
          return;
        setUpgradeError(
          'تعذّر تأكيد النتيجة\nحدّث الصفحة قبل المحاولة مرة أخرى',
        );
      }
    } finally {
      if (
        upgradeGenerationRef.current === upgradeGeneration &&
        upgradeFlightRef.current === flight
      ) {
        upgradeFlightRef.current = null;
        setUpgradeLoading(false);
      }
    }
  };

  return {
    assistantPresence,
    assistantIncluded,
    attachments,
    chatAccessUnavailable,
    confirmUpgrade,
    input,
    loadUpgradeQuote,
    messages,
    planLimitReached,
    scholarshipAccess,
    scrollRef,
    retry,
    send,
    isSendInFlight,
    sending,
    stop,
    setInput,
    setAttachments,
    upgradeError,
    upgradeLoading,
    upgradeQuote,
  };
};
