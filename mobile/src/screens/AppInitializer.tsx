import React, {FC, useCallback, useEffect, useRef, useState} from 'react';
import {AppState, Linking, Platform} from 'react-native';
import {useDispatch, useSelector} from 'react-redux';
import Navigation from '../navigation/Navigation';
import {initializApp} from '../store/actions/settings';
import Splash from './Splash';
import {
  AsyncKeys,
  extractUserProfile,
  removeItem,
  rotateGuestStorageScope,
  saveItem,
} from '../constants/helpers';
import {LogOut, saveLoginData} from '../store/reducers/auth';
import {cleanupOldFiles} from '../utils/fileCache';
import {
  retryPendingPlaybackPositions,
  retryPendingProjectSubmissions,
  retryPendingSectionCompletions,
} from '../components/VideoPlayer/courseLearningApi';
import {
  flushPendingNotificationNavigation,
  prepareNotificationChannels,
  reconcilePushRegistration,
  subscribeToPushResponses,
  subscribeToPushTokenRefresh,
} from '../services/pushNotifications';
import {
  abandonPendingSecureSessionRestore,
  restoreSecureAuthState,
} from '../services/secureSession';
import {
  checkAppUpdatePolicy,
  dismissAppUpdate,
  shouldPresentAppUpdate,
} from '../services/appVersionCheck';
import type {AppUpdateNotice} from '../services/appVersionPolicy';
import AppUpdateGate from '../components/AppUpdateGate';
import type {AppDispatch, RootState} from '../store/store';
import {resumePendingSocialAuth} from '../services/socialAuth';
import {androidAuthSessionOwnsCallback} from '../services/androidAuthSession';
import {resumePendingGuestAccountMigration} from '../services/guestAccountMigration';
import {CAN_START_NATIVE_CHECKOUT} from '../constants/distribution';
import {reconcilePendingCoinCheckout} from '../services/coinCheckout';
import {
  runAuthenticatedStorageUpgrade,
  runDeviceStorageUpgrade,
} from '../services/storageUpgrade';
import {flushProductEvents} from '../services/productAnalytics';
import {flushPendingAccountWrites} from '../services/pendingAccountWrites';
import {flushOperationalTelemetry} from '../services/operationalTelemetry';

type DeadlineResult<T> =
  | {settled: true; value: T}
  | {settled: false; value?: undefined};

const monotonicNow = () => {
  const value = globalThis.performance?.now?.();
  return Number.isFinite(value) ? Number(value) : Date.now();
};

const settleWithin = <T,>(
  promise: Promise<T>,
  timeoutMs: number,
): Promise<DeadlineResult<T>> =>
  new Promise(resolve => {
    let settled = false;
    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      resolve({settled: false});
    }, timeoutMs);
    promise.then(
      value => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve({settled: true, value});
      },
      () => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve({settled: false});
      },
    );
  });

/**
 * Restores the tiny persisted settings slice before mounting navigation.
 * Course, wallet and profile data load in the screen that owns each journey.
 */
