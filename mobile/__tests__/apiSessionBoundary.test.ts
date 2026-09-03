import {AxiosHeaders} from 'axios';

const mockGetItem = jest.fn();
const mockPeekSession = jest.fn();

jest.mock('react-native', () => ({Platform: {OS: 'android'}}));
jest.mock('../src/constants/helpers', () => ({
  AsyncKeys: {LANGUAGE: 'LANGUAGE', USER_DATA: 'USER_DATA', IS_LOGIN: 'IS_LOGIN'},
  extractApiToken: (value: unknown) =>
    typeof value === 'object' && value !== null && 'api_token' in value
      ? String((value as {api_token: unknown}).api_token)
      : null,
  getItem: (...args: unknown[]) => mockGetItem(...args),
  removeItem: jest.fn(),
  rotateGuestStorageScope: jest.fn(),
}));
jest.mock('../src/services/secureSession', () => ({
  peekSecureSession: (...args: unknown[]) => mockPeekSession(...args),
}));
jest.mock('../src/navigation/RootNavigationHelper', () => ({
  getLoginReturnToSnapshot: jest.fn(),
  navigate: jest.fn(),
}));
jest.mock('../src/navigation/authReturn', () => ({
  savePendingLoginReturnTo: jest.fn(),
}));
jest.mock('../src/store/store', () => ({store: {dispatch: jest.fn()}}));
jest.mock('../src/store/reducers/auth', () => ({LogOut: jest.fn()}));
jest.mock('../src/services/smartReminders', () => ({
  cancelLearningReminders: jest.fn(),
  setSmartRemindersEnabled: jest.fn(),
}));
jest.mock('../src/services/pushDeviceState', () => ({
  invalidateLocalPushDeviceRegistration: jest.fn(),
}));
jest.mock('../src/utils/serverClock', () => ({observeServerTime: jest.fn()}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));
jest.mock('../src/services/installationIdentity', () => ({
  getInstallationId: jest.fn(async () => null),
}));

import {
  onFulfilledRequest,
  onRejectedResponse,
  responseConfig,
} from '../src/constants/api';

