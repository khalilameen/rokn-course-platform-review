import type {RouteProp} from '@react-navigation/native';
import type {NativeStackNavigationProp} from '@react-navigation/native-stack';

export type CourseDetailsRouteParams = {
  courseId: string | number;
  openCodeRedemption?: boolean;
  openPurchase?: boolean;
  coinPrice?: number | null;
  title?: string;
  description?: string;
  resumeAfterPreview?: boolean;
  resumeReelId?: string;
};

export type ReelsRouteParams = {
  courseId?: string | number;
  reelId?: string | number;
  lessonId?: string | number;
  initialReelIndex?: number;
  initialPositionSeconds?: number;
  preview?: boolean;
  previewCount?: number;
  coinPrice?: number | null;
  title?: string;
  description?: string;
};

export const LOGIN_RETURN_TO_PARAMLESS_ROUTES = [
  'Wallet',
  'MyCorner',
  'Profile',
  'Settings',
] as const;

export type LoginReturnToParamlessRoute =
  (typeof LOGIN_RETURN_TO_PARAMLESS_ROUTES)[number];

export type LoginReturnTo =
  | {
      name: 'CourseDetails';
      params: Omit<CourseDetailsRouteParams, 'courseId'> & {courseId: string};
    }
  | {
      name: 'Reels';
      params: {
        courseId: string;
        reelId?: string;
        lessonId?: string;
        preview?: boolean;
        previewCount?: number;
      };
    }
  | {
      name: LoginReturnToParamlessRoute;
      params?: never;
    };

export type LoginRouteParams = {
  returnTo?: LoginReturnTo;
};

export type RootStackParamList = {
  Splash: undefined;
  LanguageSelect: undefined;
  Onboarding: undefined;
  Login: LoginRouteParams | undefined;
  EditAccount: undefined;
  Feedback: {sourceScreen?: string} | undefined;
  Home: undefined;
  Reels: ReelsRouteParams;
  CourseDetails: CourseDetailsRouteParams;
  MyCorner: undefined;
  Wallet: undefined;
  Profile: undefined;
  AboutUs: undefined;
  PrivacyPolicy: undefined;
  TermsOfUse: undefined;
  ThirdPartyNotices: undefined;
  Notifications: undefined;
  Settings: undefined;
  DeviceSessions: undefined;
  SearchScreen: {inputSearch: string};
};

export type RootNavigation = NativeStackNavigationProp<RootStackParamList>;
export type RootRoute<RouteName extends keyof RootStackParamList> = RouteProp<
  RootStackParamList,
  RouteName
>;
