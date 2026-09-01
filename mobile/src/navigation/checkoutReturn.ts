import AsyncStorage from '@react-native-async-storage/async-storage';
import {accountScopedStorageKey} from '../constants/helpers';
import {serverNowMs} from '../utils/serverClock';
import {safeLoginReturnTo} from './authReturn';
import type {LoginReturnTo} from './types';

const CHECKOUT_RETURN_KEY = '@rokn/pending-checkout-return/v1';
const CHECKOUT_RETURN_TTL_MS = 30 * 60 * 1000;

type CheckoutReturnEnvelope = {
  returnTo: LoginReturnTo;
  createdAt: number;
};

export type PendingCheckoutReturnClaim = {
  returnTo: LoginReturnTo;
  receipt: string;
  storageKey: string;
};

const asRecord = (value: unknown): Record<string, unknown> | null =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;

/** Persist only a canonical in-app destination, never provider/browser data. */
export const savePendingCheckoutReturn = async (value: unknown) => {
  const returnTo = safeLoginReturnTo(value);
  if (!returnTo) return undefined;
  const storageKey = await accountScopedStorageKey(CHECKOUT_RETURN_KEY);
  const envelope: CheckoutReturnEnvelope = {
    returnTo,
    createdAt: serverNowMs(),
  };
  const receipt = JSON.stringify(envelope);
  await AsyncStorage.setItem(storageKey, receipt);
  return {returnTo, receipt, storageKey} satisfies PendingCheckoutReturnClaim;
};

export const claimPendingCheckoutReturn = async (): Promise<
  PendingCheckoutReturnClaim | undefined
> => {
  const storageKey = await accountScopedStorageKey(CHECKOUT_RETURN_KEY);
  const receipt = await AsyncStorage.getItem(storageKey);
  if (!receipt) return undefined;
  try {
    const envelope = asRecord(JSON.parse(receipt));
    const createdAt = Number(envelope?.createdAt);
    const age = serverNowMs() - createdAt;
    const returnTo = safeLoginReturnTo(envelope?.returnTo);
    if (
      !returnTo ||
      !Number.isFinite(createdAt) ||
      age < -60_000 ||
      age > CHECKOUT_RETURN_TTL_MS
    ) {
      await AsyncStorage.removeItem(storageKey);
      return undefined;
    }
    return {returnTo, receipt, storageKey};
  } catch {
    await AsyncStorage.removeItem(storageKey);
    return undefined;
  }
};

export const acknowledgePendingCheckoutReturn = async (
  claim: PendingCheckoutReturnClaim,
) => {
  const current = await AsyncStorage.getItem(claim.storageKey);
  if (current === claim.receipt) {
    await AsyncStorage.removeItem(claim.storageKey);
    return true;
  }
  return false;
};
