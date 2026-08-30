import {NavigationContainer} from '@react-navigation/native';
import type {LinkingOptions} from '@react-navigation/native';
import {createNativeStackNavigator} from '@react-navigation/native-stack';
import {I18nManager, Linking} from 'react-native';

import React from 'react';
import {useSelector} from 'react-redux';
import type {RootState} from '../store/store';

import {navigationRef} from './RootNavigationHelper';

import Splash from '../screens/Splash';
import Reels from '../screens/Reels';
import Home from '../screens/Home';
import Onboarding from '../screens/Onboarding';
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
import {parseRoknDestination} from './deepLinks';
import type {RootStackParamList} from './types';
// import WalletWithdrawalRequest from '../screens/WalletWithdrawalRequest';
const Stack = createNativeStackNavigator<RootStackParamList>();

const linking: LinkingOptions<RootStackParamList> = {
  prefixes: [
    'rokn://',
    'https://rokn.app',
    'https://www.rokn.app',
    'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud',
    // Keep already-shared legacy links functional during the domain cutover.
    'https://rokn.com',
    'https://www.rokn.com',
  ],
  async getInitialURL() {
    return Linking.getInitialURL();
  },
  // Custom-scheme URLs accept the notification route grammar. OAuth callbacks
  // and encoded path fragments are rejected as screen parameters.
  filter: url => parseRoknDestination(url) !== null,
  config: {
    screens: {
      Home: 'home',
      CourseDetails: 'course/:courseId',
      Reels: 'course/:courseId/watch/:reelId?',
      Profile: 'profile',
      Wallet: 'wallet',
    },
  },
};

const Stacks = () => {
  const {onboarding, language} = useSelector(
    (state: RootState) => state.settings,
  );
  const languageCode =
    (typeof language === 'string' ? language : language?.code) || 'ar';
  const needsArabicBootstrap = languageCode !== 'ar' || !I18nManager.isRTL;

  return (
    <Stack.Navigator
      screenOptions={{headerShown: false}}
      initialRouteName={
        needsArabicBootstrap
          ? 'LanguageSelect'
          : !onboarding
          ? 'Onboarding'
          : 'Home'
      }>
      <Stack.Screen name="Splash" component={Splash} />
      <Stack.Screen name="LanguageSelect" component={LanguageSelect} />
      <Stack.Screen name="Onboarding" component={Onboarding} />
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
  return (
    <NavigationContainer linking={linking} ref={navigationRef}>
      <Stacks />
    </NavigationContainer>
  );
};

export default Navigation;
