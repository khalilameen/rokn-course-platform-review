jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));
jest.mock('expo-web-browser', () => ({
  openAuthSessionAsync: jest.fn(),
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(async (key: string) => key),
  getItem: jest.fn(async () => null),
  removeItem: jest.fn(async () => undefined),
  saveItem: jest.fn(async () => true),
}));
jest.mock('../src/services/demoExperience', () => ({
  creditDemoCoins: jest.fn(),
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_EXTERNAL_CHECKOUT: true,
  CAN_START_NATIVE_CHECKOUT: false,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/services/productFeatures', () => ({
  requireProductFeature: jest.fn(),
}));

describe('coin checkout boundary', () => {
  const originalProfile = process.env.EXPO_PUBLIC_BUILD_PROFILE;
  const originalDemoFlag = process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO;

  afterAll(() => {
    if (originalProfile === undefined) {
      delete process.env.EXPO_PUBLIC_BUILD_PROFILE;
    } else {
      process.env.EXPO_PUBLIC_BUILD_PROFILE = originalProfile;
    }
    if (originalDemoFlag === undefined) {
      delete process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO;
    } else {
      process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = originalDemoFlag;
    }
    jest.resetModules();
  });

  it('rejects synthetic packages outside an opted-in test build', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '0';
    jest.resetModules();

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<unknown>;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };

    await expect(
      openCoinCheckout({
        id: 'demo-4200',
        coins: 4200,
        price: 249,
        label: 'demo',
      }),
    ).rejects.toThrow('LOCAL_DEMO_DISABLED');
    expect(publicRequest.post).not.toHaveBeenCalled();
  });
});
