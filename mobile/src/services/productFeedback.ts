import {Dimensions, Platform} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';

import appConfig from '../../app.json';
import {publicRequest} from '../constants/api';
import {accountScopedStorageKey} from '../constants/helpers';
import {firstBoolean} from './api/common';
import {
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
} from './learnerDraftFiles';

export type ProductFeedbackCategory =
  | 'problem'
  | 'idea'
  | 'content'
  | 'playback';

export type FeedbackAttachment = {
  fileName?: string;
  size?: number;
  type?: string;
  uri: string;
};

export type ProductFeedbackContext = {
  locale?: string;
  sourceScreen?: string;
};

export type ProductFeedbackDraft = {
  attachment?: FeedbackAttachment;
  category: ProductFeedbackCategory;
  clientRequestId: string;
  message: string;
  updatedAt: number;
};

export type ProductFeedbackReceipt = {
  accessToken?: string;
  caseNumber: string;
  createdAt: string;
  messages: ProductFeedbackMessage[];
  publicId: string;
  replayed: boolean;
  status: string;
};

export type ProductFeedbackMessage = {
  author: 'learner' | 'support';
  createdAt: string;
  hasAttachment: boolean;
  publicId: string;
  text: string;
};

export type ProductFeedbackCase = Omit<ProductFeedbackReceipt, 'replayed'> & {
  category: string;
  message: string;
  updatedAt: string;
};

type StoredCaseReceipt = {
  accessToken?: string;
  publicId: string;
  updatedAt: number;
};

const DRAFT_KEY = '@rokn/product-feedback-draft/v1';
const RECEIPTS_KEY = '@rokn/product-feedback-receipts/v1';
const REPLY_DRAFT_PREFIX = '@rokn/product-feedback-reply/v1:';
const DRAFT_TTL_MS = 30 * 24 * 60 * 60 * 1000;
let draftOperation: Promise<unknown> = Promise.resolve();

const withDraftLock = <T>(operation: () => Promise<T>) => {
  const result = draftOperation.then(operation, operation);
  draftOperation = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

const backendCategory: Record<ProductFeedbackCategory, string> = {
  problem: 'bug',
  idea: 'suggestion',
  content: 'course_content',
  playback: 'playback',
};

const normalizeScreenKey = (value?: string) => {
  const normalized = String(value || 'feedback')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 64);
  return normalized || 'feedback';
};

const osMajor = () => {
  const value = Number.parseInt(String(Platform.Version).split('.')[0], 10);
  return Number.isInteger(value) && value > 0 && value <= 255
    ? value
    : undefined;
};

const buildNumber = () => {
  const value = Number(
    Platform.OS === 'ios'
      ? appConfig.expo.ios?.buildNumber
      : appConfig.expo.android?.versionCode,
  );
  return Number.isInteger(value) && value > 0 ? value : undefined;
};

type NativeUpload = {name: string; type: string; uri: string};
type NativeFormData = FormData & {
  append(name: string, value: string | NativeUpload): void;
};

const createFeedbackBody = ({
  attachment,
  category,
  context,
  message,
  clientRequestId,
}: {
  attachment?: FeedbackAttachment;
  category: ProductFeedbackCategory;
  context?: ProductFeedbackContext;
  message: string;
  clientRequestId: string;
}) => {
  const screen = Dimensions.get('window');
  const form = new FormData() as NativeFormData;
  form.append('client_request_id', clientRequestId);
  form.append('category', backendCategory[category]);
  form.append('message', message.trim());
  form.append('platform', Platform.OS);
  form.append('app_version', appConfig.expo.version);
  form.append('screen_key', normalizeScreenKey(context?.sourceScreen));
  form.append('locale', String(context?.locale || 'ar').slice(0, 16));
  form.append(
    'screen_size',
    `${Math.round(screen.width)}x${Math.round(screen.height)}`,
  );
  form.append('font_scale', String(screen.fontScale || 1));
  const currentBuildNumber = buildNumber();
  if (currentBuildNumber) {
    form.append('build_number', String(currentBuildNumber));
  }
  const currentOsMajor = osMajor();
  if (currentOsMajor) {
    form.append('os_major', String(currentOsMajor));
  }
  form.append('device_tier', 'unknown');
  form.append('network_type', 'unknown');
  if (attachment) {
    form.append('screenshot', {
      name: attachment.fileName || `rokn-feedback-${Date.now()}.jpg`,
      type: attachment.type || 'image/jpeg',
      uri: attachment.uri,
    });
  }
  return form;
};

/**
 * Feedback remains server-owned and session-sized. The screenshot is uploaded
 * from its picker URI and is never copied into Rokn's persistent storage.
 */
