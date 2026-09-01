import AsyncStorage from '@react-native-async-storage/async-storage';

const mockGet = jest.fn();

jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
  digestStringAsync: jest.fn(async () => 'a'.repeat(64)),
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));

import {
  getPublishedCoursesPage,
  hasSession,
} from '../src/services/api/courses';
import {resetSecureSessionMigrationForTests} from '../src/services/secureSession';

describe('guest catalogue session boundary', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    resetSecureSessionMigrationForTests();
    await AsyncStorage.clear();
  });

  it('loads the public catalogue without starting secure-session hydration', async () => {
    mockGet.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: {
          courses: [],
          catalogue_revision: 1,
          pagination: {current_page: 1, last_page: 1, total: 0},
        },
      },
    });

    await expect(hasSession()).resolves.toBe(false);
    await expect(getPublishedCoursesPage()).resolves.toMatchObject({
      courses: [],
      page: 1,
      hasMore: false,
      fromCache: false,
    });
    expect(mockGet).toHaveBeenCalledWith(
      'courses/list',
      expect.objectContaining({optionalAuthorization: true}),
    );
  });
});
