import AsyncStorage from '@react-native-async-storage/async-storage';

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(async (key: string) => `${key}:user-a`),
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
        openPurchase: true,
        resumeAfterPreview: false,
        resumeReelId: undefined,
      },
    });
    expect(await acknowledgePendingCheckoutReturn(claimed!)).toBe(true);
    expect(await claimPendingCheckoutReturn()).toBeUndefined();
    expect(saved?.receipt).toBe(claimed?.receipt);
  });
});
