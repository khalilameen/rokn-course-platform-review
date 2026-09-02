import AsyncStorage from '@react-native-async-storage/async-storage';
import {readJsonOrQuarantine} from '../src/services/recoverableJsonStorage';
import {decodePortfolioMediaOutboxEntries} from '../src/services/portfolioMediaOutbox';

const KEY = '@test/portfolio-upload-state';

describe('recoverable JSON storage', () => {
  beforeEach(async () => {
    await AsyncStorage.clear();
    jest.restoreAllMocks();
  });

  it('returns a valid durable value without rewriting it', async () => {
    await AsyncStorage.setItem(KEY, JSON.stringify([{id: 'one'}]));
    const remove = jest.spyOn(AsyncStorage, 'removeItem');

    await expect(
      readJsonOrQuarantine(
        KEY,
        () => [],
        value => (Array.isArray(value) ? value : null),
      ),
    ).resolves.toEqual([{id: 'one'}]);
    expect(remove).not.toHaveBeenCalled();
  });

  it.each(['{broken', '{}'])(
    'quarantines malformed or structurally invalid state before resetting it',
    async raw => {
      await AsyncStorage.setItem(KEY, raw);

      await expect(
        readJsonOrQuarantine(
          KEY,
          () => [],
          value => (Array.isArray(value) ? value : null),
        ),
      ).resolves.toEqual([]);
      expect(await AsyncStorage.getItem(`${KEY}:corrupt`)).toBe(raw);
      expect(await AsyncStorage.getItem(KEY)).toBeNull();
    },
  );

  it('keeps the source intact when the recovery copy cannot be written', async () => {
    const raw = '{broken';
    await AsyncStorage.setItem(KEY, raw);
    const originalSetItem = (AsyncStorage.setItem as jest.Mock).getMockImplementation();
    jest.spyOn(AsyncStorage, 'setItem').mockImplementation(
      async (key: string, value: string) => {
        if (key === `${KEY}:corrupt`) throw new Error('DEVICE_FULL');
        return originalSetItem?.(key, value);
      },
    );

    try {
      await expect(
        readJsonOrQuarantine(
          KEY,
          () => [],
          value => (Array.isArray(value) ? value : null),
        ),
      ).rejects.toThrow('DEVICE_FULL');
      expect(await AsyncStorage.getItem(KEY)).toBe(raw);
    } finally {
      (AsyncStorage.setItem as jest.Mock).mockImplementation(originalSetItem);
    }
  });

  it('quarantines the whole media queue when any entry is invalid', async () => {
    const valid = {
      projectId: '42',
      clientRequestId: '11111111-1111-4111-8111-111111111111',
      file: {uri: 'file:///draft.jpg'},
      createdAt: 1,
    };
    const raw = JSON.stringify([valid, {...valid, projectId: ''}]);
    await AsyncStorage.setItem(KEY, raw);

    expect(decodePortfolioMediaOutboxEntries([valid])).toEqual([valid]);
    await expect(
      readJsonOrQuarantine(
        KEY,
        () => [],
        decodePortfolioMediaOutboxEntries,
      ),
    ).resolves.toEqual([]);
    expect(await AsyncStorage.getItem(`${KEY}:corrupt`)).toBe(raw);
    expect(await AsyncStorage.getItem(KEY)).toBeNull();
  });
});
