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
});
