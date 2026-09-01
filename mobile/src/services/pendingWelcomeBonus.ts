import {
  accountScopedStorageKey,
  AsyncKeys,
  extractApiToken,
  getItem,
  removeItem,
  saveItem,
} from '../constants/helpers';

const PENDING_WELCOME_BONUS_KEY = '@rokn/pending-welcome-bonus/v2';

const currentKey = () => accountScopedStorageKey(PENDING_WELCOME_BONUS_KEY);

export const savePendingWelcomeBonus = async (amount: unknown) => {
  const normalized = Math.max(0, Number(amount) || 0);
  if (!normalized || !extractApiToken(await getItem(AsyncKeys.USER_DATA))) {
    return false;
  }
  return saveItem(await currentKey(), normalized);
};

export const getPendingWelcomeBonus = async (): Promise<number | null> => {
  // Old builds stored this UI receipt globally. Discard it instead of showing
  // one learner's message after an account switch on the same phone.
  await removeItem(AsyncKeys.PENDING_WELCOME_BONUS);
  if (!extractApiToken(await getItem(AsyncKeys.USER_DATA))) return null;
  const amount = Number(await getItem(await currentKey()));
  return amount > 0 ? amount : null;
};

export const clearPendingWelcomeBonus = async () =>
  removeItem(await currentKey());
