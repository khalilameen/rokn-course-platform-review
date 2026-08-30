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
import {creditDemoCoins, DemoCoinPackage} from './demoExperience';
import {
  CAN_START_EXTERNAL_CHECKOUT,
  CAN_START_NATIVE_CHECKOUT,
} from '../constants/distribution';
import {reportClientError} from './operationalTelemetry';
import {requireProductFeature} from './productFeatures';
import {errorCode} from '../utils/errorPayload';
import {LOCAL_DEMO_ENABLED} from '../config/runtime';

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
  createdAt: string;
};

const CHECKOUT_ATTEMPT_KEY = '@rokn/coin-checkout-attempt/v1';
const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
let checkoutAttemptStorageTail: Promise<void> = Promise.resolve();

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

const checkoutAttemptStorageKey = (packageId: number) =>
  accountScopedStorageKey(`${CHECKOUT_ATTEMPT_KEY}:${packageId}`);

const getOrCreateCheckoutIdempotencyKey = async (
  packageId: number,
): Promise<string> =>
  withCheckoutAttemptStorageLock(async () => {
    const storageKey = await checkoutAttemptStorageKey(packageId);
    const stored = await getItem<PersistedCheckoutAttempt>(storageKey);
    if (
      stored?.packageId === packageId &&
      typeof stored.idempotencyKey === 'string' &&
      UUID_PATTERN.test(stored.idempotencyKey)
    ) {
      return stored.idempotencyKey;
    }

    const idempotencyKey = Crypto.randomUUID().toLowerCase();
    const persisted = await saveItem(storageKey, {
      idempotencyKey,
      packageId,
      createdAt: new Date().toISOString(),
    } satisfies PersistedCheckoutAttempt);
    if (!persisted) {
      // Starting without durable intent identity would make a timeout unsafe to
      // retry: the server may already have created a payable order.
      throw new Error('CHECKOUT_IDEMPOTENCY_UNAVAILABLE');
    }

    return idempotencyKey;
  });

const clearCheckoutAttempt = async (
  packageId: number,
  expectedIdempotencyKey: string,
) =>
  withCheckoutAttemptStorageLock(async () => {
    const storageKey = await checkoutAttemptStorageKey(packageId);
    const stored = await getItem<PersistedCheckoutAttempt>(storageKey);
    if (stored?.idempotencyKey === expectedIdempotencyKey) {
      await removeItem(storageKey);
    }
  });

const resultFromUrl = (value: string) => {
  try {
    const query = value.split('?')[1]?.split('#')[0] || '';
    const params = query
      .split('&')
      .reduce<Record<string, string>>((result, pair) => {
        const [rawKey, ...rawValue] = pair.split('=');
        if (rawKey) {
          result[decodeURIComponent(rawKey)] = decodeURIComponent(
            rawValue.join('=') || '',
          );
        }
        return result;
      }, {});
    return {
      status: params.status?.toLowerCase(),
      orderRef: params.order_ref || params.orderRef || undefined,
      coins: Number(params.coins || 0),
    };
  } catch {
    return {status: undefined, orderRef: undefined, coins: 0};
  }
};

