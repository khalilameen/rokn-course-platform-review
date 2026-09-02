import {
  CommonActions,
  NavigationContainer,
  getStateFromPath as getNavigationStateFromPath,
} from '@react-navigation/native';
import type {LinkingOptions} from '@react-navigation/native';
import {createNativeStackNavigator} from '@react-navigation/native-stack';
import {I18nManager, Linking} from 'react-native';

import React from 'react';
import {useSelector} from 'react-redux';
import type {RootState} from '../store/store';

import {navigationRef, openRoknDestination} from './RootNavigationHelper';

import Reels from '../screens/Reels';
import Home from '../screens/Home';
import Login from '../screens/Login';
import CourseDetails from '../screens/CourseDetails';
import MyCorner from '../screens/MyCorner';
import Wallet from '../screens/Wallet';
import Profile from '../screens/Profile';
import Settings from '../screens/Settings';
import LanguageSelect from '../screens/LanguageSelect';
import AboutUs from '../screens/Informations/AboutUs';
import PrivacyPolicy from '../screens/Informations/PrivacyPolicy';
import TermsOfUse from '../screens/Informations/TermsOfUse';
import ThirdPartyNotices from '../screens/Informations/ThirdPartyNotices';
import Notifications from '../screens/Notifications';
import EditAccount from '../screens/EditAccount';
import Feedback from '../screens/Feedback';
import DeviceSessions from '../screens/DeviceSessions';
import {
  parseRoknDestination,
  resolveInitialUrlWithinDeadline,
  roknDestinationKey,
  type RoknDestination,
} from './deepLinks';
import type {RootStackParamList} from './types';
import {
  flushPendingNotificationNavigation,
  setNotificationNavigationReady,
} from '../services/pushNotifications';
import {
  acknowledgePendingLoginReturnTo,
  claimPendingLoginReturnTo,
  clearPendingLoginReturnTo,
} from './authReturn';
import {
  acknowledgePendingCheckoutReturn,
  claimPendingCheckoutReturn,
} from './checkoutReturn';
import {useReducedMotion} from '../hooks/useReducedMotion';
import {extractUserProfile} from '../services/secureSession';
// import WalletWithdrawalRequest from '../screens/WalletWithdrawalRequest';
const Stack = createNativeStackNavigator<RootStackParamList>();

let lastDeliveredDestination = '';
let lastDeliveredAt = 0;
let lateInitialDestination: RoknDestination | null = null;
let restoredNavigationReady = false;
const WARM_LINK_DEDUPE_MS = 1_500;

const rememberDeliveredDestination = (destination: RoknDestination) => {
  lastDeliveredDestination = roknDestinationKey(destination);
  lastDeliveredAt = Date.now();
};

const deliverLateInitialUrl = (url: string | null) => {
  const destination = parseRoknDestination(url);
  if (!destination) return;
  rememberDeliveredDestination(destination);
  if (!restoredNavigationReady || !openRoknDestination(destination)) {
    lateInitialDestination = destination;
  }
};

const flushLateInitialDestination = () => {
  if (!restoredNavigationReady || !lateInitialDestination) return false;
  const destination = lateInitialDestination;
  if (!openRoknDestination(destination)) return false;
  lateInitialDestination = null;
  return true;
};

const navigationDeadline = <T,>(
  promise: Promise<T>,
  fallback: T,
  timeoutMs = 1_500,
) =>
  new Promise<T>(resolve => {
    let settled = false;
    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      resolve(fallback);
    }, timeoutMs);
    promise.then(
      value => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve(value);
      },
      () => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve(fallback);
      },
    );
  });

