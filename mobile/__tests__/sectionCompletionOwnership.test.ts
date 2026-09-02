jest.mock('../src/constants/api', () => ({
  publicRequest: {post: jest.fn()},
}));

let mockActiveBoundary = {epoch: 1, scope: 'user-a'};
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: (boundary: {epoch: number}) => {
    if (boundary.epoch !== mockActiveBoundary.epoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  captureAccountSessionBoundary: jest.fn(async () => ({...mockActiveBoundary})),
  getCurrentAccountStorageScope: jest.fn(async () => mockActiveBoundary.scope),
}));

jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(async () => true),
}));

jest.mock('../src/services/productFeatures', () => ({
  requireProductFeature: jest.fn(async () => undefined),
}));

jest.mock('../src/config/runtime', () => ({
  isLocalDemoId: jest.fn(() => false),
}));

jest.mock('../src/components/VideoPlayer/courseLearning/persistence', () => ({
  isWatchHistoryEnabled: jest.fn(async () => true),
  updatePlayerState: jest.fn(async () => undefined),
  updatePlayerStateForScope: jest.fn(async () => undefined),
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../src/constants/api';
import {
  markSectionComplete,
  resetPlaybackRuntimeState,
  retryPendingSectionCompletions,
} from '../src/components/VideoPlayer/courseLearning/playback';
import {updatePlayerStateForScope} from '../src/components/VideoPlayer/courseLearning/persistence';

const apiPost = publicRequest.post as jest.MockedFunction<
  typeof publicRequest.post
>;
const scopedUpdate = updatePlayerStateForScope as jest.MockedFunction<
  typeof updatePlayerStateForScope
>;

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(next => {
    resolve = next;
  });
  return {promise, resolve};
};

describe('section completion ownership', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    mockActiveBoundary = {epoch: 1, scope: 'user-a'};
    resetPlaybackRuntimeState();
  });

  it('coalesces duplicate completion actions for one account and section', async () => {
    const request = deferred<unknown>();
    apiPost.mockReturnValue(request.promise as never);

    const first = markSectionComplete('31', '72');
    const second = markSectionComplete('31', '72');
    await Promise.resolve();
    await Promise.resolve();

    expect(apiPost).toHaveBeenCalledTimes(1);
    request.resolve({});
    await expect(Promise.all([first, second])).resolves.toEqual([true, true]);
    expect(scopedUpdate).toHaveBeenCalledTimes(1);
    expect(scopedUpdate).toHaveBeenCalledWith(
      'user-a',
      expect.any(Function),
      {epoch: 1, scope: 'user-a'},
    );
  });

  it('does not let an old response complete or block the new account', async () => {
    const oldRequest = deferred<unknown>();
    const newRequest = deferred<unknown>();
    apiPost
      .mockReturnValueOnce(oldRequest.promise as never)
      .mockReturnValueOnce(newRequest.promise as never);

    const oldCompletion = markSectionComplete('31', '72');
    await Promise.resolve();
    await Promise.resolve();
    mockActiveBoundary = {epoch: 2, scope: 'user-b'};
    const newCompletion = markSectionComplete('31', '72');
    await Promise.resolve();
    await Promise.resolve();

    expect(apiPost).toHaveBeenCalledTimes(2);
    newRequest.resolve({});
    await expect(newCompletion).resolves.toBe(true);
    oldRequest.resolve({});
    await expect(oldCompletion).resolves.toBe(false);
    expect(scopedUpdate).toHaveBeenCalledTimes(1);
    expect(scopedUpdate).toHaveBeenCalledWith(
      'user-b',
      expect.any(Function),
      {epoch: 2, scope: 'user-b'},
    );
    expect(
      (await AsyncStorage.getAllKeys()).some(key =>
        key.startsWith('@rokn/section-completion/v1:user-a:'),
      ),
    ).toBe(false);
  });

  it('shares one flight with durable retry instead of posting twice', async () => {
    await AsyncStorage.setItem(
      '@rokn/section-completion/v1:user-a:31:72',
      JSON.stringify({courseId: '31', sectionId: '72'}),
    );
    const request = deferred<unknown>();
    apiPost.mockReturnValue(request.promise as never);

    const direct = markSectionComplete('31', '72');
    await Promise.resolve();
    await Promise.resolve();
    const retry = retryPendingSectionCompletions();
    await Promise.resolve();
    await Promise.resolve();

    expect(apiPost).toHaveBeenCalledTimes(1);
    request.resolve({});
    await expect(Promise.all([direct, retry])).resolves.toEqual([
      true,
      undefined,
    ]);
    expect(scopedUpdate).toHaveBeenCalledTimes(1);
  });
});
