import AsyncStorage from '@react-native-async-storage/async-storage';
import {clearAccountScopedStorage} from '../src/constants/helpers';

describe('account-scoped storage cleanup', () => {
  beforeEach(async () => {
    await AsyncStorage.clear();
  });

  it('removes every key owned by the logging-out account only', async () => {
    await AsyncStorage.multiSet([
      ['LANGUAGE', 'ar'],
      ['@rokn/course-player/v3:account-alpha', '{}'],
      ['@rokn/catalogue/v1:account-alpha:2', '[]'],
      ['@rokn/course-player/v3:account-beta', '{}'],
      ['@rokn/push-token-invalidation-pending/v1', 'true'],
    ]);

    const removed = await clearAccountScopedStorage('account-alpha');

    expect(removed.sort()).toEqual([
      '@rokn/catalogue/v1:account-alpha:2',
      '@rokn/course-player/v3:account-alpha',
    ]);
    expect(await AsyncStorage.getItem('LANGUAGE')).toBe('ar');
    expect(
      await AsyncStorage.getItem('@rokn/course-player/v3:account-beta'),
    ).toBe('{}');
    expect(
      await AsyncStorage.getItem('@rokn/push-token-invalidation-pending/v1'),
    ).toBe('true');
  });

  it('rejects an empty or unsafe scope instead of deleting broad data', async () => {
    await expect(clearAccountScopedStorage('')).rejects.toThrow(
      'INVALID_ACCOUNT_STORAGE_SCOPE',
    );
    await expect(clearAccountScopedStorage('../')).rejects.toThrow(
      'INVALID_ACCOUNT_STORAGE_SCOPE',
    );
  });
});
