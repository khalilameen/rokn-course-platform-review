import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import type {ChatMessage} from '../types';
import {
  learnerDraftFileIsReadable,
  retainLearnerDraftFiles,
} from '../../../services/learnerDraftFiles';
import {cleanUnicodeText, truncateGraphemes} from '../../../utils/unicodeText';

const COURSE_CHAT_HISTORY_PREFIX = '@rokn/course-chat-history/v2';
const MAX_STORED_MESSAGES = 36;
const writeFlights = new Map<string, Promise<void>>();
let persistenceGeneration = 0;
const ACTIVE_STATUSES = new Set(['queued', 'sent', 'streaming']);
const DELIVERY_STATUSES = new Set([
  'queued',
  'sent',
  'streaming',
  'completed',
  'failed',
  'cancelled',
]);

const historyKey = async (
  courseId: string,
  lessonId?: string,
  boundary?: AccountSessionBoundary,
) =>
  `${COURSE_CHAT_HISTORY_PREFIX}:${boundary?.scope || (await captureAccountSessionBoundary()).scope}:${encodeURIComponent(
    courseId,
  )}:${encodeURIComponent(lessonId || 'course')}`;
const referenceOwner = (courseId: string, lessonId?: string) =>
  `course-chat:${encodeURIComponent(courseId)}:${encodeURIComponent(lessonId || 'course')}`;

const normaliseStoredMessage = (value: unknown): ChatMessage | null => {
  if (!value || typeof value !== 'object') return null;
  const record = value as Record<string, unknown>;
  const role = record.role;
  const status = record.deliveryStatus;
  const text =
    typeof record.text === 'string'
      ? truncateGraphemes(cleanUnicodeText(record.text), 12000)
      : '';
  const id = typeof record.id === 'string' ? record.id.slice(0, 160) : '';
  if (
    !id ||
    (role !== 'assistant' && role !== 'user') ||
    (status !== undefined && !DELIVERY_STATUSES.has(String(status)))
  ) {
    return null;
  }

  const interrupted = status !== undefined && ACTIVE_STATUSES.has(String(status));
  const attachments = Array.isArray(record.attachments)
    ? record.attachments.flatMap(attachmentValue => {
        if (!attachmentValue || typeof attachmentValue !== 'object') return [];
        const file = attachmentValue as Record<string, unknown>;
        const uploadId = typeof file.uploadId === 'string'
          ? file.uploadId.slice(0, 100) : '';
        const serverId = typeof file.serverId === 'string'
          ? file.serverId.slice(0, 100) : undefined;
        const uri = typeof file.uri === 'string' ? file.uri.slice(0, 2048) : '';
        if (!uploadId || (!serverId && !uri)) return [];
        return [{
          uploadId,
          serverId,
          uri,
          name: typeof file.name === 'string' ? file.name.slice(0, 240) : 'مرفق',
          type: typeof file.type === 'string'
            ? file.type.slice(0, 120) : 'application/octet-stream',
          size: Number.isFinite(Number(file.size)) ? Number(file.size) : undefined,
          downloadUrl: typeof file.downloadUrl === 'string'
            ? file.downloadUrl.slice(0, 2048) : undefined,
        }];
      })
    : [];
  return {
    id,
    role,
    text:
      interrupted && role === 'assistant' && !text
        ? 'انقطع الاتصال قبل وصول الرد\nاستعد الرد'
        : text,
    createdAt:
      typeof record.createdAt === 'number' && Number.isFinite(record.createdAt)
        ? record.createdAt
        : Date.now(),
    pending: false,
    clientRequestId:
      typeof record.clientRequestId === 'string'
        ? record.clientRequestId.slice(0, 100)
        : undefined,
    deliveryStatus: interrupted
      ? 'failed'
      : (status as ChatMessage['deliveryStatus']),
    errorCode: interrupted
      ? 'interrupted_turn'
      : typeof record.errorCode === 'string'
      ? record.errorCode.slice(0, 80)
      : undefined,
    contextEligible:
      !interrupted &&
      status === 'completed' &&
      record.contextEligible !== false,
    attachments,
  };
};

export const loadCourseChatHistory = async (
  courseId: string,
  lessonId?: string,
): Promise<ChatMessage[]> => {
  const boundary = await captureAccountSessionBoundary();
  try {
    const raw = await AsyncStorage.getItem(await historyKey(courseId, lessonId, boundary));
    assertAccountSessionBoundary(boundary);
    const parsed = raw ? JSON.parse(raw) : [];
    if (!Array.isArray(parsed)) return [];
    const messages = parsed
      .map(normaliseStoredMessage)
      .filter((message): message is ChatMessage => Boolean(message))
      .slice(-MAX_STORED_MESSAGES);
    for (const message of messages) {
      if (!message.attachments?.length) continue;
      const readable = [];
      for (const file of message.attachments) {
        if (file.serverId || (file.uri && await learnerDraftFileIsReadable(file))) {
          readable.push(file);
        }
      }
      message.attachments = readable;
    }
    await retainLearnerDraftFiles(
      referenceOwner(courseId, lessonId),
      messages.flatMap(message => message.attachments || []).filter(file => !file.serverId),
      boundary.scope,
    );
    assertAccountSessionBoundary(boundary);
    return messages;
  } catch {
    await retainLearnerDraftFiles(referenceOwner(courseId, lessonId), [], boundary.scope).catch(
      () => undefined,
    );
    return [];
  }
};

export const saveCourseChatHistory = async (
  courseId: string,
  messages: ChatMessage[],
  lessonId?: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const generation = persistenceGeneration;
  const durable = messages
    .filter(message => !message.id.startsWith('welcome-'))
    .slice(-MAX_STORED_MESSAGES)
    .map(message => ({
      id: message.id,
      role: message.role,
      text: truncateGraphemes(cleanUnicodeText(message.text), 12000),
      createdAt: message.createdAt,
      pending: message.pending === true,
      clientRequestId: message.clientRequestId,
      deliveryStatus: message.deliveryStatus,
      errorCode: message.errorCode,
      contextEligible: message.contextEligible === true,
      attachments: message.attachments?.map(file => ({
        uri: file.serverId ? '' : file.uri,
        name: file.name,
        type: file.type,
        size: file.size,
        uploadId: file.uploadId,
        serverId: file.serverId,
        downloadUrl: file.downloadUrl,
      })),
    }));
  const key = await historyKey(courseId, lessonId, boundary);
  const previous = writeFlights.get(key) || Promise.resolve();
  const write = previous
    .catch(() => undefined)
    .then(async () => {
      if (generation !== persistenceGeneration) return;
      assertAccountSessionBoundary(boundary);
      await retainLearnerDraftFiles(
        referenceOwner(courseId, lessonId),
        messages.flatMap(message => message.attachments || []).filter(file => !file.serverId),
        boundary.scope,
      );
      await AsyncStorage.setItem(key, JSON.stringify(durable));
      assertAccountSessionBoundary(boundary);
    });
  writeFlights.set(key, write);
  try {
    await write;
  } finally {
    if (writeFlights.get(key) === write) writeFlights.delete(key);
  }
};

/**
 * Stop queued history writes before logout removes their scoped keys. Waiting
 * here prevents an already-started AsyncStorage write from recreating private
 * chat history after cleanup has completed.
 */
export const quiesceCourseChatPersistence = async () => {
  persistenceGeneration += 1;
  const pending = Array.from(writeFlights.values());
  await Promise.allSettled(pending);
  writeFlights.clear();
};
