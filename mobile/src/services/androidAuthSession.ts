import {AppState, Linking} from 'react-native';

export type AndroidAuthSessionResult =
  | {type: 'success'; url: string}
  | {type: 'cancel'};

const AUTH_SESSION_TIMEOUT_MS = 10 * 60 * 1000;
const DEEP_LINK_DELIVERY_GRACE_MS = 8000;

export const openAndroidAuthSession = (
  startUrl: string,
  returnUrl: string,
): Promise<AndroidAuthSessionResult> =>
  new Promise((resolve, reject) => {
    let settled = false;
    let leftApp = false;
    let cancelTimer: ReturnType<typeof setTimeout> | undefined;

    const isExpectedCallback = (url: string) =>
      url === returnUrl || url.startsWith(`${returnUrl}?`);

    const redirectSubscription = Linking.addEventListener('url', event => {
      if (isExpectedCallback(event.url)) {
        finish({type: 'success', url: event.url});
      }
    });
    const appStateSubscription = AppState.addEventListener('change', state => {
      if (state === 'inactive' || state === 'background') {
        leftApp = true;
        return;
      }
      if (state === 'active' && leftApp && !settled) {
        // Some Android builds resume the activity well before dispatching the
        // OAuth deep link. A short grace period produced false cancellations
        // on real devices even though the provider callback had succeeded.
        if (cancelTimer) clearTimeout(cancelTimer);
        cancelTimer = setTimeout(
          () => finish({type: 'cancel'}),
          DEEP_LINK_DELIVERY_GRACE_MS,
        );
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
