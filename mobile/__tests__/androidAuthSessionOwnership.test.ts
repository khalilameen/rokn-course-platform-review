const mockLinkListeners = new Set<(event: {url: string}) => void>();
const mockAppStateListeners = new Set<(state: string) => void>();

jest.mock('react-native', () => ({
  Linking: {
    addEventListener: (
      _event: string,
      listener: (event: {url: string}) => void,
    ) => {
      mockLinkListeners.add(listener);
      return {remove: () => mockLinkListeners.delete(listener)};
    },
    openURL: jest.fn(async () => undefined),
  },
  AppState: {
    addEventListener: (_event: string, listener: (state: string) => void) => {
      mockAppStateListeners.add(listener);
      return {remove: () => mockAppStateListeners.delete(listener)};
    },
  },
}));

import {
  androidAuthSessionOwnsCallback,
  openAndroidAuthSession,
} from '../src/services/androidAuthSession';

describe('Android OAuth callback ownership', () => {
  afterEach(() => {
    mockLinkListeners.clear();
    mockAppStateListeners.clear();
  });

  it('does not let a stale callback consume the active attempt', async () => {
    const currentAttempt = 'current-pkce-challenge';
    const session = openAndroidAuthSession(
      'https://identity.rokn.app/start',
      'rokn://auth',
      currentAttempt,
    );
    const oldCallback = 'rokn://auth?attempt=old-pkce-challenge&code=old';
    const currentCallback = `rokn://auth?attempt=${currentAttempt}&code=current`;

    expect(androidAuthSessionOwnsCallback(oldCallback)).toBe(false);
    expect(androidAuthSessionOwnsCallback(currentCallback)).toBe(true);

    for (const listener of mockLinkListeners) listener({url: oldCallback});
    expect(mockLinkListeners.size).toBe(1);

    for (const listener of [...mockLinkListeners]) {
      listener({url: currentCallback});
    }
    await expect(session).resolves.toEqual({
      type: 'success',
      url: currentCallback,
    });

    expect(androidAuthSessionOwnsCallback(oldCallback)).toBe(false);
    expect(androidAuthSessionOwnsCallback(currentCallback)).toBe(true);
  });
});
