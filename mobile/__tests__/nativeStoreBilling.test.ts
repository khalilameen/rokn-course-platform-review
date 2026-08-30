const mockExpoIap = {
  fetchProducts: jest.fn(),
  finishTransaction: jest.fn(),
  getAvailablePurchases: jest.fn(),
  initConnection: jest.fn(),
  purchaseErrorListener: jest.fn(),
  purchaseUpdatedListener: jest.fn(),
  requestPurchase: jest.fn(),
};
const mockApi = {
  get: jest.fn(),
  post: jest.fn(),
};

jest.mock('expo-iap', () => mockExpoIap);
jest.mock('../src/constants/api', () => ({publicRequest: mockApi}));
jest.mock('../src/constants/distribution', () => ({
  DISTRIBUTION_CHANNEL: 'play',
  IS_APP_STORE_DISTRIBUTION: false,
  IS_PLAY_DISTRIBUTION: true,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));

describe('native store billing', () => {
  let purchaseUpdate: (purchase: Record<string, unknown>) => void;

  beforeEach(() => {
    jest.resetModules();
    Object.values(mockExpoIap).forEach(mock => mock.mockReset());
    mockApi.get.mockReset();
    mockApi.post.mockReset();
    mockExpoIap.initConnection.mockResolvedValue(true);
    mockExpoIap.getAvailablePurchases.mockResolvedValue([]);
    mockExpoIap.purchaseUpdatedListener.mockImplementation(listener => {
      purchaseUpdate = listener;
      return {remove: jest.fn()};
    });
    mockExpoIap.purchaseErrorListener.mockReturnValue({remove: jest.fn()});
  });

  it('uses the store-localized product and omits unconfigured packages', async () => {
    mockExpoIap.fetchProducts.mockResolvedValue([
      {
        id: 'rokn.coins.600',
        price: 119.99,
        displayPrice: '١١٩٫٩٩ ج.م.',
        currency: 'EGP',
        platform: 'android',
        type: 'in-app',
      },
    ]);
    const {hydrateNativeStorePackages} = require('../src/services/nativeStoreBilling');

    const result = await hydrateNativeStorePackages([
      {
        id: '1',
        coins: 600,
        price: 120,
        label: '600',
        storeProductIds: {google: 'rokn.coins.600'},
      },
      {id: '2', coins: 900, price: 170, label: '900'},
    ]);

    expect(result).toHaveLength(1);
    expect(result[0]).toMatchObject({
      id: '1',
      price: 119.99,
      displayPrice: '١١٩٫٩٩ ج.م.',
    });
    expect(mockExpoIap.fetchProducts).toHaveBeenCalledWith({
      skus: ['rokn.coins.600'],
      type: 'in-app',
    });
  });

  it('finishes a consumable only after the backend verifies and credits it', async () => {
    mockApi.get.mockResolvedValue({
      data: {data: {google_obfuscated_account_id: 'account-binding'}},
    });
    mockApi.post.mockResolvedValue({
      data: {data: {coins_added: 600, finalize_transaction: true}},
    });
    mockExpoIap.requestPurchase.mockImplementation(async () => {
      purchaseUpdate({
        id: 'purchase-1',
        store: 'google',
        productId: 'rokn.coins.600',
        purchaseState: 'purchased',
        purchaseToken: 'store-token',
        transactionId: 'GPA.1111',
        quantity: 1,
        isAutoRenewing: false,
        transactionDate: Date.now(),
      });
      return null;
    });
    const {purchaseNativeCoinPackage} = require('../src/services/nativeStoreBilling');

    const result = await purchaseNativeCoinPackage({
      id: '1',
      coins: 600,
      price: 120,
      label: '600',
      storeProductIds: {google: 'rokn.coins.600'},
    });

    expect(mockExpoIap.requestPurchase).toHaveBeenCalledWith({
      request: {
        google: {
          skus: ['rokn.coins.600'],
          obfuscatedAccountId: 'account-binding',
        },
      },
      type: 'in-app',
    });
    expect(mockApi.post).toHaveBeenCalledWith('store-purchases/verify', {
      provider: 'google',
      product_id: 'rokn.coins.600',
      purchase_token: 'store-token',
      transaction_id: 'GPA.1111',
    });
    expect(mockExpoIap.finishTransaction).toHaveBeenCalledWith({
      purchase: expect.objectContaining({purchaseToken: 'store-token'}),
      isConsumable: true,
    });
    expect(result).toMatchObject({success: true, coinsAdded: 600, demo: false});
    expect(mockApi.post.mock.invocationCallOrder[0]).toBeLessThan(
      mockExpoIap.finishTransaction.mock.invocationCallOrder[0],
    );
  });

  it('never finishes the store transaction when server verification fails', async () => {
    mockApi.get.mockResolvedValue({
      data: {data: {google_obfuscated_account_id: 'account-binding'}},
    });
    mockApi.post.mockRejectedValue(new Error('verification unavailable'));
    mockExpoIap.requestPurchase.mockImplementation(async () => {
      purchaseUpdate({
        id: 'purchase-2',
        store: 'google',
        productId: 'rokn.coins.600',
        purchaseState: 'purchased',
        purchaseToken: 'store-token-two',
        transactionId: 'GPA.2222',
        quantity: 1,
        isAutoRenewing: false,
        transactionDate: Date.now(),
      });
      return null;
    });
    const {purchaseNativeCoinPackage} = require('../src/services/nativeStoreBilling');

    await expect(
      purchaseNativeCoinPackage({
        id: '1',
        coins: 600,
        price: 120,
        label: '600',
        storeProductIds: {google: 'rokn.coins.600'},
      }),
    ).rejects.toThrow('verification unavailable');
    expect(mockExpoIap.finishTransaction).not.toHaveBeenCalled();
  });
});
