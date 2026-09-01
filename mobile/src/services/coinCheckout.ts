import {NativeModules, Platform} from 'react-native';
import * as Crypto from 'expo-crypto';
import * as WebBrowser from 'expo-web-browser';
import {publicRequest} from '../constants/api';
import {
  accountScopedStorageKey,
  getItem,
  removeItem,
  saveItem,
} from '../constants/helpers';
import {DemoCoinPackage} from './demoExperience';
import {
  CAN_START_EXTERNAL_CHECKOUT,
  CAN_START_NATIVE_CHECKOUT,
} from '../constants/distribution';
import {reportClientError} from './operationalTelemetry';
import {requireProductFeature} from './productFeatures';
import {errorCode, errorPayload} from '../utils/errorPayload';
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

const CHECKOUT_ATTEMPT_KEY = '@rokn/coin-checkout-attempt/v2';
const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
let checkoutAttemptStorageTail: Promise<void> = Promise.resolve();
const checkoutFlights = new Map<string, Promise<CoinCheckoutResult>>();
const checkoutReconciliationFlights = new Map<
  string,
  Promise<CoinCheckoutResult | null>
>();

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

const checkoutAttemptStorageKey = () =>
  accountScopedStorageKey(CHECKOUT_ATTEMPT_KEY);

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

const readCheckoutAttempt = async () =>
  normalizeCheckoutAttempt(
    await getItem<PersistedCheckoutAttempt>(await checkoutAttemptStorageKey()),
  );

