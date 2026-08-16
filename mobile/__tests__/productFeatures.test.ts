import AsyncStorage from '@react-native-async-storage/async-storage';

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn()},
}));
import {publicRequest} from '../src/constants/api';

import {
  isProductFeatureEnabled,
  productFeatureSnapshotStorageKey,
  refreshProductFeatures,
  resetProductFeaturesForTests,
} from '../src/services/productFeatures';

const mockGet = publicRequest.get as jest.Mock;

describe('product feature control plane', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    resetProductFeaturesForTests();
  });

  it('uses one validated remote snapshot for product capabilities', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          version: 'release-7',
          expires_at: new Date(Date.now() + 60_000).toISOString(),
          flags: {
            checkout: false,
            playback: true,
            project_uploads: false,
            ai_chat: true,
          },
        },
      },
    });

    await expect(refreshProductFeatures()).resolves.toMatchObject({
      version: 'release-7',
    });
    await expect(isProductFeatureEnabled('checkout')).resolves.toBe(false);
    await expect(isProductFeatureEnabled('playback')).resolves.toBe(true);
    expect(mockGet).toHaveBeenCalledTimes(1);
    expect(await AsyncStorage.getItem(productFeatureSnapshotStorageKey)).toContain(
      'release-7',
    );
  });

  it('rejects malformed control-plane payloads', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          version: 'bad',
          expires_at: new Date(Date.now() + 60_000).toISOString(),
          flags: {checkout: 'yes'},
        },
      },
    });

    await expect(refreshProductFeatures()).resolves.toBeNull();
    expect(await AsyncStorage.getItem(productFeatureSnapshotStorageKey)).toBeNull();
  });

  it('uses the active build profile fallback if the endpoint is unavailable', async () => {
    mockGet.mockRejectedValue(new Error('offline'));
    const requiresRemoteFlags =
      process.env.EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS === '1' ||
      process.env.EXPO_PUBLIC_BUILD_PROFILE === 'production';

    await expect(isProductFeatureEnabled('project_uploads')).resolves.toBe(
      !requiresRemoteFlags,
    );
    await expect(isProductFeatureEnabled('ai_chat')).resolves.toBe(
      !requiresRemoteFlags,
    );
  });
});

describe('product feature outage defaults', () => {
  const originalProfile = process.env.EXPO_PUBLIC_BUILD_PROFILE;
  const originalRemoteFlag = process.env.EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS;

  const loadFallback = async (profile: string, requireRemoteFlags?: string) => {
    process.env.EXPO_PUBLIC_BUILD_PROFILE = profile;
    if (requireRemoteFlags === undefined) {
      delete process.env.EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS;
    } else {
      process.env.EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS = requireRemoteFlags;
    }
    jest.resetModules();
    const isolatedApi = require('../src/constants/api').publicRequest as {
      get: jest.Mock;
    };
    isolatedApi.get.mockRejectedValue(new Error('offline'));
    const isolatedFeatures = require('../src/services/productFeatures') as {
      isProductFeatureEnabled: (
        feature: 'project_uploads' | 'ai_chat',
      ) => Promise<boolean>;
    };
    return Promise.all([
      isolatedFeatures.isProductFeatureEnabled('project_uploads'),
      isolatedFeatures.isProductFeatureEnabled('ai_chat'),
    ]);
  };

  afterAll(() => {
    if (originalProfile === undefined) {
      delete process.env.EXPO_PUBLIC_BUILD_PROFILE;
    } else {
      process.env.EXPO_PUBLIC_BUILD_PROFILE = originalProfile;
    }
    if (originalRemoteFlag === undefined) {
      delete process.env.EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS;
    } else {
      process.env.EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS = originalRemoteFlag;
    }
    jest.resetModules();
  });

  it('keeps test builds usable and production mutations fail closed', async () => {
    await expect(loadFallback('test')).resolves.toEqual([true, true]);
    await expect(loadFallback('production', '1')).resolves.toEqual([
      false,
      false,
    ]);
  });
});
