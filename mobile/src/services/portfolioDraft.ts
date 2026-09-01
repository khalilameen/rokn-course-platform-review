import AsyncStorage from '@react-native-async-storage/async-storage';

import {accountScopedStorageKey} from '../constants/helpers';
import type {EligibleProject} from './api/profile';
import {
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
} from './learnerDraftFiles';

export type PortfolioDraft = {
  clientRequestId: string;
  cover?: {uri: string; type?: string; fileName?: string; size?: number};
  selectedSource?: EligibleProject;
  summary: string;
  title: string;
  updatedAt: number;
};

const STORAGE_KEY = '@rokn/portfolio-editor-draft/v1';
const TTL_MS = 30 * 24 * 60 * 60 * 1000;
let draftOperation: Promise<unknown> = Promise.resolve();

const withDraftLock = <T>(operation: () => Promise<T>) => {
  const result = draftOperation.then(operation, operation);
  draftOperation = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

export const readPortfolioEditorDraft =
  async (): Promise<PortfolioDraft | null> => {
    const key = await accountScopedStorageKey(STORAGE_KEY);
    return withDraftLock(async () => {
      const raw = await AsyncStorage.getItem(key);
      if (!raw) return null;
      let parsed: Partial<PortfolioDraft> | null = null;
      try {
        const value = JSON.parse(raw) as Partial<PortfolioDraft>;
        parsed = value;
        if (
          typeof value.title === 'string' &&
          typeof value.summary === 'string' &&
          /^[0-9a-f-]{36}$/i.test(String(value.clientRequestId || '')) &&
          Number.isFinite(value.updatedAt) &&
          Date.now() - Number(value.updatedAt) <= TTL_MS
        ) {
          const draft = value as PortfolioDraft;
          if (!draft.cover || (await learnerDraftFileIsReadable(draft.cover))) {
            return draft;
          }
          await removeLearnerDraftFile(draft.cover);
          const repaired = {...draft, cover: undefined};
          await AsyncStorage.setItem(key, JSON.stringify(repaired));
          return repaired;
        }
      } catch {}
      await removeLearnerDraftFile(parsed?.cover);
      await AsyncStorage.removeItem(key);
      return null;
    });
  };

export const writePortfolioEditorDraft = async (
  draft: PortfolioDraft,
): Promise<void> => {
  const key = await accountScopedStorageKey(STORAGE_KEY);
  await withDraftLock(async () => {
    if (!draft.title.trim() && !draft.summary.trim() && !draft.cover) {
      await AsyncStorage.removeItem(key);
      return;
    }
    await AsyncStorage.setItem(key, JSON.stringify(draft));
  });
};

export const clearPortfolioEditorDraft = async (): Promise<void> => {
  const key = await accountScopedStorageKey(STORAGE_KEY);
  await withDraftLock(async () => {
    const raw = await AsyncStorage.getItem(key);
    if (raw) {
      try {
        const draft = JSON.parse(raw) as Partial<PortfolioDraft>;
        await removeLearnerDraftFile(draft.cover);
      } catch {}
    }
    await AsyncStorage.removeItem(key);
  });
};
