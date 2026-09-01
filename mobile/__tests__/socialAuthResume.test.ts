const mockPost = jest.fn();
const mockLoad = jest.fn();
const mockSave = jest.fn();
const mockDelete = jest.fn();

jest.mock('react-native', () => ({Platform: {OS: 'android'}}));
jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));
jest.mock('expo-apple-authentication', () => ({
  AppleAuthenticationScope: {FULL_NAME: 0, EMAIL: 1},
  isAvailableAsync: jest.fn(),
  signInAsync: jest.fn(),
}));
jest.mock('expo-web-browser', () => ({
  maybeCompleteAuthSession: jest.fn(),
  openAuthSessionAsync: jest.fn(),
}));
jest.mock('../src/constants/api', () => ({
  mainUrl: 'https://rokn.app/api/v1/',
  publicRequest: {get: jest.fn(), post: (...args: unknown[]) => mockPost(...args)},
}));
jest.mock('../src/services/secureSession', () => ({
  loadPendingSocialAuthAttempt: (...args: unknown[]) => mockLoad(...args),
  savePendingSocialAuthAttempt: (...args: unknown[]) => mockSave(...args),
  deletePendingSocialAuthAttempt: (...args: unknown[]) => mockDelete(...args),
  saveSecureSession: jest.fn(async () => undefined),
}));
jest.mock('../src/services/androidAuthSession', () => ({
  openAndroidAuthSession: jest.fn(),
}));
jest.mock('../src/services/installationIdentity', () => ({
  getInstallationId: jest.fn(async () => null),
}));
jest.mock('../src/services/pendingWelcomeBonus', () => ({
  savePendingWelcomeBonus: jest.fn(async () => undefined),
}));

import {resumePendingSocialAuth} from '../src/services/socialAuth';

describe('social auth cold-start recovery', () => {
  const pending = {
    provider: 'google',
    verifier:
      '1111111111114111811111111111111111111111111141118111111111111111',
    startedAt: new Date().toISOString(),
  };

  beforeEach(() => {
    jest.clearAllMocks();
    mockLoad.mockResolvedValue(pending);
    mockSave.mockResolvedValue(undefined);
    mockDelete.mockResolvedValue(undefined);
  });

  it('completes the initial deep link with the durable PKCE verifier', async () => {
    mockPost.mockResolvedValue({
      data: {
        data: {
          api_token: 'session-token',
          user: {
            id: 7,
            name: 'Rokn Learner',
            email: 'learner@example.com',
            social_provider: 'google',
          },
        },
      },
    });

    await expect(
      resumePendingSocialAuth('rokn://auth?code=one-time-code'),
    ).resolves.toMatchObject({api_token: 'session-token'});
    expect(mockSave).toHaveBeenNthCalledWith(1, {
      ...pending,
      callbackUrl: 'rokn://auth?code=one-time-code',
    });
    expect(mockSave).toHaveBeenNthCalledWith(
      2,
      expect.objectContaining({
        ...pending,
        completedSession: expect.objectContaining({api_token: 'session-token'}),
      }),
    );
    expect(mockPost).toHaveBeenCalledWith(
      'social-auth/complete',
      {
        code: 'one-time-code',
        code_verifier: pending.verifier,
        device_os: 'android',
        device_type: 'android',
      },
      {timeout: 10_000},
    );
    expect(mockDelete).toHaveBeenCalledTimes(1);
  });

  it('keeps the callback for a later retry when the API is unavailable', async () => {
    const timeout = jest
      .spyOn(global, 'setTimeout')
      .mockImplementation(callback => {
        callback();
        return 0 as unknown as ReturnType<typeof setTimeout>;
      });
    mockPost.mockRejectedValue(new Error('network'));

    await expect(
      resumePendingSocialAuth('rokn://auth?code=retryable-code'),
    ).rejects.toThrow('network');
    expect(mockSave).toHaveBeenCalled();
    expect(mockDelete).not.toHaveBeenCalled();
    timeout.mockRestore();
  });
});
