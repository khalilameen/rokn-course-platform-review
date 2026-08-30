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
} from '../courseLearningApi';
import {isGrantCourseAccess} from '../courseEntitlements';
import type {ChatMessage, CourseLearningData, CourseReel} from '../types';
import {courseChatErrorCode} from './policy';

export type AssistantPresence = 'online' | 'connecting' | 'typing';

const welcomeMessage = (courseId: string): ChatMessage => ({
  id: `welcome-${courseId}`,
  role: 'assistant',
  text: 'حاجة واقفة معاك؟\nمحتاجني أشرحلك؟\nقابلك معنى مش مفهوم؟\nمصطلح غريب؟\nعايز مثال أو شرح أكتر أو تلخيص؟',
  createdAt: Date.now(),
});

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
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [assistantPresence, setAssistantPresence] =
    useState<AssistantPresence>('online');
  const scrollRef = useRef<ScrollView>(null);
  const scrollTimersRef = useRef(new Set<ReturnType<typeof setTimeout>>());
  const conversationGenerationRef = useRef(0);
  const activeCourseIdRef = useRef(courseId);
  const sendFlightRef = useRef<symbol | null>(null);
  const upgradeFlightRef = useRef<symbol | null>(null);
  activeCourseIdRef.current = courseId;

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
    sendFlightRef.current = null;
    setMessages([welcomeMessage(courseId)]);
    setInput('');
    setSending(false);
    setAssistantPresence('online');
    return () => {
      conversationGenerationRef.current += 1;
      sendFlightRef.current = null;
    };
  }, [courseId, visible]);

  useEffect(() => {
    upgradeFlightRef.current = null;
    setServerBlocked(false);
    setUpgraded(false);
    setUpgradeQuote(null);
    setUpgradeError('');
    setUpgradeLoading(false);
    return () => {
      upgradeFlightRef.current = null;
    };
  }, [courseId]);

  useEffect(() => {
    const scrollTimers = scrollTimersRef.current;
    if (visible) scheduleScrollToEnd(false, 100);
    return () => {
      scrollTimers.forEach(clearTimeout);
      scrollTimers.clear();
    };
  }, [scheduleScrollToEnd, visible]);

  const send = async () => {
    const cleanMessage = input.trim();
    if (
      !cleanMessage ||
      sending ||
      sendFlightRef.current ||
      !assistantIncluded
    ) {
      return;
    }
    const flight = Symbol('course-chat-send');
    sendFlightRef.current = flight;
    const userMessage: ChatMessage = {
      id: `user-${Date.now()}`,
      role: 'user',
      text: cleanMessage,
      createdAt: Date.now(),
    };
    const pendingId = `assistant-${Date.now()}`;
    const conversationGeneration = conversationGenerationRef.current;
    setMessages(current => [
      ...current,
      userMessage,
      {
        id: pendingId,
        role: 'assistant',
        text: '',
        createdAt: Date.now(),
        pending: true,
      },
    ]);
    setInput('');
    setSending(true);
    setAssistantPresence('connecting');
    scheduleScrollToEnd(true, 80);

    try {
      const response = await askCourseAssistant({
        course: upgraded
          ? {...course, accessType: 'paid', chatAvailable: true}
          : course,
        reel,
        message: cleanMessage,
        onRequestStart: () => {
          if (conversationGeneration === conversationGenerationRef.current) {
            setAssistantPresence('typing');
          }
        },
      });
      if (conversationGeneration !== conversationGenerationRef.current) return;
      if (response.blocked) setServerBlocked(true);
      setMessages(current =>
        current.map(message =>
          message.id === pendingId
            ? {...message, text: response.text, pending: false}
            : message,
        ),
      );
    } catch {
      if (conversationGeneration !== conversationGenerationRef.current) return;
      setMessages(current =>
        current.map(message =>
          message.id === pendingId
            ? {
                ...message,
                text: 'Rokn AI غير متاح للحظات، جرّب سؤالك تاني بعد شوية.',
                pending: false,
              }
            : message,
        ),
      );
    } finally {
      if (sendFlightRef.current === flight) {
        sendFlightRef.current = null;
        if (conversationGeneration === conversationGenerationRef.current) {
          setSending(false);
          setAssistantPresence('online');
          scheduleScrollToEnd(true, 80);
        }
      }
    }
  };

  const loadUpgradeQuote = async () => {
    if (upgradeLoading || upgradeFlightRef.current) return;
    const flight = Symbol('course-chat-upgrade-quote');
    upgradeFlightRef.current = flight;
    setUpgradeLoading(true);
    setUpgradeError('');
    try {
      const quote = await getCourseChatUpgradeQuote(courseId);
      if (activeCourseIdRef.current !== courseId) return;
      if (quote.alreadyUpgraded || quote.chatAvailable) {
        setUpgraded(true);
        setServerBlocked(false);
        setUpgradeQuote(null);
        return;
      }
      setUpgradeQuote(quote);
    } catch (error: unknown) {
      if (activeCourseIdRef.current !== courseId) return;
      const code = courseChatErrorCode(error);
      setUpgradeError(
        code === 'chat_upgrade_not_priced' ||
          code === 'full_track_upgrade_not_priced'
          ? 'الترقية غير متاحة لهذا الكورس الآن'
          : code === 'course_access_required'
          ? 'افتح الكورس أولًا ثم ارجع لـ Rokn AI'
          : 'تعذّر تحميل تفاصيل الترقية الآن جرّب مرة تانية',
      );
    } finally {
      if (upgradeFlightRef.current === flight) {
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
    upgradeFlightRef.current = flight;
    setUpgradeLoading(true);
    setUpgradeError('');
    try {
      const result = await purchaseCourseChatUpgrade(
        courseId,
        upgradeQuote.targetPlanCode,
      );
      if (activeCourseIdRef.current !== courseId) return;
      if (result.alreadyUpgraded || result.chatAvailable) {
        setUpgraded(true);
        setServerBlocked(false);
        setUpgradeQuote(null);
        return;
      }
      setUpgradeQuote(result);
    } catch (error: unknown) {
      if (activeCourseIdRef.current !== courseId) return;
      if (courseChatErrorCode(error) === 'insufficient_coins') {
        try {
          const refreshedQuote = await getCourseChatUpgradeQuote(courseId);
          if (activeCourseIdRef.current === courseId) {
            setUpgradeQuote(refreshedQuote);
          }
        } catch {
          // The last confirmed quote remains actionable when refresh fails.
        }
        setUpgradeError('الرصيد المتاح اتغيّر راجع الناقص قبل الترقية');
      } else {
        setUpgradeError('لم يتم الخصم ولم تتغير صلاحيتك جرّب مرة تانية');
      }
    } finally {
      if (upgradeFlightRef.current === flight) {
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
    send,
    sending,
    setInput,
    upgradeError,
    upgradeLoading,
    upgradeQuote,
  };
};