describe('public request session boundary', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGetItem.mockImplementation(async (key: string) =>
      key === 'LANGUAGE' ? 'ar' : null,
    );
    mockPeekSession.mockReturnValue({ready: false, session: null, epoch: 1});
  });

  it('does not wait for secure session storage on a public request', async () => {
    const config = {
      method: 'get',
      url: 'auth-methods',
      headers: new AxiosHeaders(),
      skipAuthorization: true,
    } as Parameters<typeof responseConfig>[0];

    await responseConfig(config);

    expect(mockGetItem).toHaveBeenCalledWith('LANGUAGE');
    expect(mockGetItem).not.toHaveBeenCalledWith('USER_DATA');
    expect(config.headers.has('Authorization')).toBe(false);
  });

  it('gives ordinary reads one bounded logical deadline across retries', async () => {
    const now = jest.spyOn(Date, 'now').mockReturnValue(1_000_000);
    const config = {
      method: 'get',
      url: 'wallet',
      headers: new AxiosHeaders(),
    } as Parameters<typeof responseConfig>[0];

    await responseConfig(config);

    expect(
      (config as typeof config & {roknNetworkRetryDeadlineAt: number})
        .roknNetworkRetryDeadlineAt,
    ).toBe(1_020_000);
    expect(config.timeout).toBe(20_000);
    now.mockRestore();
  });

  it('preserves a shorter screen-owned read deadline', async () => {
    const now = jest.spyOn(Date, 'now').mockReturnValue(1_000_000);
    const config = {
      method: 'get',
      url: 'courses/list',
      headers: new AxiosHeaders(),
      timeout: 15_000,
      roknNetworkRetryDeadlineAt: 1_002_500,
    } as Parameters<typeof responseConfig>[0];

    await responseConfig(config);

    expect(
      (config as typeof config & {roknNetworkRetryDeadlineAt: number})
        .roknNetworkRetryDeadlineAt,
    ).toBe(1_002_500);
    expect(config.timeout).toBe(2_500);
    now.mockRestore();
  });

  it('uses only the ready memory snapshot for optional catalogue auth', async () => {
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'memory-token'},
    });
    const config = {
      method: 'get',
      url: 'courses/list',
      headers: new AxiosHeaders(),
      optionalAuthorization: true,
    } as Parameters<typeof responseConfig>[0];

    await responseConfig(config);

    expect(mockGetItem).not.toHaveBeenCalledWith('USER_DATA');
    expect(config.headers.get('Authorization')).toBe('Bearer memory-token');
  });

  it('does not read or clear a session for a bearer-less public 401', async () => {
    const response = {status: 401, data: {code: 'gateway_unauthorized'}};

    await expect(
      onRejectedResponse({
        response,
        config: {method: 'get', headers: new AxiosHeaders()},
      }),
    ).rejects.toBe(response);
    expect(mockGetItem).not.toHaveBeenCalledWith('USER_DATA');
  });

  it.each([
    ['guest to user', {ready: false, session: null, epoch: 10}, {ready: true, session: {api_token: 'user-one'}, epoch: 11}],
    ['user to user', {ready: true, session: {api_token: 'user-one'}, epoch: 20}, {ready: true, session: {api_token: 'user-two'}, epoch: 21}],
  ])('rejects a %s response captured before the session epoch changed', async (_label, before, after) => {
    mockPeekSession.mockReturnValue(before);
    const config = {
      method: 'get',
      url: 'courses/list',
      headers: new AxiosHeaders(),
      optionalAuthorization: true,
    } as Parameters<typeof responseConfig>[0];
    await responseConfig(config);

    mockPeekSession.mockReturnValue(after);
    await expect(
      onFulfilledRequest({
        config,
        data: {},
        headers: {},
      } as never),
    ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
  });

  it('keeps a public guest response when slow storage settles to the same guest', async () => {
    mockPeekSession.mockReturnValue({ready: false, session: null, epoch: 10});
    const config = {
      method: 'get',
      url: 'auth-methods',
      headers: new AxiosHeaders(),
      skipAuthorization: true,
    } as Parameters<typeof responseConfig>[0];
    await responseConfig(config);

    mockPeekSession.mockReturnValue({ready: true, session: null, epoch: 11});
    await expect(
      onFulfilledRequest({config, data: {}, headers: {}} as never),
    ).resolves.toBeDefined();
  });

  it('does not resend a read-only network retry after the account epoch changes', async () => {
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'user-one'},
      epoch: 30,
    });
    mockGetItem.mockImplementation(async (key: string) =>
      key === 'LANGUAGE' ? 'ar' : {api_token: 'user-one'},
    );
    const config = {
      method: 'get',
      url: 'profile',
      headers: new AxiosHeaders(),
    } as Parameters<typeof responseConfig>[0];
    await responseConfig(config);
    expect(config.headers.get('Authorization')).toBe('Bearer user-one');

    (config as typeof config & {roknNetworkRetryCount: number})
      .roknNetworkRetryCount = 1;
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'user-two'},
      epoch: 31,
    });
    mockGetItem.mockImplementation(async (key: string) =>
      key === 'LANGUAGE' ? 'ar' : {api_token: 'user-two'},
    );

    await expect(responseConfig(config)).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(config.headers.get('Authorization')).toBe('Bearer user-one');
  });

  it('keeps a same-owner retry on its captured bearer without rereading auth', async () => {
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'user-one'},
      epoch: 40,
    });
    mockGetItem.mockImplementation(async (key: string) =>
      key === 'LANGUAGE' ? 'ar' : {api_token: 'user-one'},
    );
    const config = {
      method: 'get',
      url: 'profile',
      headers: new AxiosHeaders(),
    } as Parameters<typeof responseConfig>[0];
    await responseConfig(config);
    mockGetItem.mockClear();

    (config as typeof config & {roknNetworkRetryCount: number})
      .roknNetworkRetryCount = 1;
    await responseConfig(config);

    expect(config.headers.get('Authorization')).toBe('Bearer user-one');
    expect(mockGetItem).toHaveBeenCalledWith('LANGUAGE');
    // The ownership assertion reads the captured owner's durable token. The
    // retry must not perform a second auth lookup that could replace it.
    expect(
      mockGetItem.mock.calls.filter(([key]) => key === 'USER_DATA'),
    ).toHaveLength(1);
  });
});
