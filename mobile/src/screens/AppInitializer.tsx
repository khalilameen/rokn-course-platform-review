import React, {FC, useEffect, useState} from 'react';
import {AppState} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {useDispatch, useSelector} from 'react-redux';
import Navigation from '../navigation/Navigation';
import {initializApp} from '../store/actions/settings';
import Splash from './Splash';
import {
  AsyncKeys,
  extractUserProfile,
  removeItem,
  saveItem,
} from '../constants/helpers';
import {LogOut, saveLoginData} from '../store/reducers/auth';
import {clearTransientChatCache} from '../utils/fileCache';
import {
  retryPendingPlaybackPositions,
  retryPendingProjectSubmissions,
  retryPendingSectionCompletions,
} from '../components/VideoPlayer/courseLearningApi';
import {
  reconcilePushRegistration,
  subscribeToPushResponses,
} from '../services/pushNotifications';
import {restoreSecureAuthState} from '../services/secureSession';
import {checkForAppUpdate} from '../services/appVersionCheck';
import type {AppUpdateNotice} from '../services/appVersionPolicy';
import AppUpdateGate from '../components/AppUpdateGate';
import type {AppDispatch, RootState} from '../store/store';

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

  useEffect(() => {
    // Older test builds persisted Rokn AI conversations. Server-backed chat is
    // session-only, so clear those legacy records without retaining history.
    void AsyncStorage.getAllKeys()
      .then(keys =>
        keys.filter(
          key => key === 'persist:chat' || key.includes('@rokn/course-chat/'),
        ),
      )
      .then(keys => (keys.length ? AsyncStorage.multiRemove(keys) : undefined))
      .catch(() => undefined);
    void clearTransientChatCache();

    let active = true;
    void (async () => {
      try {
        const {session, isAuthenticated} = await restoreSecureAuthState();
        const profile = extractUserProfile(session);
        const isDiscardedDemoIdentity =
          profile?.id === 'demo-student-1' ||
          String(profile?.email || '').endsWith('@example.com');

        if (isDiscardedDemoIdentity) {
          await Promise.all([
            removeItem(AsyncKeys.IS_LOGIN),
            removeItem(AsyncKeys.USER_DATA),
          ]);
          dispatch(LogOut());
        } else if (isAuthenticated) {
          await saveItem(AsyncKeys.IS_LOGIN, true);
          dispatch(saveLoginData(session));
        } else {
          await removeItem(AsyncKeys.IS_LOGIN);
          dispatch(LogOut());
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
    // Cold-start notification responses must wait until NavigationContainer
    // exists; otherwise the OS opens an internal course URL in the browser.
    if (!appLoaded || !sessionReady) return undefined;
    return subscribeToPushResponses();
  }, [appLoaded, sessionReady]);

  useEffect(() => {
    if (!appLoaded || !sessionReady) return undefined;
    let active = true;
    void checkForAppUpdate().then(notice => {
      if (active && notice) setUpdateNotice(notice);
    });
    return () => {
      active = false;
    };
  }, [appLoaded, sessionReady]);

  useEffect(() => {
    // Token refresh/retry happens only for an authenticated, already opted-in
    // learner. This bootstrap path never opens the notification permission UI.
    void reconcilePushRegistration();
  }, [storedUser]);

  useEffect(() => {
    const reconcileLearning = () => {
      void Promise.allSettled([
        retryPendingProjectSubmissions(),
        retryPendingPlaybackPositions().then(retryPendingSectionCompletions),
      ]);
    };
    reconcileLearning();
    const subscription = AppState.addEventListener('change', state => {
      if (state === 'active') {
        reconcileLearning();
        void reconcilePushRegistration();
      }
    });
    return () => subscription.remove();
  }, []);

  return (
    <>
      {appLoaded && sessionReady ? <Navigation /> : <Splash />}
      <AppUpdateGate
        notice={updateNotice}
        onDismiss={() => setUpdateNotice(null)}
      />
    </>
  );
};

export default AppInitializer;
