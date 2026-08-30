import {
  fetchProducts,
  finishTransaction,
  getAvailablePurchases,
  initConnection,
  purchaseErrorListener,
  purchaseUpdatedListener,
  requestPurchase,
  type Product,
  type Purchase,
} from 'expo-iap';
import {publicRequest} from '../constants/api';
import {
  DISTRIBUTION_CHANNEL,
  IS_APP_STORE_DISTRIBUTION,
  IS_PLAY_DISTRIBUTION,
} from '../constants/distribution';
import type {DemoCoinPackage} from './demoExperience';
import {payload} from './api/common';
import {reportClientError} from './operationalTelemetry';

type StoreBillingContext = {
  google_obfuscated_account_id?: unknown;
  apple_app_account_token?: unknown;
};

type StoreVerificationResult = {
  coins_added?: unknown;
  finalize_transaction?: unknown;
  already_processed?: unknown;
};

export type NativeCoinCheckoutResult = {
  success: boolean;
  pending: boolean;
  cancelled: boolean;
  coinsAdded: number;
  orderRef?: string;
  demo: false;
};

type ActivePurchase = {
  productId: string;
  resolve: (value: NativeCoinCheckoutResult) => void;
  reject: (reason?: unknown) => void;
  timer: ReturnType<typeof setTimeout>;
};

let connectionPromise: Promise<void> | null = null;
let listenersReady = false;
let outstandingReconciled = false;
let activePurchase: ActivePurchase | null = null;
const processing = new Map<string, Promise<NativeCoinCheckoutResult>>();
const creditListeners = new Set<(result: NativeCoinCheckoutResult) => void>();

const emitCredit = (result: NativeCoinCheckoutResult) => {
  if (result.success) creditListeners.forEach(listener => listener(result));
};

const provider = () => {
  if (IS_PLAY_DISTRIBUTION) return 'google' as const;
  if (IS_APP_STORE_DISTRIBUTION) return 'apple' as const;
  throw new Error('NATIVE_STORE_UNAVAILABLE_FOR_DISTRIBUTION');
};

const packageProductId = (coinPackage: DemoCoinPackage) =>
  IS_PLAY_DISTRIBUTION
    ? coinPackage.storeProductIds?.google
    : IS_APP_STORE_DISTRIBUTION
      ? coinPackage.storeProductIds?.apple
      : undefined;

const purchaseTransactionId = (purchase: Purchase) =>
  'transactionId' in purchase && purchase.transactionId
    ? String(purchase.transactionId)
    : undefined;

const purchaseKey = (purchase: Purchase) =>
  String(
    purchase.purchaseToken ||
      purchaseTransactionId(purchase) ||
      `${purchase.store}:${purchase.productId}:${purchase.id}`,
  );

const cancelledError = (error: {code?: unknown}) => {
  const code = String(error.code || '').toLowerCase();
  return code.includes('cancel') || code.includes('user');
};

const settleActive = (
  productId: string,
  action: (active: ActivePurchase) => void,
) => {
  if (!activePurchase || activePurchase.productId !== productId) return;
  const current = activePurchase;
  activePurchase = null;
  clearTimeout(current.timer);
  action(current);
};

const verifyAndFinish = async (
  purchase: Purchase,
): Promise<NativeCoinCheckoutResult> => {
  if (purchase.purchaseState === 'pending') {
    return {
      success: false,
      pending: true,
      cancelled: false,
      coinsAdded: 0,
      orderRef: purchaseTransactionId(purchase),
      demo: false as const,
    };
  }
  if (purchase.purchaseState !== 'purchased') {
    throw new Error('STORE_PURCHASE_NOT_COMPLETED');
  }
  const purchaseToken = String(purchase.purchaseToken || '').trim();
  if (!purchaseToken) throw new Error('STORE_PURCHASE_TOKEN_MISSING');

  const key = purchaseKey(purchase);
  const existing = processing.get(key);
  if (existing) return existing;

  const operation: Promise<NativeCoinCheckoutResult> = (async () => {
    const response = await publicRequest.post('store-purchases/verify', {
      provider: provider(),
      product_id: purchase.productId,
      purchase_token: purchaseToken,
      transaction_id: purchaseTransactionId(purchase),
    });
    const verified = payload<StoreVerificationResult>(response);
    if (verified.finalize_transaction !== true) {
      throw new Error('STORE_SERVER_DID_NOT_AUTHORIZE_FINALIZATION');
    }

    // Consumables are finalized only after the backend has atomically recorded
    // and credited them. A network/server failure leaves the transaction in the
    // store queue, so it is recovered without asking the learner to pay again.
    await finishTransaction({purchase, isConsumable: true});

    return {
      success: true,
      pending: false,
      cancelled: false,
      coinsAdded: Math.max(0, Number(verified.coins_added || 0)),
      orderRef: purchaseTransactionId(purchase),
      demo: false,
    };
  })();
  processing.set(key, operation);
  try {
    return await operation;
  } finally {
    processing.delete(key);
  }
};

const handlePurchaseUpdate = async (purchase: Purchase) => {
  try {
    const result = await verifyAndFinish(purchase);
    emitCredit(result);
    settleActive(purchase.productId, active => active.resolve(result));
  } catch (error: unknown) {
    reportClientError(
      error instanceof Error ? error : new Error('native_store_verification_failed'),
      {source: 'native_store_billing'},
    );
    settleActive(purchase.productId, active => active.reject(error));
  }
};

