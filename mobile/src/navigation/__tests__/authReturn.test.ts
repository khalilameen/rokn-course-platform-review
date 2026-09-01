import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  acknowledgePendingLoginReturnTo,
  claimPendingLoginReturnTo,
  safeLoginReturnToFromRoute,
  savePendingLoginReturnTo,
} from '../authReturn';

beforeEach(async () => {
  await AsyncStorage.clear();
});

describe('safeLoginReturnToFromRoute', () => {
  it('preserves the exact course position without copying arbitrary params', () => {
    expect(
      safeLoginReturnToFromRoute({
        name: 'Reels',
        params: {
          courseId: ' 42 ',
          reelId: ' 9 ',
          preview: false,
          secret: 'must-not-leak',
        },
      }),
    ).toEqual({
      name: 'Reels',
      params: {
        courseId: '42',
        reelId: '9',
        lessonId: undefined,
        preview: false,
        previewCount: undefined,
      },
    });
  });

  it('preserves course detail intent without forcing purchase', () => {
    expect(
      safeLoginReturnToFromRoute({
        name: 'CourseDetails',
        params: {courseId: '7', resumeReelId: '21'},
      }),
    ).toEqual({
      name: 'CourseDetails',
      params: {
        courseId: '7',
        openCodeRedemption: false,
        openPurchase: false,
        resumeAfterPreview: false,
        resumeReelId: '21',
      },
    });
  });

  it('preserves only the explicit course-code redemption intent', () => {
    expect(
      safeLoginReturnToFromRoute({
        name: 'CourseDetails',
        params: {
          courseId: '7',
          openCodeRedemption: true,
          purchaseUrl: 'https://example.test/checkout',
        },
      }),
    ).toEqual({
      name: 'CourseDetails',
      params: {
        courseId: '7',
        openCodeRedemption: true,
        openPurchase: false,
        resumeAfterPreview: false,
        resumeReelId: undefined,
      },
    });
  });

  it('rejects unsupported or incomplete routes', () => {
    expect(
      safeLoginReturnToFromRoute({
        name: 'NotAllowed',
        params: {redirect: 'Wallet'},
      }),
    ).toBeUndefined();
    expect(
      safeLoginReturnToFromRoute({name: 'Reels', params: {courseId: ' '}}),
    ).toBeUndefined();
  });

  it.each(['Wallet', 'MyCorner', 'Profile', 'Settings'])(
    'keeps the safe parameterless destination %s and drops arbitrary params',
    name => {
      expect(
        safeLoginReturnToFromRoute({
          name,
          params: {secret: 'must-not-leak'},
        }),
      ).toEqual({name});
    },
  );
});

describe('durable login return hand-off', () => {
  it('keeps the route until navigation acknowledges the exact receipt', async () => {
    await savePendingLoginReturnTo({
      name: 'CourseDetails',
      params: {courseId: '42', openPurchase: true},
    });
    const claim = await claimPendingLoginReturnTo();
    expect(claim?.returnTo).toMatchObject({
      name: 'CourseDetails',
      params: {courseId: '42', openPurchase: true},
    });
    expect((await claimPendingLoginReturnTo())?.receipt).toBe(claim?.receipt);

    await savePendingLoginReturnTo({name: 'Wallet'});
    expect(
      await acknowledgePendingLoginReturnTo(claim?.receipt || ''),
    ).toBe(false);
    expect((await claimPendingLoginReturnTo())?.returnTo).toEqual({
      name: 'Wallet',
    });
  });
});
