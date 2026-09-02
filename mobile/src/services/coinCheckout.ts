import {NativeModules, Platform} from 'react-native';
import * as Crypto from 'expo-crypto';
import * as WebBrowser from 'expo-web-browser';
import {publicRequest} from '../constants/api';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getItem,
  removeItem,
  saveItem,
} from '../constants/helpers';
import type {AccountSessionBoundary} from '../constants/helpers';
import {DemoCoinPackage} from './demoExperience';
import {
  CAN_START_EXTERNAL_CHECKOUT,
  CAN_START_NATIVE_CHECKOUT,
} from '../constants/distribution';
import {reportClientError} from './operationalTelemetry';
import {requireProductFeature} from './productFeatures';
import {errorCode, errorPayload, errorStatus} from '../utils/errorPayload';
import {isLocalDemoId, LOCAL_DEMO_ENABLED} from '../config/runtime';
import {
  acknowledgePendingCheckoutReturn,
  savePendingCheckoutReturn,
} from '../navigation/checkoutReturn';
import type {LoginReturnTo} from '../navigation/types';

type CheckoutNativeModule = {
  open: (url: string) => Promise<string>;
};

export type CoinCheckoutResult = {
  success: boolean;
  pending: boolean;
  cancelled: boolean;
  coinsAdded: number;
  orderRef?: string;
  demo: boolean;
};

const nativeCheckout = NativeModules.RoknCheckout as
  | CheckoutNativeModule
  | undefined;

type PersistedCheckoutAttempt = {
  idempotencyKey: string;
  packageId: number;
  expectedPrice?: number;
  expectedCoins?: number;
  createdAt: string;
  orderRef?: string;
};

type PersistedCheckoutLedger = {
  attempts: PersistedCheckoutAttempt[];
};

const CHECKOUT_ATTEMPT_KEY = '@rokn/coin-checkout-attempt/v2';
const CHECKOUT_ATTEMPT_TTL_MS = 30 * 60 * 1000;
const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
let checkoutAttemptStorageTail: Promise<void> = Promise.resolve();
const checkoutFlights = new Map<string, Promise<CoinCheckoutResult>>();
const checkoutReconciliationFlights = new Map<
  string,
  Promise<CoinCheckoutResult | null>
>();
const checkoutCreditListeners = new Set<(result: CoinCheckoutResult) => void>();
const emittedCheckoutCredits = new Set<string>();
const MAX_EMITTED_CHECKOUT_CREDITS = 128;

