import {AppState, Linking} from 'react-native';

export type AndroidAuthSessionResult =
  | {type: 'success'; url: string}
  | {type: 'cancel'};

const AUTH_SESSION_TIMEOUT_MS = 10 * 60 * 1000;

export const openAndroidAuthSession = (
  startUrl: string,
  returnUrl: string,
): Promise<AndroidAuthSessionResult> =>
  new Promise((resolve, reject) => {
    let settled = false;
    let leftApp = false;
    let cancelTimer: ReturnType<typeof setTimeout> | undefined;

    const redirectSubscription = Linking.addEventListener('url', event => {
      if (event.url.startsWith(returnUrl)) {
        finish({type: 'success', url: event.url});
      }
    });
    const appStateSubscription = AppState.addEventListener('change', state => {
      if (state === 'inactive' || state === 'background') {
        leftApp = true;
        return;
      }
      if (state === 'active' && leftApp && !settled) {
        // Android can emit active immediately before the deep-link event.
        cancelTimer = setTimeout(() => finish({type: 'cancel'}), 750);
      }
    });
    const timeoutTimer = setTimeout(fail, AUTH_SESSION_TIMEOUT_MS);

    function cleanup() {
      clearTimeout(timeoutTimer);
      if (cancelTimer) clearTimeout(cancelTimer);
      redirectSubscription.remove();
      appStateSubscription.remove();
    }

    function finish(result: AndroidAuthSessionResult) {
      if (settled) return;
      settled = true;
      cleanup();
      resolve(result);
    }

    function fail() {
      if (settled) return;
      settled = true;
      cleanup();
      reject(new Error('LOGIN_BROWSER_UNAVAILABLE'));
    }

    void Linking.openURL(startUrl).catch(fail);
  });