const AppInitializer: FC = () => {
  const dispatch = useDispatch<AppDispatch>();
  const appLoaded = useSelector((state: RootState) => state.settings.appLoaded);
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const [sessionReady, setSessionReady] = useState(false);
  const [updateNotice, setUpdateNotice] = useState<AppUpdateNotice | null>(
    null,
  );
  const lastUpdateCheckAtRef = useRef(Number.NEGATIVE_INFINITY);
  const updateCheckFlightRef = useRef<Promise<void> | null>(null);
  const mountedRef = useRef(true);

  const refreshUpdateNotice = useCallback((force = false) => {
    const now = monotonicNow();
    const elapsed = now - lastUpdateCheckAtRef.current;
    if (!force && elapsed < 15 * 60 * 1000) {
      return updateCheckFlightRef.current || Promise.resolve();
    }
    if (updateCheckFlightRef.current) return updateCheckFlightRef.current;
    lastUpdateCheckAtRef.current = now;
    const flight = (async () => {
      const result = await checkAppUpdatePolicy();
      if (!mountedRef.current || !result.authoritative) return;
      if (!result.notice) {
        setUpdateNotice(null);
        return;
      }
      setUpdateNotice(
        (await shouldPresentAppUpdate(result.notice)) ? result.notice : null,
      );
    })().finally(() => {
      if (updateCheckFlightRef.current === flight) {
        updateCheckFlightRef.current = null;
      }
    });
    updateCheckFlightRef.current = flight;
    return flight;
  }, []);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    // Keep durable course-chat history across process restarts. Only reclaim
    // bounded temporary attachment bytes here; private account data is
    // quiesced and removed by the explicit account-boundary paths.
    void cleanupOldFiles().catch(() => undefined);

    let active = true;
    void (async () => {
      try {
        const deviceUpgrade = runDeviceStorageUpgrade().catch(() => undefined);
        void deviceUpgrade;
        const adoptPendingSession = async (session: unknown) => {
          if (!active || !session) return;
          const profile = extractUserProfile(session);
          if (
            profile?.id === 'demo-student-1' ||
            String(profile?.email || '').endsWith('@example.com')
          ) {
            await Promise.allSettled([
              removeItem(AsyncKeys.IS_LOGIN),
              removeItem(AsyncKeys.USER_DATA),
            ]);
            if (active) dispatch(LogOut());
            return;
          }
          await settleWithin(saveItem(AsyncKeys.IS_LOGIN, true), 1_000);
          if (!active) return;
          dispatch(saveLoginData(session));
          void runAuthenticatedStorageUpgrade()
            .then(() => resumePendingGuestAccountMigration(false))
            .then(() => resumePendingGuestAccountMigration(true))
            .catch(() => undefined);
        };
        const initialUrlFlight = Linking.getInitialURL().catch(() => null);
        const initialUrlResult = await settleWithin(initialUrlFlight, 1_000);
        const initialUrl = initialUrlResult.settled
          ? initialUrlResult.value
          : null;
        if (!initialUrlResult.settled) {
          // Opening the guest shell has a deadline; ownership of an OAuth deep
          // link does not. Android does not emit a second `url` event for a
          // delayed cold-start result, so finish consuming this same promise.
          void initialUrlFlight
            .then(url => (url ? resumePendingSocialAuth(url) : null))
            .then(adoptPendingSession)
            .catch(() => undefined);
        }
        // A transient provider or network failure must not skip restoration of
        // an already valid session. The pending OAuth record remains durable
        // and is retried when the app becomes active again.
        const pendingResume = resumePendingSocialAuth(initialUrl).catch(
          () => null,
        );
        const quickResume = await settleWithin(pendingResume, 2_000);
        if (quickResume.settled) {
          await adoptPendingSession(quickResume.value);
        } else {
          // A cold OAuth callback may still be completing on a weak network.
          // Let the learner reach the app, then adopt only the session that
          // still owns the durable pending attempt when it finishes.
          void pendingResume.then(adoptPendingSession).catch(() => undefined);
        }
        const adoptRestoredState = async ({
          session,
          isAuthenticated,
        }: Awaited<ReturnType<typeof restoreSecureAuthState>>) => {
          if (!active) return;
          const profile = extractUserProfile(session);
          const isDiscardedDemoIdentity =
            profile?.id === 'demo-student-1' ||
            String(profile?.email || '').endsWith('@example.com');

          if (isDiscardedDemoIdentity) {
            await settleWithin(
              Promise.allSettled([
                removeItem(AsyncKeys.IS_LOGIN),
                removeItem(AsyncKeys.USER_DATA),
                rotateGuestStorageScope(),
              ]),
              2_000,
            );
            if (active) dispatch(LogOut());
            return;
          }
          if (isAuthenticated) {
            await settleWithin(saveItem(AsyncKeys.IS_LOGIN, true), 1_000);
            if (!active) return;
            dispatch(saveLoginData(session));
            // Copy-before-delete upgrades remain ordered, but they no longer
            // hold the launch screen after SecureStore proved the account.
            void runAuthenticatedStorageUpgrade()
              .then(() => resumePendingGuestAccountMigration(false))
              .then(() => resumePendingGuestAccountMigration(true))
              .catch(() => undefined);
            return;
          }
          await settleWithin(removeItem(AsyncKeys.IS_LOGIN), 1_000);
          if (active) dispatch(LogOut());
        };

        const restoreFlight = restoreSecureAuthState();
        const quickRestore = await settleWithin(restoreFlight, 3_500);
        if (quickRestore.settled) {
          await adoptRestoredState(quickRestore.value);
        } else {
          // A locked or slow keychain must not become an endless branded
          // splash. Continue as a guest without deleting either half of the
          // session, then adopt the same epoch-safe restore when it finishes.
          dispatch(LogOut());
          void restoreFlight.then(adoptRestoredState).catch(() => undefined);
        }
      } catch {
        // Do not trap the learner on the splash screen if the OS keychain is
        // temporarily unavailable. Migration keeps the old record untouched
        // and retries next launch; this runtime continues safely as a guest.
        dispatch(LogOut());
      } finally {
        if (active) setSessionReady(true);
      }
    })();
    return () => {
      active = false;
    };
  }, [dispatch]);

  useEffect(() => {
    if (!appLoaded) dispatch(initializApp());
  }, [appLoaded, dispatch]);

  useEffect(() => {
    if (!appLoaded) return;
    void prepareNotificationChannels().catch(() => undefined);
  }, [appLoaded]);

  useEffect(() => {
    // Cold-start notification responses must wait until NavigationContainer
    // exists; otherwise the OS opens an internal course URL in the browser.
    if (!appLoaded || !sessionReady) return undefined;
    const unsubscribeResponses = subscribeToPushResponses();
    const unsubscribeTokenRefresh = subscribeToPushTokenRefresh();
    return () => {
      unsubscribeResponses();
      unsubscribeTokenRefresh();
    };
  }, [appLoaded, sessionReady]);

  useEffect(() => {
    if (!appLoaded || !sessionReady) return undefined;
    void refreshUpdateNotice(true);
    return undefined;
  }, [appLoaded, refreshUpdateNotice, sessionReady]);

  useEffect(() => {
    // Token refresh/retry happens only for an authenticated, already opted-in
    // learner. This bootstrap path never opens the notification permission UI.
    void reconcilePushRegistration();
    void flushPendingAccountWrites().catch(() => undefined);
  }, [storedUser]);

  useEffect(() => {
    let active = true;
    let learningReconcileFlight: Promise<unknown> | null = null;
    let foregroundSessionFlight: Promise<void> | null = null;
    let storeReconcileFlight: Promise<void> | null = null;
    let storeReconcileTimer: ReturnType<typeof setTimeout> | null = null;
    let storeReconcileAttempt = 0;
    let lastLearningReconcileAt = 0;
    const reconcileLearning = (force = false) => {
      const now = Date.now();
      if (learningReconcileFlight) return;
      if (!force && now - lastLearningReconcileAt < 60_000) return;
      lastLearningReconcileAt = now;
      learningReconcileFlight = Promise.allSettled([
        retryPendingProjectSubmissions(),
        retryPendingPlaybackPositions().then(retryPendingSectionCompletions),
      ]).finally(() => {
        learningReconcileFlight = null;
      });
    };
    const clearStoreReconcileTimer = () => {
      if (storeReconcileTimer) clearTimeout(storeReconcileTimer);
      storeReconcileTimer = null;
    };
    const reconcileStorePurchases = () => {
      if (!storedUser || storeReconcileFlight) return;
      const external = reconcilePendingCoinCheckout();
      const native = CAN_START_NATIVE_CHECKOUT
        ? import('../services/nativeStoreBilling').then(store =>
            store.reconcileNativeStorePurchases(),
          )
        : Promise.resolve(null);
      storeReconcileFlight = Promise.allSettled([external, native])
        .then(results => {
          if (!active) return;
          const pending = results.some(
            result =>
              result.status === 'rejected' ||
              Boolean(result.status === 'fulfilled' && result.value?.pending),
          );
          clearStoreReconcileTimer();
          if (!pending || AppState.currentState !== 'active') {
            storeReconcileAttempt = 0;
            return;
          }
          // Payment providers can confirm after the browser has already
          // returned. Keep reconciling the durable receipt while the app is
          // visible instead of requiring another foreground transition.
          const retryDelays = [4_000, 10_000, 20_000, 40_000, 60_000];
          // A permanent account/storage/configuration failure is not a pending
          // payment. Bound this foreground burst; the next foreground event
          // starts another authoritative reconciliation without creating one
          // endless timer per signed-in learner.
          if (storeReconcileAttempt >= retryDelays.length) return;
          const delay = retryDelays[storeReconcileAttempt];
          storeReconcileAttempt += 1;
          storeReconcileTimer = setTimeout(() => {
            storeReconcileTimer = null;
            reconcileStorePurchases();
          }, delay);
        })
        .finally(() => {
          storeReconcileFlight = null;
        });
    };
    const adoptForegroundSession = async (session: unknown) => {
      const profile = extractUserProfile(session);
      if (
        profile?.id === 'demo-student-1' ||
        String(profile?.email || '').endsWith('@example.com')
      ) {
        await Promise.allSettled([
          removeItem(AsyncKeys.IS_LOGIN),
          removeItem(AsyncKeys.USER_DATA),
        ]);
        dispatch(LogOut());
        return;
      }
      await settleWithin(saveItem(AsyncKeys.IS_LOGIN, true), 1_000);
      dispatch(saveLoginData(session));
      void runAuthenticatedStorageUpgrade()
        .then(() => resumePendingGuestAccountMigration(false))
        .then(() => resumePendingGuestAccountMigration(true))
        .catch(() => undefined);
    };
    const restoreSessionAfterUnlock = () => {
      if (storedUser || foregroundSessionFlight) return;
      abandonPendingSecureSessionRestore();
      foregroundSessionFlight = restoreSecureAuthState()
        .then(result => {
          if (result.isAuthenticated)
            return adoptForegroundSession(result.session);
          return undefined;
        })
        .catch(() => undefined)
        .finally(() => {
          foregroundSessionFlight = null;
        });
    };
    const socialAuthLinkSubscription =
      Platform.OS === 'android'
        ? Linking.addEventListener('url', ({url}) => {
            // The live Custom Tab session owns its callback while it is open.
            // Once Android has returned without delivering the deep link, this
            // durable listener adopts a late callback instead of losing it.
            if (androidAuthSessionOwnsCallback(url)) return;
            void resumePendingSocialAuth(url)
              .then(session => {
                if (!session) return;
                return adoptForegroundSession(session);
              })
              .catch(() => undefined);
          })
        : null;
    reconcileLearning(true);
    reconcileStorePurchases();
    const subscription = AppState.addEventListener('change', state => {
      if (state === 'active') {
        reconcileLearning();
        reconcileStorePurchases();
        void reconcilePushRegistration();
        void flushProductEvents().catch(() => undefined);
        void flushOperationalTelemetry().catch(() => undefined);
        void refreshUpdateNotice();
        void flushPendingNotificationNavigation().catch(() => undefined);
        void flushPendingAccountWrites().catch(() => undefined);
        restoreSessionAfterUnlock();
        void resumePendingSocialAuth()
          .then(session => {
            if (!session) return;
            return adoptForegroundSession(session);
          })
          .catch(() => undefined);
      } else {
        clearStoreReconcileTimer();
      }
    });
    return () => {
      active = false;
      clearStoreReconcileTimer();
      socialAuthLinkSubscription?.remove();
      subscription.remove();
    };
  }, [dispatch, refreshUpdateNotice, storedUser]);

  const dismissUpdateNotice = () => {
    const notice = updateNotice;
    setUpdateNotice(null);
    if (notice) void dismissAppUpdate(notice);
  };

  return (
    <>
      {appLoaded && sessionReady ? <Navigation /> : <Splash />}
      <AppUpdateGate notice={updateNotice} onDismiss={dismissUpdateNotice} />
    </>
  );
};

export default AppInitializer;
