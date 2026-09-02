import type {RouteProp} from '@react-navigation/native';
import type {NativeStackNavigationProp} from '@react-navigation/native-stack';

export type CourseDetailsRouteParams = {
  courseId: string | number;
  openCodeRedemption?: boolean;
  openFullTrackUpgrade?: boolean;
  openPurchase?: boolean;
  purchasePlanCode?: string;
  purchaseCouponCode?: string;
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
  openCourseChatUpgrade?: boolean;
};

export const LOGIN_RETURN_TO_PARAMLESS_ROUTES = [
  'Wallet',
  'MyCorner',
  'Settings',
  'EditAccount',
  'DeviceSessions',
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
        openCourseChatUpgrade?: boolean;
      };
    }
  | {
      name: 'Profile';
      params?: {tab: 'portfolio' | 'certificates' | 'saved'};
    }
  | {
      name: LoginReturnToParamlessRoute;
      params?: never;
    };

export type LoginRouteParams = {
  returnTo?: LoginReturnTo;
};

export type RootStackParamList = {
  LanguageSelect: undefined;
  Login: LoginRouteParams | undefined;
  EditAccount: undefined;
  Feedback: {sourceScreen?: string; caseId?: string} | undefined;
  Home: undefined;
  Reels: ReelsRouteParams;
  CourseDetails: CourseDetailsRouteParams;
  MyCorner: undefined;
  Wallet: {returnTo?: LoginReturnTo} | undefined;
  Profile: {tab?: 'portfolio' | 'certificates' | 'saved'} | undefined;
  AboutUs: undefined;
  PrivacyPolicy: undefined;
  TermsOfUse: undefined;
  ThirdPartyNotices: undefined;
  Notifications: undefined;
  Settings: undefined;
  DeviceSessions: undefined;
};

export type RootNavigation = NativeStackNavigationProp<RootStackParamList>;
export type RootRoute<RouteName extends keyof RootStackParamList> = RouteProp<
  RootStackParamList,
  RouteName
>;