const linking: LinkingOptions<RootStackParamList> = {
  prefixes: [
    'rokn://',
    'https://rokn.app',
    'https://www.rokn.app',
    'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud',
  ],
  async getInitialURL() {
    const url = await resolveInitialUrlWithinDeadline(
      Linking.getInitialURL(),
      deliverLateInitialUrl,
    );
    const destination = parseRoknDestination(url);
    if (destination) rememberDeliveredDestination(destination);
    return url;
  },
  subscribe(listener) {
    const subscription = Linking.addEventListener('url', ({url}) => {
      const destination = parseRoknDestination(url);
      if (!destination) return;
      const destinationKey = roknDestinationKey(destination);
      const now = Date.now();
      // Once navigation is ready, its destination-aware guard decides whether
      // this is the duplicate native delivery of the route already on screen.
      // A time-only guard here used to swallow a legitimate second tap after
      // the learner had already left that destination.
      if (openRoknDestination(destination)) {
        lastDeliveredDestination = destinationKey;
        lastDeliveredAt = now;
        return;
      }
      if (
        destinationKey === lastDeliveredDestination &&
        now - lastDeliveredAt >= 0 &&
        now - lastDeliveredAt < WARM_LINK_DEDUPE_MS
      ) {
        return;
      }
      lastDeliveredDestination = destinationKey;
      lastDeliveredAt = now;
      listener(url);
    });
    return () => subscription.remove();
  },
  // Custom-scheme URLs accept the notification route grammar. OAuth callbacks
  // and encoded path fragments are rejected as screen parameters.
  filter: url => parseRoknDestination(url) !== null,
  getStateFromPath(path, options) {
    const destination = parseRoknDestination(path);
    if (!destination) return undefined;
    const normalizedPath =
      destination.name === 'Home'
        ? 'home'
        : destination.name === 'Profile'
        ? 'profile'
        : destination.name === 'Wallet'
        ? 'wallet'
        : destination.name === 'Feedback'
        ? `support/${destination.params.caseId}`
        : destination.name === 'CourseDetails'
        ? `course/${destination.params.courseId}`
        : destination.params.lessonId
        ? `course/${destination.params.courseId}/watch?lessonId=${destination.params.lessonId}`
        : `course/${destination.params.courseId}/watch${
            destination.params.reelId ? `/${destination.params.reelId}` : ''
          }`;
    const state = getNavigationStateFromPath(normalizedPath, options);
    if (!state || destination.name === 'Home' || !state.routes?.length) {
      return state;
    }
    // A cold external link still belongs to the app journey. Put Home under
    // the target so Android back and the in-screen back button agree instead
    // of abruptly closing the app after the first tap.
    return {
      ...state,
      index: state.routes.length,
      routes: [{name: 'Home' as const}, ...state.routes],
    };
  },
  config: {
    screens: {
      Home: 'home',
      CourseDetails: 'course/:courseId',
      Reels: 'course/:courseId/watch/:reelId?',
      Profile: 'profile',
      Wallet: 'wallet',
      Feedback: 'support/:caseId',
    },
  },
};

const Stacks = () => {
  const reducedMotion = useReducedMotion();
  const language = useSelector((state: RootState) => state.settings.language);
  const languageCode =
    (typeof language === 'string' ? language : language?.code) || 'ar';
  const needsArabicBootstrap = languageCode === 'en' && !I18nManager.isRTL;

  return (
    <Stack.Navigator
      screenOptions={{
        animation: reducedMotion ? 'none' : 'default',
        headerShown: false,
      }}
      initialRouteName={needsArabicBootstrap ? 'LanguageSelect' : 'Home'}>
      <Stack.Screen name="LanguageSelect" component={LanguageSelect} />
      <Stack.Screen name="Login" component={Login} />
      <Stack.Screen name="EditAccount" component={EditAccount} />
      <Stack.Screen name="Feedback" component={Feedback} />
      <Stack.Screen name="Home" component={Home} />
      <Stack.Screen name="Reels" component={Reels} />
      <Stack.Screen name="CourseDetails" component={CourseDetails} />
      <Stack.Screen name="MyCorner" component={MyCorner} />
      <Stack.Screen name="Wallet" component={Wallet} />
      <Stack.Screen name="Profile" component={Profile} />
      <Stack.Screen name="AboutUs" component={AboutUs} />
      <Stack.Screen name="PrivacyPolicy" component={PrivacyPolicy} />
      <Stack.Screen name="TermsOfUse" component={TermsOfUse} />
      <Stack.Screen name="ThirdPartyNotices" component={ThirdPartyNotices} />
      <Stack.Screen name="Notifications" component={Notifications} />
      <Stack.Screen name="Settings" component={Settings} />
      <Stack.Screen name="DeviceSessions" component={DeviceSessions} />
    </Stack.Navigator>
  );
};

