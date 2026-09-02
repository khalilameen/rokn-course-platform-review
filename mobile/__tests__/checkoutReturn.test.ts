import AsyncStorage from '@react-native-async-storage/async-storage';

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(async (key: string) => `${key}:user-a`),
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 0,
    scope: 'user-a',
  })),
}));

import {
  acknowledgePendingCheckoutReturn,
  claimPendingCheckoutReturn,
  savePendingCheckoutReturn,
} from '../src/navigation/checkoutReturn';

describe('checkout return recovery', () => {
  beforeEach(async () => {
    await AsyncStorage.clear();
  });

  it('survives a killed caller and is acknowledged once after navigation', async () => {
    const saved = await savePendingCheckoutReturn({
      name: 'CourseDetails',
      params: {courseId: '52', openPurchase: true, providerUrl: 'hidden'},
    });
    const claimed = await claimPendingCheckoutReturn();

    expect(claimed?.returnTo).toEqual({
      name: 'CourseDetails',
      params: {
        courseId: '52',
        openCodeRedemption: false,
        openFullTrackUpgrade: false,
        openPurchase: true,
        resumeAfterPreview: false,
        resumeReelId: undefined,
      },
    });
    expect(await acknowledgePendingCheckoutReturn(claimed!)).toBe(true);
    expect(await claimPendingCheckoutReturn()).toBeUndefined();
    expect(saved?.receipt).toBe(claimed?.receipt);
  });

  it('does not let an older acknowledgement delete a newer checkout return', async () => {
    const older = await savePendingCheckoutReturn({name: 'Wallet'});
    expect(older).toBeDefined();

    const originalRemoveItem = AsyncStorage.removeItem.bind(AsyncStorage);
    let releaseRemove!: () => void;
    let markRemoveStarted!: () => void;
    const removeStarted = new Promise<void>(resolve => {
      markRemoveStarted = resolve;
    });
    const removeGate = new Promise<void>(resolve => {
      releaseRemove = resolve;
    });
    const removeSpy = jest
      .spyOn(AsyncStorage, 'removeItem')
      .mockImplementationOnce(async key => {
        markRemoveStarted();
        await removeGate;
        await originalRemoveItem(key);
      });

    const acknowledgeOlder = acknowledgePendingCheckoutReturn(older!);
    await removeStarted;
    const saveNewer = savePendingCheckoutReturn({
      name: 'CourseDetails',
      params: {courseId: '71', openPurchase: true},
    });
    releaseRemove();
    await expect(acknowledgeOlder).resolves.toBe(true);
    const newer = await saveNewer;
    removeSpy.mockRestore();

    const claimed = await claimPendingCheckoutReturn();
    expect(claimed?.receipt).toBe(newer?.receipt);
    expect(claimed?.returnTo).toMatchObject({
      name: 'CourseDetails',
      params: {courseId: '71', openPurchase: true},
    });
  });
});
