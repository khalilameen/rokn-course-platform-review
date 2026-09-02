let mockCourseAccountEpoch = 1;

jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: jest.fn(async () => null),
}));

jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {post: jest.fn()},
}));

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (key: string, boundary?: {scope: string}) =>
      `${key}:${boundary?.scope ?? 'user-a'}`,
  ),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: mockCourseAccountEpoch,
    scope: 'user-a',
  })),
  assertAccountSessionBoundary: jest.fn((boundary: {epoch: number}) => {
    if (boundary.epoch !== mockCourseAccountEpoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  }),
  saveItem: jest.fn(async () => {
    // Reproduce logout/account replacement in the narrow gap after the
    // durable idempotency intent is stored and before authorization starts.
    mockCourseAccountEpoch = 2;
    return true;
  }),
  removeItem: jest.fn(async () => undefined),
}));

describe('course purchase account boundary', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockCourseAccountEpoch = 1;
  });

  it('never authorizes an old mounted purchase sheet against the next account', async () => {
    const {publicRequest} = require('../src/constants/api') as {
      publicRequest: {post: jest.Mock};
    };
    const {purchaseCourse} = require('../src/services/api/access') as {
      purchaseCourse: (
        courseId: string,
        plan: string,
        coupon?: string,
        expectedPrice?: number,
      ) => Promise<unknown>;
    };

    await expect(
      purchaseCourse('64', 'guided', undefined, 700),
    ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(publicRequest.post).not.toHaveBeenCalled();
  });
});