export const submitProductFeedback = async (input: {
  attachment?: FeedbackAttachment;
  category: ProductFeedbackCategory;
  context?: ProductFeedbackContext;
  message: string;
  clientRequestId: string;
}): Promise<ProductFeedbackReceipt> => {
  const response = await publicRequest.post(
    'feedback',
    createFeedbackBody(input),
    {
      timeout: 30000,
      headers: {'Idempotency-Key': input.clientRequestId},
    },
  );
  const payload =
    (response.data as {data?: Record<string, unknown>})?.data || {};
  const publicId = String(payload.public_id || '').trim();
  const createdAt = String(payload.created_at || '').trim();
  if (
    !/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(publicId) ||
    !createdAt ||
    !Number.isFinite(Date.parse(createdAt))
  ) {
    throw new Error('INVALID_FEEDBACK_RECEIPT');
  }
  const receipt = {
    accessToken: safeAccessToken(payload.access_token),
    caseNumber: safeCaseNumber(payload.case_number, publicId),
    publicId,
    status: String(payload.status || 'new'),
    createdAt,
    replayed: firstBoolean(payload.replayed) ?? false,
    messages: parseMessages(payload.messages),
  };
  await rememberCaseReceipt(receipt).catch(() => undefined);
  return receipt;
};

const safeAccessToken = (value: unknown) => {
  const token = String(value || '').trim();
  return /^[A-Za-z0-9_-]{32,128}$/.test(token) ? token : undefined;
};

const safeCaseNumber = (value: unknown, publicId: string) => {
  const candidate = String(value || '').trim().toUpperCase();
  return /^[0-9A-Z]{6,12}$/.test(candidate)
    ? candidate
    : publicId.slice(-8).toUpperCase();
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null;

const parseMessages = (value: unknown): ProductFeedbackMessage[] =>
  (Array.isArray(value) ? value : [])
    .map(item => {
      if (!isRecord(item)) return null;
      const publicId = String(item.public_id || '').trim();
      const text = String(item.text || '').trim();
      const createdAt = String(item.created_at || '').trim();
      if (!publicId || !text || !Number.isFinite(Date.parse(createdAt))) return null;
      return {
        author: item.author === 'learner' ? ('learner' as const) : ('support' as const),
        createdAt,
        hasAttachment: firstBoolean(item.has_attachment) ?? false,
        publicId,
        text,
      };
    })
    .filter((item): item is ProductFeedbackMessage => item !== null);

const parseCase = (value: unknown): ProductFeedbackCase => {
  if (!isRecord(value)) throw new Error('INVALID_SUPPORT_CASE');
  const publicId = String(value.public_id || '').trim();
  const createdAt = String(value.created_at || '').trim();
  const updatedAt = String(value.updated_at || '').trim();
  if (
    !/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(publicId) ||
    !Number.isFinite(Date.parse(createdAt)) ||
    !Number.isFinite(Date.parse(updatedAt))
  ) {
    throw new Error('INVALID_SUPPORT_CASE');
  }
  return {
    caseNumber: safeCaseNumber(value.case_number, publicId),
    category: String(value.category || 'bug'),
    createdAt,
    message: String(value.message || '').trim(),
    messages: parseMessages(value.messages),
    publicId,
    status: String(value.status || 'in_progress'),
    updatedAt,
  };
};

const loadStoredReceipts = async (): Promise<StoredCaseReceipt[]> => {
  const key = await accountScopedStorageKey(RECEIPTS_KEY);
  const raw = await AsyncStorage.getItem(key);
  if (!raw) return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    return (Array.isArray(parsed) ? parsed : [])
      .map((item: unknown): StoredCaseReceipt | null => {
        if (!isRecord(item)) return null;
        const publicId = String(item.publicId || '').trim();
        if (!/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(publicId)) return null;
        return {
          publicId,
          accessToken: safeAccessToken(item.accessToken),
          updatedAt: Number(item.updatedAt) || 0,
        };
      })
      .filter((item: StoredCaseReceipt | null): item is StoredCaseReceipt => item !== null)
      .slice(0, 20);
  } catch {
    await AsyncStorage.removeItem(key);
    return [];
  }
};

const rememberCaseReceipt = async (receipt: ProductFeedbackReceipt) => {
  const key = await accountScopedStorageKey(RECEIPTS_KEY);
  const current = await loadStoredReceipts();
  const next = [
    {publicId: receipt.publicId, accessToken: receipt.accessToken, updatedAt: Date.now()},
    ...current.filter(item => item.publicId !== receipt.publicId),
  ].slice(0, 20);
  await AsyncStorage.setItem(key, JSON.stringify(next));
};

const accessHeaders = (accessToken?: string) =>
  accessToken ? {'X-Support-Access': accessToken} : undefined;

export const loadProductFeedbackCase = async (
  publicId: string,
  accessToken?: string,
): Promise<ProductFeedbackCase> => {
  if (!/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(publicId)) {
    throw new Error('INVALID_SUPPORT_CASE');
  }
  const response = await publicRequest.get(`feedback/${encodeURIComponent(publicId)}`, {
    headers: accessHeaders(accessToken),
  });
  return parseCase((response.data as {data?: unknown})?.data);
};

