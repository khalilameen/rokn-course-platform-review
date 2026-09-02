import AsyncStorage from '@react-native-async-storage/async-storage';

import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../constants/helpers';
import type {EligibleProject} from './api/profile';
import {
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
} from './learnerDraftFiles';

export type PortfolioDraft = {
  clientRequestId: string;
  cover?: {uri: string; type?: string; fileName?: string; size?: number};
  media?: Array<{uri: string; type?: string; fileName?: string; size?: number}>;
  selectedSource?: EligibleProject;
  summary: string;
  title: string;
  updatedAt: number;
};

const STORAGE_KEY = '@rokn/portfolio-editor-draft/v1';
const REFERENCE_OWNER = 'portfolio-editor-draft';
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
    const boundary = await captureAccountSessionBoundary();
    const key = await accountScopedStorageKey(STORAGE_KEY, boundary);
    return withDraftLock(async () => {
      assertAccountSessionBoundary(boundary);
      const raw = await AsyncStorage.getItem(key);
      if (!raw) {
        await retainLearnerDraftFiles(REFERENCE_OWNER, [], boundary.scope);
        return null;
      }
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
          const media = await Promise.all(
            (draft.media || []).map(async file =>
              (await learnerDraftFileIsReadable(file)) ? file : null,
            ),
          );
          const readableMedia = media.filter(
            (file): file is NonNullable<typeof file> => file !== null,
          );
          if (!draft.cover || (await learnerDraftFileIsReadable(draft.cover))) {
            await retainLearnerDraftFiles(
              REFERENCE_OWNER,
              [...readableMedia, ...(draft.cover ? [draft.cover] : [])],
              boundary.scope,
            );
            assertAccountSessionBoundary(boundary);
            return {...draft, media: readableMedia};
          }
          const repaired = {...draft, cover: undefined, media: readableMedia};
          await retainLearnerDraftFiles(
            REFERENCE_OWNER,
            readableMedia,
            boundary.scope,
          );
          await AsyncStorage.setItem(key, JSON.stringify(repaired));
          assertAccountSessionBoundary(boundary);
          await removeLearnerDraftFile(draft.cover);
          return repaired;
        }
      } catch {}
      await retainLearnerDraftFiles(REFERENCE_OWNER, [], boundary.scope);
      await AsyncStorage.removeItem(key);
      assertAccountSessionBoundary(boundary);
      await Promise.all([
        removeLearnerDraftFile(parsed?.cover),
        ...(parsed?.media || []).map(removeLearnerDraftFile),
      ]);
      return null;
    });
  };

export const writePortfolioEditorDraft = async (
  draft: PortfolioDraft,
): Promise<void> => {
  const boundary = await captureAccountSessionBoundary();
  const key = await accountScopedStorageKey(STORAGE_KEY, boundary);
  await withDraftLock(async () => {
    assertAccountSessionBoundary(boundary);
    if (
      !draft.title.trim() &&
      !draft.summary.trim() &&
      !draft.cover &&
      !draft.media?.length
    ) {
      await retainLearnerDraftFiles(REFERENCE_OWNER, [], boundary.scope);
      await AsyncStorage.removeItem(key);
      assertAccountSessionBoundary(boundary);
      return;
    }
    await retainLearnerDraftFiles(
      REFERENCE_OWNER,
      [...(draft.media || []), ...(draft.cover ? [draft.cover] : [])],
      boundary.scope,
    );
    await AsyncStorage.setItem(key, JSON.stringify(draft));
    assertAccountSessionBoundary(boundary);
  });
};

export const clearPortfolioEditorDraft = async (): Promise<void> => {
  const boundary = await captureAccountSessionBoundary();
  const key = await accountScopedStorageKey(STORAGE_KEY, boundary);
  await withDraftLock(async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    await retainLearnerDraftFiles(REFERENCE_OWNER, [], boundary.scope);
    await AsyncStorage.removeItem(key);
    assertAccountSessionBoundary(boundary);
    if (raw) {
      try {
        const draft = JSON.parse(raw) as Partial<PortfolioDraft>;
        await Promise.all([
          removeLearnerDraftFile(draft.cover),
          ...(draft.media || []).map(removeLearnerDraftFile),
        ]);
      } catch {}
    }
  });
};
