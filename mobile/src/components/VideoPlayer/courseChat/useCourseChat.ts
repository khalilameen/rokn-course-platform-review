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
} from '../courseLearningApi';
import {isGrantCourseAccess} from '../courseEntitlements';
import type {ChatMessage, CourseLearningData, CourseReel} from '../types';
import {courseChatErrorCode} from './policy';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {cleanUnicodeText} from '../../../utils/unicodeText';
import {isLocalDemoId} from '../../../config/runtime';
import {loadCourseChatHistory, saveCourseChatHistory} from './persistence';
import {useAppActiveState} from '../../../hooks/useAppActiveState';
import {getCurrentAccountStorageScope} from '../../../constants/helpers';

export type AssistantPresence = 'online' | 'working';
const MAX_IN_MEMORY_MESSAGES = 37;

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
  const [upgraded, setUpgraded] = useState(false);
  const [upgradeQuote, setUpgradeQuote] =
    useState<CourseChatUpgradeQuote | null>(null);
  const [upgradeLoading, setUpgradeLoading] = useState(false);
  const [upgradeError, setUpgradeError] = useState('');
  const assistantIncluded =
    (courseIncludesAssistant(course) || upgraded) && !serverBlocked;
  const scholarshipAccess = isGrantCourseAccess(course.accessType);
  const planLimitReached = serverBlocked && courseIncludesAssistant(course);
  const [messages, setMessages] = useState<ChatMessage[]>(() => [
    welcomeMessage(courseId),
  ]);
  const [accountEpoch, setAccountEpoch] = useState(0);
  const [input, setInput] = useState('');
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
  const upgradeFlightRef = useRef<symbol | null>(null);
  const upgradeGenerationRef = useRef(0);
  const resumeInterruptedTurnRef = useRef(false);
  const messagesRef = useRef(messages);
  const runTurnRef = useRef<
    (clientRequestId?: string, message?: string) => Promise<void>
  >(async () => undefined);
  activeCourseIdRef.current = courseId;
  activeConversationRef.current = conversationScope;
  visibleRef.current = interactive;
  messagesRef.current = messages;

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
      messagesRef.current = initialMessages;
      setMessages(initialMessages);

      try {
        const remoteHistory = await loadCourseAssistantHistory(courseId, lessonId);
        const currentAccountScope = await getCurrentAccountStorageScope();
        if (
          conversationGeneration !== conversationGenerationRef.current ||
          activeConversationRef.current !== conversationScope ||
          currentAccountScope !== accountScope
        ) return;
        const renderedLocalHistory = messagesRef.current.filter(
          message => !message.id.startsWith('welcome-'),
        );
        const currentLocalHistory =
          renderedLocalHistory.length > 0
            ? renderedLocalHistory
            : localHistory;
        const remoteKeys = new Set(
          remoteHistory.map(message => `${message.role}:${message.clientRequestId || message.id}`),
        );
        const history = [
          ...remoteHistory,
          ...currentLocalHistory.filter(message =>
            !remoteKeys.has(`${message.role}:${message.clientRequestId || message.id}`),
          ),
        ];
        const reconciled = [
          welcomeMessage(courseId),
          ...trimConversation(history),
        ];
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
    void saveCourseChatHistory(courseId, messages, lessonId).catch(() => undefined);
  }, [conversationScope, courseId, lessonId, messages]);

  useEffect(() => {
    if (messages.length <= MAX_IN_MEMORY_MESSAGES) return;
    setMessages(current => trimConversation(current));
  }, [messages.length]);

  useEffect(() => {
    upgradeGenerationRef.current += 1;
    upgradeFlightRef.current = null;
    setServerBlocked(false);
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
  ) => {
    const cleanMessage = cleanUnicodeText(retryMessage ?? input);
    if (
      !cleanMessage ||
      sending ||
      sendFlightRef.current ||
      !assistantIncluded ||
      hydratedConversationRef.current !== conversationScope
    ) {
      return;
    }
    const flight = Symbol('course-chat-send');
    let clientRequestId = retryClientRequestId || secureRandomUuid();
    sendFlightRef.current = flight;
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
      text: cleanMessage,
      createdAt: Date.now(),
      clientRequestId,
      deliveryStatus: 'sent',
      contextEligible: true,
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
    if (!retryMessage) setInput('');
    setSending(true);
    scheduleScrollToEnd(true, 80);

    try {
      // Persist the outbox before the paid request leaves the phone. If the
      // process dies after this point, the same client id can recover the
      // accepted server response without another provider call or debit.
      await saveCourseChatHistory(courseId, queuedMessages, lessonId).catch(
        () => undefined,
      );
      let response = await askCourseAssistant({
        course: upgraded
          ? {...course, accessType: 'paid', chatAvailable: true}
          : course,
        reel,
        message: cleanMessage,
        clientRequestId,
      });

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
        await saveCourseChatHistory(courseId, queuedMessages, lessonId).catch(
          () => undefined,
        );
        response = await askCourseAssistant({
          course: upgraded
            ? {...course, accessType: 'paid', chatAvailable: true}
            : course,
          reel,
          message: cleanMessage,
          clientRequestId,
        });
      }

      let recoveryAttempts = 0;
      while (
        response.code === 'chat_answer_in_progress' &&
        recoveryAttempts < 20 &&
        visibleRef.current &&
        conversationGeneration === conversationGenerationRef.current
      ) {
        setMessages(current =>
          current.map(item =>
            item.id === pendingId
              ? {...item, pending: true, deliveryStatus: 'streaming'}
              : item,
          ),
        );
        const waitMs = Math.max(
          1000,
          Math.min(5000, (response.retryAfterSeconds || 3) * 1000),
        );
        await new Promise<void>(resolve => setTimeout(resolve, waitMs));
        recoveryAttempts += 1;
        if (!visibleRef.current) break;
        response = await askCourseAssistant({
          course: upgraded
            ? {...course, accessType: 'paid', chatAvailable: true}
            : course,
          reel,
          message: cleanMessage,
          clientRequestId,
        });
      }
      if (response.code === 'chat_answer_in_progress') {
        response = {
          ...response,
          text: 'الرد قيد التجهيز\nاستعده عند فتح الشات',
          unavailable: true,
          turnStatus: 'failed',
        };
      }
      if (conversationGeneration !== conversationGenerationRef.current) return;
      if (
        response.blocked &&
        ['chat_upgrade_required', 'chat_plan_limit_reached'].includes(
          response.code || '',
        )
      ) {
        setServerBlocked(true);
      }
      const completed =
        !response.unavailable &&
        !response.blocked &&
        (!response.offline || isLocalDemoId(course.id));
      setMessages(current =>
        current.map(item => {
          if (item.id === userMessage.id) {
            return completed
              ? item
              : {...item, deliveryStatus: 'failed', contextEligible: false};
          }
          return item.id === pendingId
            ? {
                ...item,
                text: response.text,
                pending: false,
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
    } catch {
      if (conversationGeneration !== conversationGenerationRef.current) return;
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

  const retry = (clientRequestId: string) => {
    const userMessage = messages.find(
      item => item.role === 'user' && item.clientRequestId === clientRequestId,
    );
    if (userMessage) void runTurn(clientRequestId, userMessage.text);
  };

  runTurnRef.current = runTurn;

  useEffect(() => {
    if (!interactive) {
      if (sendFlightRef.current) resumeInterruptedTurnRef.current = true;
      return;
    }
    if (
      !resumeInterruptedTurnRef.current ||
      sending ||
      sendFlightRef.current
    ) {
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
    if (user) void runTurnRef.current(assistant.clientRequestId, user.text);
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
      );
      if (
        activeCourseIdRef.current !== courseId ||
        upgradeGenerationRef.current !== upgradeGeneration
      )
        return;
      if (result.alreadyUpgraded || result.chatAvailable) {
        setUpgraded(true);
        setServerBlocked(false);
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
      if (code === 'insufficient_coins' || code === 'course_price_changed') {
        try {
          const refreshedQuote = await getCourseChatUpgradeQuote(courseId);
          if (
            activeCourseIdRef.current === courseId &&
            upgradeGenerationRef.current === upgradeGeneration
          ) {
            setUpgradeQuote(refreshedQuote);
          }
        } catch {
          // The last confirmed quote remains actionable when refresh fails.
        }
        setUpgradeError(
          code === 'course_price_changed'
            ? 'تغير السعر\nراجع الإجمالي قبل الترقية'
            : 'تغيّر الرصيد المتاح\nراجع المبلغ الناقص قبل الترقية',
        );
      } else {
        setUpgradeError('لم يتم الخصم\nحاول مرة أخرى');
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
    confirmUpgrade,
    input,
    loadUpgradeQuote,
    messages,
    planLimitReached,
    scholarshipAccess,
    scrollRef,
    retry,
    send,
    sending,
    setInput,
    upgradeError,
    upgradeLoading,
    upgradeQuote,
  };
};
