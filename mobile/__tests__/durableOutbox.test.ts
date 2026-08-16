import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  enqueueDurableOutbox,
  flushDurableOutbox,
  readDurableOutbox,
  resetDurableOutboxForTests,
} from '../src/services/durableOutbox';

const KEY = '@test/outbox:account-a';

describe('durable outbox', () => {
  beforeEach(async () => {
    await AsyncStorage.clear();
    resetDurableOutboxForTests();
    jest.restoreAllMocks();
  });

  it('deduplicates by id and acknowledges only delivered records', async () => {
    await Promise.all([
      enqueueDurableOutbox({storageKey: KEY, id: 'one', payload: {value: 1}}),
      enqueueDurableOutbox({storageKey: KEY, id: 'one', payload: {value: 2}}),
      enqueueDurableOutbox({storageKey: KEY, id: 'two', payload: {value: 3}}),
    ]);

    const deliver = jest.fn().mockResolvedValue('ack');
    await flushDurableOutbox({storageKey: KEY, deliver});

    expect(deliver).toHaveBeenCalledTimes(2);
    expect(await readDurableOutbox(KEY)).toEqual([]);
  });

  it('backs off after a transient failure and keeps newer enqueues', async () => {
    let now = Date.parse('2026-08-12T10:00:00.000Z');
    jest.spyOn(Date, 'now').mockImplementation(() => now);
    await enqueueDurableOutbox({storageKey: KEY, id: 'one', payload: 1});

    await flushDurableOutbox({
      storageKey: KEY,
      deliver: async () => 'retry',
      now: () => now,
      baseRetryMs: 1_000,
    });
    await enqueueDurableOutbox({storageKey: KEY, id: 'two', payload: 2});

    const queued = await readDurableOutbox<number>(KEY);
    expect(queued.map(item => item.id)).toEqual(['one', 'two']);
    expect(queued[0].attempts).toBe(1);
    expect(Date.parse(queued[0].nextAttemptAt)).toBe(now + 1_000);
  });

  it('drops expired and damaged records without blocking the queue', async () => {
    await AsyncStorage.setItem(
      KEY,
      JSON.stringify([
        {id: 'damaged'},
        {
          id: 'expired',
          payload: 1,
          createdAt: '2020-01-01T00:00:00.000Z',
          expiresAt: '2020-01-02T00:00:00.000Z',
          attempts: 0,
          nextAttemptAt: '2020-01-01T00:00:00.000Z',
        },
      ]),
    );

    expect(await readDurableOutbox(KEY)).toEqual([]);
  });
});