const getOrCreateCheckoutAttempt = async (
  packageId: number,
  expectedPrice: number,
  expectedCoins: number,
): Promise<PersistedCheckoutAttempt> =>
  withCheckoutAttemptStorageLock(async () => {
    const storageKey = await checkoutAttemptStorageKey();
    const stored = normalizeCheckoutAttempt(
      await getItem<PersistedCheckoutAttempt>(storageKey),
    );
    if (stored?.packageId === packageId) {
      return stored;
    }
    if (stored) {
      // One account can have one external checkout recovery intent at a time.
      // Never overwrite an uncertain older payment merely because the learner
      // selected a different package after updating the app.
      throw new Error('CHECKOUT_PENDING_DIFFERENT_PACKAGE');
    }

    const idempotencyKey = Crypto.randomUUID().toLowerCase();
    const attempt = {
      idempotencyKey,
      packageId,
      expectedPrice,
      expectedCoins,
      createdAt: new Date().toISOString(),
    } satisfies PersistedCheckoutAttempt;
    const persisted = await saveItem(storageKey, attempt);
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
) =>
  withCheckoutAttemptStorageLock(async () => {
    const storageKey = await checkoutAttemptStorageKey();
    return saveItem(storageKey, {...attempt, orderRef});
  });

const clearCheckoutAttempt = async (
  expectedIdempotencyKey: string,
) =>
  withCheckoutAttemptStorageLock(async () => {
    const storageKey = await checkoutAttemptStorageKey();
    const stored = normalizeCheckoutAttempt(
      await getItem<PersistedCheckoutAttempt>(storageKey),
    );
    if (stored?.idempotencyKey === expectedIdempotencyKey) {
      await removeItem(storageKey);
    }
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

const pollOrder = async (orderRef: string, attempts = 4) => {
  const normalizedOrderRef = String(orderRef).trim();
  if (!/^[a-zA-Z0-9_-]{8,100}$/.test(normalizedOrderRef)) {
    throw new Error('PAYMENT_ORDER_REFERENCE_INVALID');
  }
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    let response;
    try {
      response = await publicRequest.post(
        `payment/reconcile/${encodeURIComponent(normalizedOrderRef)}`,
      );
    } catch {
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

const reconcilePendingCoinCheckoutOnce = async (): Promise<
  CoinCheckoutResult | null
> => {
  if (!CAN_START_EXTERNAL_CHECKOUT) return null;
  const attempt = await readCheckoutAttempt();
  if (!attempt) return null;
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
        const remembered = await rememberCheckoutOrder(attempt, orderRef);
        if (!remembered) throw new Error('CHECKOUT_ORDER_REFERENCE_UNAVAILABLE');
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
        const remembered = await rememberCheckoutOrder(attempt, orderRef);
        if (!remembered) throw new Error('CHECKOUT_ORDER_REFERENCE_UNAVAILABLE');
        return {
          success: false,
          pending: true,
          cancelled: false,
          coinsAdded: 0,
          orderRef,
          demo: false,
        };
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

  const status = await pollOrder(attempt.orderRef, 1);
  if (!status.pending) {
    await clearCheckoutAttempt(attempt.idempotencyKey);
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

export const reconcilePendingCoinCheckout = async (): Promise<
  CoinCheckoutResult | null
> => {
  const scope = await checkoutAttemptStorageKey();
  const current = checkoutReconciliationFlights.get(scope);
  if (current) return current;

  const operation = reconcilePendingCoinCheckoutOnce().finally(() => {
    if (checkoutReconciliationFlights.get(scope) === operation) {
      checkoutReconciliationFlights.delete(scope);
    }
  });
  checkoutReconciliationFlights.set(scope, operation);
  return operation;
};

const runCoinCheckout = async (
  coinPackage: DemoCoinPackage,
): Promise<CoinCheckoutResult> => {
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
  let attempt = await readCheckoutAttempt();
  if (attempt?.orderRef) {
    const previous = await pollOrder(attempt.orderRef, 1);
    if (previous.approved) {
      await clearCheckoutAttempt(attempt.idempotencyKey);
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
      await clearCheckoutAttempt(attempt.idempotencyKey);
      attempt = null;
    }
  }
  if (attempt && attempt.packageId !== packageId) {
    return {
      success: false,
      pending: true,
      cancelled: false,
      coinsAdded: 0,
      demo: false,
    };
  }
  attempt =
    attempt ||
    (await getOrCreateCheckoutAttempt(
      packageId,
      coinPackage.price,
      coinPackage.coins,
    ));
  idempotencyKey = attempt.idempotencyKey;
  try {
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
    const remembered = await rememberCheckoutOrder(attempt, orderRef);
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
        1,
      );
      if (!approved.approved) throw error;
      await clearCheckoutAttempt(idempotencyKey);
      return {
        success: true,
        pending: false,
        cancelled: false,
        coinsAdded: approved.coinsAdded,
        orderRef: String(closed?.order_ref || attempt.orderRef || ''),
        demo: false,
      };
    }
    if (code === 'pending_checkout_exists') {
      const pendingOrderRef = String(closed?.order_ref || '').trim();
      const resumablePaymentUrl = String(closed?.payment_url || '').trim();
      if (pendingOrderRef) {
        const remembered = await rememberCheckoutOrder(
          attempt,
          pendingOrderRef,
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
          'package_terms_changed',
        ].includes(code)
      ) {
        await clearCheckoutAttempt(idempotencyKey);
      }
      throw error;
    }
  }
  try {
    const callbackUrl = await openCheckoutSurface(paymentUrl);
    const callback = resultFromUrl(callbackUrl);
    if (!callback.valid) throw new Error('PAYMENT_CALLBACK_INVALID');
    if (callback.orderRef && callback.orderRef !== orderRef) {
      throw new Error('PAYMENT_CALLBACK_ORDER_MISMATCH');
    }

    const status = await pollOrder(orderRef);
    if (!status.pending) {
      await clearCheckoutAttempt(idempotencyKey);
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
      // Closing the browser does not prove that the provider-side order was
      // cancelled. Keep the same durable key so a retry resumes/reconciles the
      // existing payable order instead of creating a second chargeable order.
      return {
        success: false,
        pending: true,
        cancelled: true,
        coinsAdded: 0,
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
  const scope = await checkoutAttemptStorageKey();
  const current = checkoutFlights.get(scope);
  if (current) return current;

  // Keep navigation recovery separate from the financial attempt: the latter
  // may be closed by background reconciliation before Navigation mounts. A
  // live caller acknowledges this receipt on return; a killed process cannot,
  // so cold start restores the exact course/wallet context once.
  const returnClaim = options.returnTo
    ? await savePendingCheckoutReturn(options.returnTo).catch(() => undefined)
    : undefined;
  const operation = runCoinCheckout(coinPackage).finally(async () => {
    if (checkoutFlights.get(scope) === operation) {
      checkoutFlights.delete(scope);
    }
    if (returnClaim) {
      await acknowledgePendingCheckoutReturn(returnClaim).catch(
        () => undefined,
      );
    }
  });
  checkoutFlights.set(scope, operation);
  return operation;
};