const installListeners = () => {
  if (listenersReady) return;
  listenersReady = true;
  purchaseUpdatedListener(purchase => {
    void handlePurchaseUpdate(purchase);
  });
  purchaseErrorListener(error => {
    if (!activePurchase) return;
    const current = activePurchase;
    activePurchase = null;
    clearTimeout(current.timer);
    if (cancelledError(error)) {
      current.resolve({
        success: false,
        pending: false,
        cancelled: true,
        coinsAdded: 0,
        demo: false,
      });
      return;
    }
    current.reject(new Error(String(error.code || error.message || 'STORE_PURCHASE_FAILED')));
  });
};

const ensureConnection = async () => {
  if (!IS_PLAY_DISTRIBUTION && !IS_APP_STORE_DISTRIBUTION) {
    throw new Error('NATIVE_STORE_UNAVAILABLE_FOR_DISTRIBUTION');
  }
  if (!connectionPromise) {
    connectionPromise = (async () => {
      const connected = await initConnection();
      if (!connected) throw new Error('STORE_CONNECTION_UNAVAILABLE');
      installListeners();
    })().catch(error => {
      connectionPromise = null;
      throw error;
    });
  }
  await connectionPromise;
};

const reconcileOutstandingPurchases = async () => {
  if (outstandingReconciled) return;
  const purchases = await getAvailablePurchases({
    alsoPublishToEventListenerIOS: false,
    onlyIncludeActiveItemsIOS: false,
  });
  for (const purchase of purchases) {
    if (purchase.purchaseState === 'purchased') {
      // Fail closed: starting another charge while an earlier consumable is
      // still unverified can double-charge the learner. The next refresh
      // retries this exact store transaction.
      emitCredit(await verifyAndFinish(purchase));
    }
  }
  outstandingReconciled = true;
};

export const hydrateNativeStorePackages = async (
  packages: DemoCoinPackage[],
): Promise<DemoCoinPackage[]> => {
  await ensureConnection();
  await reconcileOutstandingPurchases();
  const configured = packages
    .map(coinPackage => ({coinPackage, productId: packageProductId(coinPackage)}))
    .filter(
      (entry): entry is {coinPackage: DemoCoinPackage; productId: string} =>
        Boolean(entry.productId),
    );
  if (!configured.length) return [];

  const products = (await fetchProducts({
    skus: configured.map(item => item.productId),
    type: 'in-app',
  })) as Product[];
  const byId = new Map(products.map(product => [product.id, product]));

  return configured.flatMap(({coinPackage, productId}) => {
    const product = byId.get(productId);
    if (!product) return [];
    return [{
      ...coinPackage,
      price: Number.isFinite(Number(product.price))
        ? Number(product.price)
        : coinPackage.price,
      displayPrice: product.displayPrice,
    }];
  });
};

export const purchaseNativeCoinPackage = async (
  coinPackage: DemoCoinPackage,
): Promise<NativeCoinCheckoutResult> => {
  const productId = packageProductId(coinPackage);
  if (!productId) throw new Error('STORE_PRODUCT_NOT_CONFIGURED');
  if (activePurchase) throw new Error('STORE_PURCHASE_ALREADY_IN_PROGRESS');

  await ensureConnection();
  await reconcileOutstandingPurchases();
  const context = payload<StoreBillingContext>(
    await publicRequest.get('store-billing/context'),
  );
  const googleAccount = String(context.google_obfuscated_account_id || '').trim();
  const appleAccount = String(context.apple_app_account_token || '').trim();
  if (IS_PLAY_DISTRIBUTION && !googleAccount) {
    throw new Error('STORE_ACCOUNT_BINDING_UNAVAILABLE');
  }
  if (IS_APP_STORE_DISTRIBUTION && !appleAccount) {
    throw new Error('STORE_ACCOUNT_BINDING_UNAVAILABLE');
  }

  let resolvePurchase!: (value: NativeCoinCheckoutResult) => void;
  let rejectPurchase!: (reason?: unknown) => void;
  const outcome = new Promise<NativeCoinCheckoutResult>((resolve, reject) => {
    resolvePurchase = resolve;
    rejectPurchase = reject;
  });
  const timer = setTimeout(() => {
    settleActive(productId, active => active.resolve({
      success: false,
      pending: true,
      cancelled: false,
      coinsAdded: 0,
      demo: false,
    }));
  }, 5 * 60 * 1000);
  activePurchase = {
    productId,
    resolve: resolvePurchase,
    reject: rejectPurchase,
    timer,
  };

  try {
    await requestPurchase({
      request: IS_PLAY_DISTRIBUTION
        ? {
            google: {
              skus: [productId],
              obfuscatedAccountId: googleAccount,
            },
          }
        : {
            apple: {
              sku: productId,
              appAccountToken: appleAccount,
              andDangerouslyFinishTransactionAutomatically: false,
            },
          },
      type: 'in-app',
    });
  } catch (error: unknown) {
    settleActive(productId, active => active.reject(error));
  }

  return outcome;
};

export const subscribeNativeStoreCredits = (
  listener: (result: NativeCoinCheckoutResult) => void,
) => {
  creditListeners.add(listener);
  return () => {
    creditListeners.delete(listener);
  };
};

export const nativeStoreChannel = DISTRIBUTION_CHANNEL;