const withCheckoutAttemptStorageLock = <T>(
  operation: () => Promise<T>,
): Promise<T> => {
  const result = checkoutAttemptStorageTail.then(operation, operation);
  checkoutAttemptStorageTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

const checkoutAttemptStorageKey = (boundary?: AccountSessionBoundary) =>
  accountScopedStorageKey(CHECKOUT_ATTEMPT_KEY, boundary);

const normalizeCheckoutAttempt = (
  value: PersistedCheckoutAttempt | null,
): PersistedCheckoutAttempt | null => {
  if (!value) return null;
  const packageId = Number(value.packageId);
  const idempotencyKey = String(value.idempotencyKey || '').toLowerCase();
  const orderRef = String(value.orderRef || '').trim();
  const createdAt = String(value.createdAt || '').trim();
  const expectedPrice = Number(value.expectedPrice);
  const expectedCoins = Number(value.expectedCoins);
  if (
    !Number.isSafeInteger(packageId) ||
    packageId <= 0 ||
    !UUID_PATTERN.test(idempotencyKey) ||
    (orderRef !== '' && !/^[a-zA-Z0-9_-]{8,100}$/.test(orderRef)) ||
    (createdAt !== '' && !Number.isFinite(Date.parse(createdAt))) ||
    (value.expectedPrice !== undefined &&
      (!Number.isFinite(expectedPrice) || expectedPrice <= 0)) ||
    (value.expectedCoins !== undefined &&
      (!Number.isSafeInteger(expectedCoins) || expectedCoins <= 0))
  ) {
    throw new Error('CHECKOUT_RECOVERY_RECORD_INVALID');
  }
  return {
    idempotencyKey,
    packageId,
    createdAt: createdAt || new Date(0).toISOString(),
    ...(value.expectedPrice !== undefined ? {expectedPrice} : {}),
    ...(value.expectedCoins !== undefined ? {expectedCoins} : {}),
    ...(orderRef ? {orderRef} : {}),
  };
};

const normalizeCheckoutLedger = (
  value: PersistedCheckoutAttempt | PersistedCheckoutLedger | null,
): PersistedCheckoutAttempt[] => {
  const rawAttempts =
    value && Array.isArray((value as PersistedCheckoutLedger).attempts)
      ? (value as PersistedCheckoutLedger).attempts
      : value
      ? [value as PersistedCheckoutAttempt]
      : [];
  const byPackage = new Map<number, PersistedCheckoutAttempt>();
  rawAttempts.forEach(raw => {
    try {
      const attempt = normalizeCheckoutAttempt(raw);
      if (attempt) byPackage.set(attempt.packageId, attempt);
    } catch {
      // AsyncStorage can retain one truncated/legacy entry after a killed
      // process or a full device. One bad recovery row must not make checkout
      // permanently unusable. Valid sibling attempts remain recoverable, and
      // the server still owns pending-order reconciliation for the next tap.
    }
  });
  return [...byPackage.values()];
};

const readCheckoutAttempts = async (boundary?: AccountSessionBoundary) =>
  normalizeCheckoutLedger(
    await getItem<PersistedCheckoutAttempt | PersistedCheckoutLedger>(
      await checkoutAttemptStorageKey(boundary),
    ),
  );

const readCheckoutAttempt = async (
  packageId?: number,
  boundary?: AccountSessionBoundary,
) => {
  const attempts = await readCheckoutAttempts(boundary);
  return packageId === undefined
    ? attempts[0] ?? null
    : attempts.find(attempt => attempt.packageId === packageId) ?? null;
};

const saveCheckoutAttempts = async (
  storageKey: string,
  attempts: PersistedCheckoutAttempt[],
) => {
  if (attempts.length === 0) {
    await removeItem(storageKey);
    return true;
  }
  return saveItem(storageKey, {attempts} satisfies PersistedCheckoutLedger);
};

const getOrCreateCheckoutAttempt = async (
  packageId: number,
  expectedPrice: number,
  expectedCoins: number,
  boundary: AccountSessionBoundary,
): Promise<PersistedCheckoutAttempt> =>
  withCheckoutAttemptStorageLock(async () => {
    assertAccountSessionBoundary(boundary);
    const storageKey = await checkoutAttemptStorageKey(boundary);
    const attempts = normalizeCheckoutLedger(
      await getItem<PersistedCheckoutAttempt | PersistedCheckoutLedger>(
        storageKey,
      ),
    );
    const stored = attempts.find(attempt => attempt.packageId === packageId);
    if (stored) return stored;

    const idempotencyKey = Crypto.randomUUID().toLowerCase();
    const attempt = {
      idempotencyKey,
      packageId,
      expectedPrice,
      expectedCoins,
      createdAt: new Date().toISOString(),
    } satisfies PersistedCheckoutAttempt;
    const persisted = await saveCheckoutAttempts(storageKey, [
      ...attempts,
      attempt,
    ]);
    if (!persisted) {
      // Starting without durable intent identity would make a timeout unsafe to
      // retry: the server may already have created a payable order.
      throw new Error('CHECKOUT_IDEMPOTENCY_UNAVAILABLE');
    }

    return attempt;
  });

const rememberCheckoutOrder = async (
  attempt: PersistedCheckoutAttempt,
  orderRef: string,
  boundary: AccountSessionBoundary,
) =>
  withCheckoutAttemptStorageLock(async () => {
    assertAccountSessionBoundary(boundary);
    const storageKey = await checkoutAttemptStorageKey(boundary);
    const attempts = normalizeCheckoutLedger(
      await getItem<PersistedCheckoutAttempt | PersistedCheckoutLedger>(
        storageKey,
      ),
    );
    const current = attempts.find(
      candidate => candidate.idempotencyKey === attempt.idempotencyKey,
    );
    if (!current) {
      return saveCheckoutAttempts(storageKey, [
        ...attempts.filter(
          candidate => candidate.packageId !== attempt.packageId,
        ),
        {...attempt, orderRef},
      ]);
    }
    return saveCheckoutAttempts(
      storageKey,
      attempts.map(candidate =>
        candidate.idempotencyKey === attempt.idempotencyKey
          ? {...candidate, orderRef}
          : candidate,
      ),
    );
  });

const clearCheckoutAttempt = async (
  expectedIdempotencyKey: string,
  boundary: AccountSessionBoundary,
) =>
  withCheckoutAttemptStorageLock(async () => {
    assertAccountSessionBoundary(boundary);
    const storageKey = await checkoutAttemptStorageKey(boundary);
    const attempts = normalizeCheckoutLedger(
      await getItem<PersistedCheckoutAttempt | PersistedCheckoutLedger>(
        storageKey,
      ),
    );
    await saveCheckoutAttempts(
      storageKey,
      attempts.filter(
        attempt => attempt.idempotencyKey !== expectedIdempotencyKey,
      ),
    );
  });

const resultFromUrl = (value: string) => {
  try {
    const callback = String(value || '').trim();
    const match = callback.match(/^rokn:\/\/payment-result(?:\?([^#]*))?$/i);
    if (!match) {
      return {valid: false, status: undefined, orderRef: undefined, coins: 0};
    }
    const query = match[1] || '';
    const params = query
      .split('&')
      .reduce<Record<string, string>>((result, pair) => {
        const [rawKey, ...rawValue] = pair.split('=');
        if (rawKey) {
          const key = decodeURIComponent(rawKey);
          if (Object.prototype.hasOwnProperty.call(result, key)) {
            throw new Error('PAYMENT_CALLBACK_DUPLICATE_FIELD');
          }
          result[key] = decodeURIComponent(rawValue.join('=') || '');
        }
        return result;
      }, {});
    return {
      valid: true,
      status: params.status?.toLowerCase(),
      orderRef: params.order_ref || params.orderRef || undefined,
      coins: Number(params.coins || 0),
    };
  } catch {
    return {valid: false, status: undefined, orderRef: undefined, coins: 0};
  }
};

const openCheckoutSurface = async (url: string): Promise<string> => {
  if (nativeCheckout?.open) return nativeCheckout.open(url);
  // ASWebAuthenticationSession / Chrome Custom Tabs keep checkout inside the
  // app-owned flow while isolating payment credentials from our JavaScript.
  // The backend result page returns through this exact deep link.
  if (/^https:\/\/checkout\.kashier\.io(?:\/|\?|$)/i.test(url)) {
    const result = await WebBrowser.openAuthSessionAsync(
      url,
      'rokn://payment-result',
      {showInRecents: true},
    );
    if (result.type === 'success') return result.url;
    if (result.type === 'cancel' || result.type === 'dismiss') {
      const cancelled = new Error('Checkout cancelled') as Error & {
        code?: string;
      };
      cancelled.code = 'CHECKOUT_CANCELLED';
      throw cancelled;
    }
    return '';
  }
  throw new Error(`In-app checkout is unavailable on ${Platform.OS}`);
};

const pollOrder = async (
  orderRef: string,
  boundary: AccountSessionBoundary,
  attempts = 4,
) => {
  const normalizedOrderRef = String(orderRef).trim();
  if (!/^[a-zA-Z0-9_-]{8,100}$/.test(normalizedOrderRef)) {
    throw new Error('PAYMENT_ORDER_REFERENCE_INVALID');
  }
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    assertAccountSessionBoundary(boundary);
    let response;
    try {
      response = await publicRequest.post(
        `payment/reconcile/${encodeURIComponent(normalizedOrderRef)}`,
      );
      assertAccountSessionBoundary(boundary);
    } catch (error: unknown) {
      // A reconciliation response belongs to the account which opened the
      // checkout. Do not turn an account-boundary rejection into an ordinary
      // provider delay and then poll the old order again with the next
      // account's bearer.
      assertAccountSessionBoundary(boundary);
      const status = errorStatus(error);
      const code = errorCode(error);
      if (status === 404 || code === 'order_not_found') {
        return {approved: false, pending: false, coinsAdded: 0};
      }
      if (
        status >= 400 &&
        status < 500 &&
        ![408, 409, 425, 429].includes(status)
      ) {
        throw error;
      }
      // The callback may arrive before the status endpoint sees the webhook.
      if (attempt + 1 < attempts) {
        await new Promise<void>(resolve =>
          setTimeout(() => resolve(), 900 + attempt * 350),
        );
      }
      continue;
    }
    const root = response?.data;
    const data = root?.data || root;
    const status = String(data?.status || root?.status || '').toLowerCase();
    const financialStatus = String(
      data?.financial_status || root?.financial_status || '',
    ).toLowerCase();
    const responsePackage = data?.package || root?.package;
    if (status === 'approved' && financialStatus === 'settled') {
      const coinsAdded = Number(responsePackage?.coins);
      if (!Number.isSafeInteger(coinsAdded) || coinsAdded <= 0) {
        throw new Error('PAYMENT_STATUS_CONTRACT_INVALID');
      }
      return {approved: true, pending: false, coinsAdded};
    }
    if (
      status === 'approved' &&
      [
        'review_required',
        'refunded',
        'chargeback',
        'reversed',
        'partially_recovered',
      ].includes(financialStatus)
    ) {
      return {approved: false, pending: false, coinsAdded: 0};
    }
    if (['failed', 'cancelled', 'rejected'].includes(status)) {
      return {approved: false, pending: false, coinsAdded: 0};
    }
    if (attempt + 1 < attempts) {
      await new Promise<void>(resolve =>
        setTimeout(() => resolve(), 900 + attempt * 350),
      );
    }
  }
  return {approved: false, pending: true, coinsAdded: 0};
};

const abandonOrder = async (
  orderRef: string,
  boundary: AccountSessionBoundary,
) => {
  assertAccountSessionBoundary(boundary);
  try {
    const response = await publicRequest.post(
      `payment/abandon/${encodeURIComponent(orderRef)}`,
    );
    assertAccountSessionBoundary(boundary);
    const root = response?.data;
    const data = root?.data || root;
    const status = String(data?.status || '').toLowerCase();
    const financialStatus = String(data?.financial_status || '').toLowerCase();
    const coinsAdded = Number(data?.coins_added || 0);
    const approved = status === 'approved' && financialStatus === 'settled';
    const terminal = ['cancelled', 'rejected', 'failed'].includes(status);
    return {
      approved,
      // Closing the browser is never proof that Kashier rejected the charge.
      // Only an explicit terminal server state may retire the durable attempt;
      // an empty or future response shape stays recoverable.
      pending: !approved && !terminal,
      coinsAdded:
        Number.isSafeInteger(coinsAdded) && coinsAdded > 0 ? coinsAdded : 0,
    };
  } catch {
    // A closed surface is not proof of provider failure. If the server cannot
    // inspect Kashier now, keep the durable intent for background recovery.
    assertAccountSessionBoundary(boundary);
    return {approved: false, pending: true, coinsAdded: 0};
  }
};

const reconcileCheckoutAttempt = async (
  attempt: PersistedCheckoutAttempt,
  boundary: AccountSessionBoundary,
): Promise<CoinCheckoutResult> => {
  assertAccountSessionBoundary(boundary);
  if (!attempt.orderRef) {
    // The server may have accepted initiation while the response was lost.
    // Replay the same account/package/key to recover the one authoritative
    // order reference. Never mint a new key merely because the network broke.
    try {
      const response = await publicRequest.post(
        'payment/initiate',
        {
          package_id: attempt.packageId,
          ...(Number.isFinite(attempt.expectedPrice)
            ? {expected_amount: attempt.expectedPrice}
            : {}),
          ...(Number.isFinite(attempt.expectedCoins)
            ? {expected_coins: attempt.expectedCoins}
            : {}),
          idempotency_key: attempt.idempotencyKey,
        },
        {headers: {'Idempotency-Key': attempt.idempotencyKey}},
      );
      const orderRef = String(response?.data?.order_ref || '').trim();
      if (orderRef) {
        const remembered = await rememberCheckoutOrder(
          attempt,
          orderRef,
          boundary,
        );
        if (!remembered)
          throw new Error('CHECKOUT_ORDER_REFERENCE_UNAVAILABLE');
        return {
          success: false,
          pending: true,
          cancelled: false,
          coinsAdded: 0,
          orderRef,
          demo: false,
        };
      }
    } catch (error: unknown) {
      const data = errorPayload(error).data;
      const conflict =
        typeof data === 'object' && data !== null
          ? (data as {order_ref?: unknown; status?: unknown})
          : undefined;
      const orderRef = String(conflict?.order_ref || '').trim();
      if (orderRef) {
        const remembered = await rememberCheckoutOrder(
          attempt,
          orderRef,
          boundary,
        );
        if (!remembered)
          throw new Error('CHECKOUT_ORDER_REFERENCE_UNAVAILABLE');
        return {
          success: false,
          pending: true,
          cancelled: false,
          coinsAdded: 0,
          orderRef,
          demo: false,
        };
      }
      const status = errorStatus(error);
      if (
        status >= 400 &&
        status < 500 &&
        ![408, 409, 425, 429].includes(status)
      ) {
        await clearCheckoutAttempt(attempt.idempotencyKey, boundary);
        throw error;
      }
    }
    return {
      success: false,
      pending: true,
      cancelled: false,
      coinsAdded: 0,
      demo: false,
    };
  }

  const status = await pollOrder(attempt.orderRef, boundary, 1);
  if (!status.pending) {
    await clearCheckoutAttempt(attempt.idempotencyKey, boundary);
  }
  return {
    success: status.approved,
    pending: status.pending,
    cancelled: false,
    coinsAdded: status.approved ? status.coinsAdded : 0,
    orderRef: attempt.orderRef,
    demo: false,
  };
};

const reconcilePendingCoinCheckoutOnce = async (
  boundary: AccountSessionBoundary,
): Promise<CoinCheckoutResult | null> => {
  if (!CAN_START_EXTERNAL_CHECKOUT) return null;
  assertAccountSessionBoundary(boundary);
  const attempts = await readCheckoutAttempts(boundary);
  if (attempts.length === 0) return null;

  let pending: CoinCheckoutResult | null = null;
  let approved: CoinCheckoutResult | null = null;
  for (const attempt of attempts) {
    assertAccountSessionBoundary(boundary);
    const result = await reconcileCheckoutAttempt(attempt, boundary);
    if (result.success) approved = result;
    else if (result.pending && !pending) pending = result;
  }

  return (
    approved ??
    pending ?? {
      success: false,
      pending: false,
      cancelled: false,
      coinsAdded: 0,
      demo: false,
    }
  );
};

/**
 * A learner can close one package and immediately choose another. Retire the
 * old payable intent first; otherwise two different package URLs can remain
 * chargeable even though each package is individually idempotent.
 */
const reconcilePackageSwitch = async (
  selectedPackageId: number,
  boundary: AccountSessionBoundary,
): Promise<CoinCheckoutResult | null> => {
  const attempts = await readCheckoutAttempts(boundary);
  for (const attempt of attempts) {
    if (attempt.packageId === selectedPackageId) continue;
    assertAccountSessionBoundary(boundary);
    const recovered = await reconcileCheckoutAttempt(attempt, boundary);
    if (recovered.success) return recovered;
    if (!recovered.pending) continue;
    if (!recovered.orderRef) return recovered;

    const abandoned = await abandonOrder(recovered.orderRef, boundary);
    if (abandoned.approved) {
      await clearCheckoutAttempt(attempt.idempotencyKey, boundary);
      return {
        success: true,
        pending: false,
        cancelled: false,
        coinsAdded: abandoned.coinsAdded,
        orderRef: recovered.orderRef,
        demo: false,
      };
    }
    if (abandoned.pending) {
      return {
        success: false,
        pending: true,
        cancelled: false,
        coinsAdded: 0,
        orderRef: recovered.orderRef,
        demo: false,
      };
    }
    await clearCheckoutAttempt(attempt.idempotencyKey, boundary);
  }
  return null;
};

export const reconcilePendingCoinCheckout =
  async (): Promise<CoinCheckoutResult | null> => {
    const boundary = await captureAccountSessionBoundary();
    const scope = await checkoutAttemptStorageKey(boundary);
    const reconciliationKey = `${scope}:${boundary.epoch}`;
    const current = checkoutReconciliationFlights.get(reconciliationKey);
    if (current) return current;

    const operation = reconcilePendingCoinCheckoutOnce(boundary)
      .then(result => {
        assertAccountSessionBoundary(boundary);
        if (result?.success && result.orderRef) {
          const creditKey = `${boundary.scope}:${result.orderRef}`;
          if (!emittedCheckoutCredits.has(creditKey)) {
            emittedCheckoutCredits.add(creditKey);
            while (emittedCheckoutCredits.size > MAX_EMITTED_CHECKOUT_CREDITS) {
              const oldest = emittedCheckoutCredits.values().next().value;
              if (typeof oldest !== 'string') break;
              emittedCheckoutCredits.delete(oldest);
            }
            checkoutCreditListeners.forEach(listener => {
              try {
                listener(result);
              } catch {
                // A screen observer cannot turn an authoritative reconciliation
                // into a failed financial operation.
              }
            });
          }
        }
        return result;
      })
      .finally(() => {
        if (
          checkoutReconciliationFlights.get(reconciliationKey) === operation
        ) {
          checkoutReconciliationFlights.delete(reconciliationKey);
        }
      });
    checkoutReconciliationFlights.set(reconciliationKey, operation);
    return operation;
  };

const runCoinCheckout = async (
  coinPackage: DemoCoinPackage,
  boundary: AccountSessionBoundary,
  allowFreshRetry = true,
): Promise<CoinCheckoutResult> => {
  assertAccountSessionBoundary(boundary);
  const demoShaped = String(coinPackage.id || '').startsWith('demo');
  const isDemo = isLocalDemoId(coinPackage.id);
  if (demoShaped && !LOCAL_DEMO_ENABLED) {
    throw new Error('LOCAL_DEMO_DISABLED');
  }
  const validatedPackageId = Number(coinPackage.id);
  if (
    (!isDemo &&
      (!Number.isSafeInteger(validatedPackageId) || validatedPackageId <= 0)) ||
    !Number.isSafeInteger(coinPackage.coins) ||
    coinPackage.coins <= 0 ||
    !Number.isFinite(coinPackage.price) ||
    coinPackage.price <= 0
  ) {
    throw new Error('COIN_PACKAGE_CONTRACT_INVALID');
  }
  // Synthetic wallets never pass through a payment-looking surface. Keeping
  // even a debug-only success callback here makes a future distribution flag
  // mistake capable of minting local credit without provider verification.
  if (isDemo) {
    throw new Error('LOCAL_DEMO_CHECKOUT_UNAVAILABLE');
  }
  if (CAN_START_NATIVE_CHECKOUT) {
    await requireProductFeature('checkout');
    const {purchaseNativeCoinPackage} = await import('./nativeStoreBilling');
    return purchaseNativeCoinPackage(coinPackage);
  }
  if (!CAN_START_EXTERNAL_CHECKOUT) {
    throw new Error('CHECKOUT_DISABLED_FOR_DISTRIBUTION');
  }
  await requireProductFeature('checkout');
  let paymentUrl = '';
  let orderRef = '';
  let idempotencyKey = '';

  const packageId = validatedPackageId;
  const packageSwitchResult = await reconcilePackageSwitch(
    packageId,
    boundary,
  );
  if (packageSwitchResult) return packageSwitchResult;
  let attempt = await readCheckoutAttempt(packageId, boundary);
  if (attempt?.orderRef) {
    const previous = await pollOrder(attempt.orderRef, boundary, 1);
    if (previous.approved) {
      await clearCheckoutAttempt(attempt.idempotencyKey, boundary);
      return {
        success: true,
        pending: false,
        cancelled: false,
        coinsAdded: previous.coinsAdded,
        orderRef: attempt.orderRef,
        demo: false,
      };
    }
    if (!previous.pending) {
      await clearCheckoutAttempt(attempt.idempotencyKey, boundary);
      attempt = null;
    } else if (
      Date.now() - Date.parse(attempt.createdAt) >=
      CHECKOUT_ATTEMPT_TTL_MS
    ) {
      // The provider may still settle the old order, so do not cancel it.
      // Stop making an abandoned local intent monopolize this package after
      // its checkout window; a new explicit tap gets a new idempotency key.
      await clearCheckoutAttempt(attempt.idempotencyKey, boundary);
      attempt = null;
    }
  }
  attempt =
    attempt ||
    (await getOrCreateCheckoutAttempt(
      packageId,
      coinPackage.price,
      coinPackage.coins,
      boundary,
    ));
  idempotencyKey = attempt.idempotencyKey;
  try {
    assertAccountSessionBoundary(boundary);
    const response = await publicRequest.post(
      'payment/initiate',
      {
        package_id: packageId,
        expected_amount: attempt.expectedPrice,
        expected_coins: attempt.expectedCoins,
        idempotency_key: idempotencyKey,
      },
      {headers: {'Idempotency-Key': idempotencyKey}},
    );
    paymentUrl = String(response?.data?.payment_url || '');
    orderRef = String(response?.data?.order_ref || '');
    const echoedIdempotencyKey = String(response?.data?.idempotency_key || '');
    if (
      !paymentUrl ||
      !orderRef ||
      (echoedIdempotencyKey && echoedIdempotencyKey !== idempotencyKey)
    ) {
      throw new Error('PAYMENT_SESSION_UNAVAILABLE');
    }
    const remembered = await rememberCheckoutOrder(attempt, orderRef, boundary);
    if (!remembered) {
      // The server has created a payable order. Losing its reference here
      // would make a restart unsafe to reconcile.
      throw new Error('CHECKOUT_ORDER_REFERENCE_UNAVAILABLE');
    }
  } catch (error: unknown) {
    const code = errorCode(error);
    // The shared Axios interceptor rejects with the response itself while
    // unit/native adapters may reject with an AxiosError. Read both through
    // the common envelope so an already-approved idempotent replay can never
    // be mistaken for a failed checkout and replaced with a second order.
    const responsePayload = errorPayload(error);
    const responseData = responsePayload.data;
    const closed =
      typeof responseData === 'object' && responseData !== null
        ? (responseData as {
            order_ref?: unknown;
            status?: unknown;
            payment_url?: unknown;
          })
        : undefined;
    if (
      code === 'checkout_attempt_closed' &&
      String(closed?.status || '').toLowerCase() === 'approved'
    ) {
      const approved = await pollOrder(
        String(closed?.order_ref || attempt.orderRef || ''),
        boundary,
        1,
      );
      if (!approved.approved) throw error;
      await clearCheckoutAttempt(idempotencyKey, boundary);
      return {
        success: true,
        pending: false,
        cancelled: false,
        coinsAdded: approved.coinsAdded,
        orderRef: String(closed?.order_ref || attempt.orderRef || ''),
        demo: false,
      };
    }
    const closedStatus = String(closed?.status || '').toLowerCase();
    if (
      allowFreshRetry &&
      (code === 'checkout_attempt_expired' ||
        (code === 'checkout_attempt_closed' &&
          ['cancelled', 'rejected', 'failed'].includes(closedStatus)))
    ) {
      await clearCheckoutAttempt(idempotencyKey, boundary);
      return runCoinCheckout(coinPackage, boundary, false);
    }
    if (code === 'pending_checkout_exists') {
      const pendingOrderRef = String(closed?.order_ref || '').trim();
      const resumablePaymentUrl = String(closed?.payment_url || '').trim();
      if (pendingOrderRef) {
        const remembered = await rememberCheckoutOrder(
          attempt,
          pendingOrderRef,
          boundary,
        );
        if (!remembered) {
          throw new Error('CHECKOUT_ORDER_REFERENCE_UNAVAILABLE');
        }
        if (resumablePaymentUrl) {
          paymentUrl = resumablePaymentUrl;
          orderRef = pendingOrderRef;
        } else {
          return {
            success: false,
            pending: true,
            cancelled: false,
            coinsAdded: 0,
            orderRef: pendingOrderRef,
            demo: false,
          };
        }
      }
    }
    if (!paymentUrl || !orderRef) {
      if (
        [
          'checkout_idempotency_conflict',
          'checkout_attempt_closed',
          'checkout_attempt_expired',
          'package_not_available',
          'package_terms_changed',
        ].includes(code)
      ) {
        await clearCheckoutAttempt(idempotencyKey, boundary);
      }
      throw error;
    }
  }
  try {
    assertAccountSessionBoundary(boundary);
    const callbackUrl = await openCheckoutSurface(paymentUrl);
    assertAccountSessionBoundary(boundary);
    const callback = resultFromUrl(callbackUrl);
    if (!callback.valid) throw new Error('PAYMENT_CALLBACK_INVALID');
    if (callback.orderRef && callback.orderRef !== orderRef) {
      throw new Error('PAYMENT_CALLBACK_ORDER_MISMATCH');
    }

    const status = await pollOrder(orderRef, boundary);
    if (!status.pending) {
      await clearCheckoutAttempt(idempotencyKey, boundary);
    }
    if (status.pending) {
      reportClientError(new Error('payment_status_timeout'), {
        source: 'coin_checkout',
      });
    }
    return {
      success: status.approved,
      pending: status.pending,
      cancelled: false,
      coinsAdded: status.approved ? status.coinsAdded : 0,
      orderRef,
      demo: false,
    };
  } catch (error: unknown) {
    if (errorCode(error) === 'CHECKOUT_CANCELLED') {
      assertAccountSessionBoundary(boundary);
      const abandoned = await abandonOrder(orderRef, boundary);
      if (!abandoned.pending) {
        await clearCheckoutAttempt(idempotencyKey, boundary);
      }
      return {
        success: abandoned.approved,
        pending: abandoned.pending,
        cancelled: !abandoned.approved,
        coinsAdded: abandoned.approved ? abandoned.coinsAdded : 0,
        orderRef,
        demo: false,
      };
    }
    reportClientError(
      error instanceof Error ? error : new Error('checkout_unknown_error'),
      {source: 'coin_checkout'},
    );
    throw error;
  }
};

export const openCoinCheckout = async (
  coinPackage: DemoCoinPackage,
  options: {returnTo?: LoginReturnTo} = {},
): Promise<CoinCheckoutResult> => {
  const boundary = await captureAccountSessionBoundary();
  const scope = await checkoutAttemptStorageKey(boundary);
  const flightKey = `${scope}:${boundary.epoch}`;
  const current = checkoutFlights.get(flightKey);
  if (current) return current;

  // Keep navigation recovery separate from the financial attempt: the latter
  // may be closed by background reconciliation before Navigation mounts. A
  // live caller acknowledges this receipt on return; a killed process cannot,
  // so cold start restores the exact course/wallet context once.
  let operation: Promise<CoinCheckoutResult> | undefined;
  operation = (async () => {
    const returnClaim = options.returnTo
      ? await savePendingCheckoutReturn(options.returnTo, boundary).catch(
          () => undefined,
        )
      : undefined;
    try {
      return await runCoinCheckout(coinPackage, boundary);
    } finally {
      if (checkoutFlights.get(flightKey) === operation) {
        checkoutFlights.delete(flightKey);
      }
      if (returnClaim) {
        try {
          // Only the account which opened the live checkout may consume its
          // navigation hand-off. If the learner switched accounts meanwhile,
          // retain the old account's receipt for its next safe restoration.
          assertAccountSessionBoundary(boundary);
          await acknowledgePendingCheckoutReturn(returnClaim);
        } catch {}
      }
    }
  })();
  // Register ownership before the first persistence/network await inside the
  // operation. Cross-screen taps and package switches now share one surface.
  checkoutFlights.set(flightKey, operation);
  return operation;
};

export const subscribeCoinCheckoutCredits = (
  listener: (result: CoinCheckoutResult) => void,
) => {
  checkoutCreditListeners.add(listener);
  return () => {
    checkoutCreditListeners.delete(listener);
  };
};
