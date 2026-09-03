import AsyncStorage from '@react-native-async-storage/async-storage';

const mockGet = jest.fn();
let mockSessionSnapshot: {
  ready: boolean;
  session: unknown;
  epoch: number;
};

jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
  digestStringAsync: jest.fn(async () => 'a'.repeat(64)),
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));
jest.mock('../src/services/secureSession', () => {
  const actual = jest.requireActual('../src/services/secureSession');
  return {...actual, peekSecureSession: () => mockSessionSnapshot};
});

import {
  getCourseDetails,
  getPublishedCoursesPage,
} from '../src/services/api/courses';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return {promise, resolve, reject};
};

describe('account-scoped course cache boundary', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
  });

  it.each([
    [
      'guest to user',
      {ready: false, session: null, epoch: 1},
      {ready: true, session: {user: {id: 7}, api_token: 'token-seven'}, epoch: 2},
    ],
    [
      'user to user',
      {ready: true, session: {user: {id: 7}, api_token: 'token-seven'}, epoch: 4},
      {ready: true, session: {user: {id: 8}, api_token: 'token-eight'}, epoch: 5},
    ],
  ])('does not return the captured owner cache after a %s switch', async (_label, before, after) => {
    mockSessionSnapshot = before;
    const request = deferred<never>();
    let started!: () => void;
    const requestStarted = new Promise<void>(resolve => {
      started = resolve;
    });
    mockGet.mockImplementation(() => {
      started();
      return request.promise;
    });

    const flight = getCourseDetails('52');
    await requestStarted;
    mockSessionSnapshot = after;
    request.reject(new Error('offline'));

    await expect(flight).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
  });

  it('keeps the guest course response when slow session restore settles empty', async () => {
    mockSessionSnapshot = {ready: false, session: null, epoch: 10};
    const request = deferred<unknown>();
    let started!: () => void;
    const requestStarted = new Promise<void>(resolve => {
      started = resolve;
    });
    mockGet.mockImplementation(() => {
      started();
      return request.promise;
    });

    const flight = getPublishedCoursesPage();
    await requestStarted;
    mockSessionSnapshot = {ready: true, session: null, epoch: 11};
    request.resolve({
      data: {
        data: {
          courses: [{id: 52, title: 'كورس ركن'}],
          catalogue_revision: 1,
          pagination: {current_page: 1, last_page: 1, total: 1},
        },
      },
    });

    await expect(flight).resolves.toMatchObject({
      courses: [expect.objectContaining({id: '52'})],
    });
  });
});