const openCheckoutSurface = async (url: string): Promise<string> => {
  if (nativeCheckout?.open) return nativeCheckout.open(url);
  // ASWebAuthenticationSession / Chrome Custom Tabs keep checkout inside the
  // app-owned flow while isolating payment credentials from our JavaScript.
  // The backend result page returns through this exact deep link.
  if (url.startsWith('https://')) {
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

const pollOrder = async (orderRef: string) => {
  for (let attempt = 0; attempt < 4; attempt += 1) {
    try {
      const response = await publicRequest.get(`payment/status/${orderRef}`);
      const status = String(response?.data?.status || '').toLowerCase();
      if (status === 'approved') return {approved: true, pending: false};
      if (['failed', 'cancelled', 'rejected'].includes(status)) {
        return {approved: false, pending: false};
      }
    } catch {
      // The callback may arrive before the status endpoint sees the webhook.
    }
    await new Promise<void>(resolve =>
      setTimeout(() => resolve(), 900 + attempt * 350),
    );
  }
  return {approved: false, pending: true};
};

export const openCoinCheckout = async (
  coinPackage: DemoCoinPackage,
): Promise<CoinCheckoutResult> => {
  if (CAN_START_NATIVE_CHECKOUT) {
    if (coinPackage.id.startsWith('demo-')) {
      throw new Error('LOCAL_DEMO_DISABLED_FOR_NATIVE_STORE');
    }
    await requireProductFeature('checkout');
    const {purchaseNativeCoinPackage} = await import('./nativeStoreBilling');
    return purchaseNativeCoinPackage(coinPackage);
  }
  if (!CAN_START_EXTERNAL_CHECKOUT) {
    throw new Error('CHECKOUT_DISABLED_FOR_DISTRIBUTION');
  }
  const isDemo = coinPackage.id.startsWith('demo-');
  if (isDemo && !LOCAL_DEMO_ENABLED) {
    throw new Error('LOCAL_DEMO_DISABLED');
  }
  if (!isDemo) {
    await requireProductFeature('checkout');
  }
  let paymentUrl = '';
  let orderRef = '';
  let idempotencyKey = '';

  if (isDemo) {
    orderRef = `DEMO-${Date.now()}`;
    paymentUrl =
      `rokn-demo://checkout?coins=${coinPackage.coins}` +
      `&price=${coinPackage.price}&order_ref=${encodeURIComponent(orderRef)}`;
  } else {
    const packageId = Number(coinPackage.id);
    idempotencyKey = await getOrCreateCheckoutIdempotencyKey(packageId);
    try {
      const response = await publicRequest.post(
        'payment/initiate',
        {
          package_id: packageId,
          idempotency_key: idempotencyKey,
        },
        {headers: {'Idempotency-Key': idempotencyKey}},
      );
      paymentUrl = String(response?.data?.payment_url || '');
      orderRef = String(response?.data?.order_ref || '');
      const echoedIdempotencyKey = String(
        response?.data?.idempotency_key || '',
      );
      if (
        !paymentUrl ||
        !orderRef ||
        (echoedIdempotencyKey && echoedIdempotencyKey !== idempotencyKey)
      ) {
        throw new Error('PAYMENT_SESSION_UNAVAILABLE');
      }
    } catch (error: unknown) {
      const code = errorCode(error);
      if (
        [
          'checkout_idempotency_conflict',
          'checkout_attempt_closed',
          'checkout_attempt_expired',
        ].includes(code)
      ) {
        await clearCheckoutAttempt(packageId, idempotencyKey);
      }
      throw error;
    }
  }

  try {
    const callbackUrl = await openCheckoutSurface(paymentUrl);
    const callback = resultFromUrl(callbackUrl);
    orderRef = callback.orderRef || orderRef;

    if (isDemo && callback.status === 'success') {
      await creditDemoCoins(
        coinPackage.coins,
        `شحن رصيد ركن بقيمة ${coinPackage.coins}`,
        `checkout:${orderRef}`,
      );
      return {
        success: true,
        pending: false,
        cancelled: false,
        coinsAdded: coinPackage.coins,
        orderRef,
        demo: true,
      };
    }

    if (!isDemo) {
      const status = await pollOrder(orderRef);
      if (!status.pending) {
        await clearCheckoutAttempt(Number(coinPackage.id), idempotencyKey);
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
        coinsAdded: status.approved ? coinPackage.coins : 0,
        orderRef,
        demo: false,
      };
    }
  } catch (error: unknown) {
    if (errorCode(error) === 'CHECKOUT_CANCELLED') {
      if (!isDemo && idempotencyKey) {
        await clearCheckoutAttempt(Number(coinPackage.id), idempotencyKey);
      }
      return {
        success: false,
        pending: false,
        cancelled: true,
        coinsAdded: 0,
        orderRef,
        demo: isDemo,
      };
    }
    reportClientError(
      error instanceof Error ? error : new Error('checkout_unknown_error'),
      {source: 'coin_checkout'},
    );
    throw error;
  }

  return {
    success: false,
    pending: !isDemo,
    cancelled: false,
    coinsAdded: 0,
    orderRef,
    demo: isDemo,
  };
};