export const loadProductFeedbackCases = async (): Promise<ProductFeedbackCase[]> => {
  const cases = new Map<string, ProductFeedbackCase>();
  let loadError: unknown;
  try {
    const response = await publicRequest.get('feedback');
    const data = (response.data as {data?: unknown})?.data;
    const items = isRecord(data) && Array.isArray(data.items) ? data.items : [];
    items.forEach(item => {
      try {
        const parsed = parseCase(item);
        cases.set(parsed.publicId, parsed);
      } catch {}
    });
  } catch (error) {
    const status = Number(
      isRecord(error) && isRecord(error.response) ? error.response.status : 0,
    );
    if (status !== 401) loadError = error;
  }

  const receipts = await loadStoredReceipts();
  const settled = await Promise.allSettled(
    receipts.map(async receipt => ({
      accessToken: receipt.accessToken,
      case: await loadProductFeedbackCase(receipt.publicId, receipt.accessToken),
    })),
  );
  settled.forEach(result => {
    if (result.status === 'fulfilled') {
      cases.set(result.value.case.publicId, {
        ...result.value.case,
        accessToken: result.value.accessToken,
      });
    } else if (!loadError) {
      loadError = result.reason;
    }
  });
  if (!cases.size && loadError) throw loadError;
  return [...cases.values()].sort((a, b) =>
    b.updatedAt.localeCompare(a.updatedAt) || b.publicId.localeCompare(a.publicId),
  );
};

export const replyToProductFeedback = async (input: {
  accessToken?: string;
  attachment?: FeedbackAttachment;
  clientRequestId: string;
  message: string;
  publicId: string;
}): Promise<ProductFeedbackCase> => {
  const form = new FormData() as NativeFormData;
  form.append('client_request_id', input.clientRequestId);
  form.append('message', input.message.trim());
  if (input.attachment) {
    form.append('screenshot', {
      name: input.attachment.fileName || 'rokn-support.jpg',
      type: input.attachment.type || 'image/jpeg',
      uri: input.attachment.uri,
    });
  }
  const response = await publicRequest.post(
    `feedback/${encodeURIComponent(input.publicId)}/messages`,
    form,
    {
      timeout: 30000,
      headers: {
        'Idempotency-Key': input.clientRequestId,
        ...(accessHeaders(input.accessToken) || {}),
      },
    },
  );
  return parseCase((response.data as {data?: unknown})?.data);
};

const replyDraftKey = (publicId: string) =>
  accountScopedStorageKey(`${REPLY_DRAFT_PREFIX}${publicId}`);

export const loadProductFeedbackReplyDraft = async (publicId: string) => {
  const key = await replyDraftKey(publicId);
  const value = String((await AsyncStorage.getItem(key)) || '');
  return value.length <= 2000 ? value : value.slice(0, 2000);
};

export const saveProductFeedbackReplyDraft = async (
  publicId: string,
  message: string,
) => {
  const key = await replyDraftKey(publicId);
  const normalized = message.slice(0, 2000);
  if (normalized.trim()) await AsyncStorage.setItem(key, normalized);
  else await AsyncStorage.removeItem(key);
};

export const loadProductFeedbackDraft =
  async (): Promise<ProductFeedbackDraft | null> => {
    const key = await accountScopedStorageKey(DRAFT_KEY);
    return withDraftLock(async () => {
      const raw = await AsyncStorage.getItem(key);
      if (!raw) return null;
      let parsed: Partial<ProductFeedbackDraft> | null = null;
      try {
        const draft = JSON.parse(raw) as Partial<ProductFeedbackDraft>;
        parsed = draft;
        const valid =
          ['problem', 'idea', 'content', 'playback'].includes(
            String(draft.category),
          ) &&
          typeof draft.message === 'string' &&
          /^[0-9a-f-]{36}$/i.test(String(draft.clientRequestId || '')) &&
          Number.isFinite(draft.updatedAt) &&
          Date.now() - Number(draft.updatedAt) <= DRAFT_TTL_MS;
        if (valid) {
          const value = draft as ProductFeedbackDraft;
          if (
            !value.attachment ||
            (await learnerDraftFileIsReadable(value.attachment))
          ) {
            return value;
          }
          await removeLearnerDraftFile(value.attachment);
          const repaired = {...value, attachment: undefined};
          await AsyncStorage.setItem(key, JSON.stringify(repaired));
          return repaired;
        }
      } catch {}
      await removeLearnerDraftFile(parsed?.attachment);
      await AsyncStorage.removeItem(key);
      return null;
    });
  };

export const saveProductFeedbackDraft = async (
  draft: ProductFeedbackDraft,
): Promise<void> => {
  const key = await accountScopedStorageKey(DRAFT_KEY);
  await withDraftLock(async () => {
    if (!draft.message.trim() && !draft.attachment) {
      await AsyncStorage.removeItem(key);
      return;
    }
    await AsyncStorage.setItem(key, JSON.stringify(draft));
  });
};

export const clearProductFeedbackDraft = async (): Promise<void> => {
  const key = await accountScopedStorageKey(DRAFT_KEY);
  await withDraftLock(async () => {
    const raw = await AsyncStorage.getItem(key);
    if (raw) {
      try {
        const draft = JSON.parse(raw) as Partial<ProductFeedbackDraft>;
        await removeLearnerDraftFile(draft.attachment);
      } catch {}
    }
    await AsyncStorage.removeItem(key);
  });
};
