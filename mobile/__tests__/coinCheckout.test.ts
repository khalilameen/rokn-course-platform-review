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

  beforeEach(() => {
    jest.clearAllMocks();
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      saveItem: jest.Mock;
    };
    helpers.getItem.mockResolvedValue(null);
    helpers.saveItem.mockResolvedValue(true);
  });

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

  it('keeps the same payment intent when the checkout browser is closed', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '0';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const {removeItem} = require('../src/constants/helpers') as {
      removeItem: jest.Mock;
    };
    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{cancelled: boolean}>;
    };

    publicRequest.post.mockResolvedValueOnce({
      data: {
        payment_url: 'https://checkout.kashier.io/session',
        order_ref: 'PKG-ONE',
        idempotency_key: '11111111-1111-4111-8111-111111111111',
      },
    });
    WebBrowser.openAuthSessionAsync.mockResolvedValueOnce({type: 'cancel'});

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({cancelled: true});
    expect(removeItem).not.toHaveBeenCalled();
  });

  it('recovers a captured payment after the browser was closed', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '0';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {get: jest.Mock; post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      removeItem: jest.Mock;
    };
    helpers.getItem.mockResolvedValue({
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
      packageId: 7,
      createdAt: '2026-08-31T10:00:00.000Z',
      orderRef: 'PKG-CAPTURED',
    });
    publicRequest.post.mockResolvedValueOnce({
      data: {
        data: {
          status: 'approved',
          financial_status: 'settled',
          package: {coins: 600},
        },
      },
    });

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{success: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({success: true, orderRef: 'PKG-CAPTURED'});
    expect(publicRequest.post).toHaveBeenCalledWith(
      'payment/reconcile/PKG-CAPTURED',
    );
    expect(WebBrowser.openAuthSessionAsync).not.toHaveBeenCalled();
    expect(helpers.removeItem).toHaveBeenCalled();
  });

  it('reopens the same pending checkout instead of trapping the learner', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '0';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
    };
    helpers.getItem.mockResolvedValue({
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
      packageId: 7,
      expectedPrice: 49,
      expectedCoins: 600,
      createdAt: '2026-09-01T11:55:43.000Z',
      orderRef: 'PKG-PENDING-RETRY',
    });
    publicRequest.post
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'pending',
            financial_status: 'pending',
            package: {coins: 600},
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          payment_url: 'https://checkout.kashier.io/resume',
          order_ref: 'PKG-PENDING-RETRY',
          idempotency_key: '11111111-1111-4111-8111-111111111111',
        },
      });
    WebBrowser.openAuthSessionAsync.mockResolvedValueOnce({type: 'cancel'});

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{cancelled: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({
      cancelled: true,
      orderRef: 'PKG-PENDING-RETRY',
    });
    expect(WebBrowser.openAuthSessionAsync).toHaveBeenCalledWith(
      'https://checkout.kashier.io/resume',
      'rokn://payment-result',
      {showInRecents: true},
    );
  });

  it('keeps an older package recoverable while opening the newly selected package', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '0';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      removeItem: jest.Mock;
      saveItem: jest.Mock;
    };
    const olderAttempt = {
      idempotencyKey: '22222222-2222-4222-8222-222222222222',
      packageId: 2,
      expectedPrice: 99,
      expectedCoins: 1200,
      createdAt: '2026-09-01T11:55:43.000Z',
      orderRef: 'PKG-OLDER-PENDING',
    };
    const newAttempt = {
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
      packageId: 3,
      expectedPrice: 149,
      expectedCoins: 2400,
      createdAt: expect.any(String),
    };
    helpers.getItem
      .mockResolvedValueOnce(olderAttempt)
      .mockResolvedValueOnce(olderAttempt)
      .mockResolvedValueOnce({attempts: [olderAttempt, newAttempt]});
    publicRequest.post.mockResolvedValueOnce({
      data: {
        payment_url: 'https://checkout.kashier.io/new-package',
        order_ref: 'PKG-NEW-SELECTION',
        idempotency_key: '11111111-1111-4111-8111-111111111111',
      },
    });
    WebBrowser.openAuthSessionAsync.mockResolvedValueOnce({type: 'cancel'});

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{cancelled: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '3', coins: 2400, price: 149, label: 'باقة'}),
    ).resolves.toMatchObject({
      pending: true,
      cancelled: true,
      orderRef: 'PKG-NEW-SELECTION',
    });
    expect(helpers.removeItem).not.toHaveBeenCalled();
    expect(helpers.saveItem).toHaveBeenCalledWith(
      expect.any(String),
      expect.objectContaining({
        attempts: expect.arrayContaining([
          expect.objectContaining({orderRef: 'PKG-OLDER-PENDING'}),
          expect.objectContaining({packageId: 3}),
        ]),
      }),
    );
    expect(WebBrowser.openAuthSessionAsync).toHaveBeenCalledWith(
      'https://checkout.kashier.io/new-package',
      'rokn://payment-result',
      {showInRecents: true},
    );
  });

  it('replaces an expired attempt in the same tap', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '0';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const helpers = require('../src/constants/helpers') as {
      getItem: jest.Mock;
      removeItem: jest.Mock;
    };
    const expiredAttempt = {
      idempotencyKey: '22222222-2222-4222-8222-222222222222',
      packageId: 2,
      expectedPrice: 99,
      expectedCoins: 1200,
      createdAt: '2026-09-01T10:00:00.000Z',
      orderRef: 'PKG-EXPIRED-ATTEMPT',
    };
    helpers.getItem
      .mockResolvedValueOnce(expiredAttempt)
      .mockResolvedValueOnce(expiredAttempt)
      .mockResolvedValueOnce(null)
      .mockResolvedValueOnce(null);
    publicRequest.post
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'pending',
            financial_status: 'pending',
            package: {coins: 1200},
          },
        },
      })
      .mockRejectedValueOnce({
        response: {
          data: {
            code: 'checkout_attempt_expired',
            data: {order_ref: 'PKG-EXPIRED-ATTEMPT', status: 'cancelled'},
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          payment_url: 'https://checkout.kashier.io/fresh-attempt',
          order_ref: 'PKG-FRESH-ATTEMPT',
          idempotency_key: '11111111-1111-4111-8111-111111111111',
        },
      });
    WebBrowser.openAuthSessionAsync.mockResolvedValueOnce({type: 'cancel'});

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{cancelled: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '2', coins: 1200, price: 99, label: 'باقة'}),
    ).resolves.toMatchObject({
      cancelled: true,
      orderRef: 'PKG-FRESH-ATTEMPT',
    });
    expect(helpers.removeItem).toHaveBeenCalled();
  });

  it('resumes the server checkout after app payment state was lost', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '0';
    jest.resetModules();

    const WebBrowser = require('expo-web-browser') as {
      openAuthSessionAsync: jest.Mock;
    };
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    publicRequest.post.mockRejectedValueOnce({
      response: {
        data: {
          code: 'pending_checkout_exists',
          data: {
            order_ref: 'PKG-SERVER-PENDING',
            status: 'pending',
            payment_url: 'https://checkout.kashier.io/server-resume',
          },
        },
      },
    });
    WebBrowser.openAuthSessionAsync.mockResolvedValueOnce({type: 'cancel'});

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{cancelled: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({
      cancelled: true,
      orderRef: 'PKG-SERVER-PENDING',
    });
    expect(WebBrowser.openAuthSessionAsync).toHaveBeenCalledWith(
      'https://checkout.kashier.io/server-resume',
      'rokn://payment-result',
      {showInRecents: true},
    );
  });

  it('accepts an approved idempotent replay instead of showing a false error', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '0';
    jest.resetModules();

    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    publicRequest.post
      .mockRejectedValueOnce({
        response: {
          data: {
            code: 'checkout_attempt_closed',
            data: {order_ref: 'PKG-PAID', status: 'approved'},
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'approved',
            financial_status: 'settled',
            package: {coins: 600},
          },
        },
      });

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{success: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({success: true, orderRef: 'PKG-PAID'});
  });

  it('accepts an approved replay after the response interceptor unwraps AxiosError', async () => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'production';
    process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '0';
    jest.resetModules();

    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    publicRequest.post
      .mockRejectedValueOnce({
        data: {
          code: 'checkout_attempt_closed',
          data: {order_ref: 'PKG-PAID-UNWRAPPED', status: 'approved'},
        },
        status: 409,
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            status: 'approved',
            financial_status: 'settled',
            package: {coins: 600},
          },
        },
      });

    const {openCoinCheckout} = require('../src/services/coinCheckout') as {
      openCoinCheckout: (coinPackage: {
        id: string;
        coins: number;
        price: number;
        label: string;
      }) => Promise<{success: boolean; orderRef?: string}>;
    };

    await expect(
      openCoinCheckout({id: '7', coins: 600, price: 49, label: 'باقة'}),
    ).resolves.toMatchObject({
      success: true,
      orderRef: 'PKG-PAID-UNWRAPPED',
    });
  });
});