const Navigation = () => {
  const isLogin = useSelector((state: RootState) => state.auth.isLogin);
  const sessionData = useSelector((state: RootState) => state.auth.userData);
  const sessionProfile = extractUserProfile(sessionData);
  const navigationSessionKey = isLogin
    ? `user:${String(
        sessionProfile.id ?? sessionProfile.user_id ?? 'authenticated',
      )}`
    : 'guest';
  const restoreFlightRef = React.useRef<Promise<boolean> | null>(null);
  React.useEffect(() => {
    restoredNavigationReady = false;
    lateInitialDestination = null;
    setNotificationNavigationReady(false);
    return () => {
      restoredNavigationReady = false;
      lateInitialDestination = null;
      setNotificationNavigationReady(false);
    };
  }, []);
  const restoreInterruptedLogin = React.useCallback(async () => {
    const initialUrl = await navigationDeadline(Linking.getInitialURL(), null);
    if (parseRoknDestination(initialUrl)) {
      // The learner explicitly opened a fresh app/notification link. It owns
      // this launch and must not be replaced by yesterday's interrupted login.
      await clearPendingLoginReturnTo().catch(() => undefined);
      return false;
    }
    const loginClaim = await navigationDeadline(
      claimPendingLoginReturnTo(),
      undefined,
    );
    if (loginClaim && navigationRef.isReady()) {
      const returnTo = loginClaim.returnTo;
      navigationRef.dispatch(
        CommonActions.reset({
          index: 1,
          routes: [
            {name: 'Home'},
            isLogin
              ? returnTo.name === 'CourseDetails' ||
                returnTo.name === 'Reels' ||
                returnTo.name === 'Profile'
                ? {name: returnTo.name, params: returnTo.params}
                : {name: returnTo.name}
              : {name: 'Login', params: {returnTo}},
          ],
        }),
      );
      // While signed out the record is the durable hand-off across the OAuth
      // browser. A signed-in reset has completed its job and can acknowledge
      // only the exact envelope it read.
      if (isLogin) {
        await acknowledgePendingLoginReturnTo(loginClaim.receipt).catch(
          () => undefined,
        );
      }
      return true;
    }

    if (!isLogin || !navigationRef.isReady()) return false;
    const checkoutClaim = await navigationDeadline(
      claimPendingCheckoutReturn(),
      undefined,
    );
    if (!checkoutClaim || !navigationRef.isReady()) return false;
    const returnTo = checkoutClaim.returnTo;
    navigationRef.dispatch(
      CommonActions.reset({
        index: 1,
        routes: [
          {name: 'Home'},
          returnTo.name === 'CourseDetails' ||
          returnTo.name === 'Reels' ||
          returnTo.name === 'Profile'
            ? {name: returnTo.name, params: returnTo.params}
            : {name: returnTo.name},
        ],
      }),
    );
    await acknowledgePendingCheckoutReturn(checkoutClaim).catch(
      () => undefined,
    );
    return true;
  }, [isLogin]);

  const runInterruptedJourneyRestore = React.useCallback(() => {
    if (restoreFlightRef.current) return restoreFlightRef.current;
    const flight = restoreInterruptedLogin().finally(() => {
      if (restoreFlightRef.current === flight) restoreFlightRef.current = null;
    });
    restoreFlightRef.current = flight;
    return flight;
  }, [restoreInterruptedLogin]);

  React.useEffect(() => {
    // A cold OAuth completion may deliberately outlive the launch deadline.
    // When that durable session arrives after Navigation is already ready,
    // finish the same return journey instead of leaving an authenticated
    // learner stranded on Login until another restart.
    if (isLogin && navigationRef.isReady()) {
      void runInterruptedJourneyRestore();
    }
  }, [isLogin, runInterruptedJourneyRestore]);

  return (
    <NavigationContainer
      linking={linking}
      onReady={() => {
        void runInterruptedJourneyRestore().finally(() => {
          restoredNavigationReady = true;
          flushLateInitialDestination();
          setNotificationNavigationReady(true);
          void flushPendingNotificationNavigation();
        });
      }}
      ref={navigationRef}>
      <Stacks key={navigationSessionKey} />
    </NavigationContainer>
  );
};

export default Navigation;
