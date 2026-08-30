import {
  Dimensions,
  NativeModules,
  Platform,
  StatusBar,
  StyleSheet,
} from 'react-native';
import {BrandColors} from './brandTokens';
const {width, height} = Dimensions.get('window');
export const phoneHeight = height;
export const phoneWidth = width;
export const Colors = {
  white: '#ffffff',
  mainColor: BrandColors.primary,
  mainColorShad: BrandColors.primaryPressed,
  secondColor: '#E9F0FF',
  bodyBackground: BrandColors.canvas,
  black: '#000000',
  red: '#FA404F',
  gray: '#ADAAAB',
  grayLight: '#888888',
  grayLighter: '#C2C2C2',
  medGary: '#9B9B9B',
  grayDark: '#171C26',
  iconGray: '#8C96A8',
  warning: '#FF5656',
  border: BrandColors.line,
  surface: BrandColors.surface,
  surfaceRaised: BrandColors.surfaceRaised,
  textSecondary: BrandColors.textMuted,
  coin: BrandColors.coin,
} as const;
export type Colors = (typeof Colors)[keyof typeof Colors];

export const Fonts = {
  extraLight: 'Cairo-ExtraLight',
  regular: 'Cairo-Regular',
  semiBold: 'Cairo-SemiBold',
  light: 'Cairo-Light',
  medium: 'Cairo-Medium',
  bold: 'Cairo-Bold',
  extraBold: 'Cairo-ExtraBold',
  black: 'Cairo-Black',
  plain: 'Cairo-Regular',
};

export enum Images {}

export const ScreenOptions = {
  StatusBarHeight: NativeModules.StatusBarManager.HEIGHT,
  HalfScreen: width / 2 - 15,
  CURRENT_RESOLUTION: Math.sqrt(height * height + width * width),
  DesignResolution: {
    width: 375,
    height: 812,
  },
} as const;

export const createPerfectPixel = (designSize = {width: 375, height: 812}) => {
  if (
    !designSize ||
    !designSize.width ||
    !designSize.height ||
    typeof designSize.width !== 'number' ||
    typeof designSize.height !== 'number'
  ) {
    throw new Error(
      'react-native-pixel-perfect | create function | Invalid design size object! must have width and height fields of type Number.',
    );
  }
  // Scaling from the screen diagonal made controls almost twice their intended
  // size on tablets. Scale from the shortest side and clamp the result so type,
  // icons and touch targets remain deliberate on phones, foldables and tablets.
  const shortestSide = Math.min(width, height);
  const baseSide = Math.min(designSize.width, designSize.height);
  const scale = Math.min(1.16, Math.max(0.9, shortestSide / baseSide));
  return (size: number) => scale * size;
};

export const PixelPerfect = (pixel: number) => {
  const Perfect = createPerfectPixel(ScreenOptions.DesignResolution);
  return Perfect(pixel);
};
export const ColorWithOpacity = (
  hex: Colors | string,
  opacity: number,
): string => {
  const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
  let color;
  if (result) {
    color = {
      r: parseInt(result[1], 16),
      g: parseInt(result[2], 16),
      b: parseInt(result[3], 16),
    };
  } else {
    return hex;
  }
  return `rgba(${color.r},${color.g},${color.b},${opacity})`;
};
export function isIphoneX() {
  const dimen = Dimensions.get('window');
  return (
    Platform.OS === 'ios' &&
    !Platform.isPad &&
    !Platform.isTV &&
    (dimen.height === 780 ||
      dimen.width === 780 ||
      dimen.height === 812 ||
      dimen.width === 812 ||
      dimen.height === 844 ||
      dimen.width === 844 ||
      dimen.height === 896 ||
      dimen.width === 896 ||
      dimen.height === 926 ||
      dimen.width === 926)
  );
}

export function ifIphoneX<T>(iphoneXStyle: T, regularStyle: T): T {
  if (isIphoneX()) {
    return iphoneXStyle;
  }
  return regularStyle;
}

export function getStatusBarHeight(safe: boolean) {
  return Platform.select({
    ios: ifIphoneX(safe ? 44 : 30, 20),
    android: StatusBar.currentHeight,
    default: 0,
  });
}

export function getBottomSpace() {
  return isIphoneX() ? 34 : 0;
}

export function checkIndexIsEven(n: number) {
  return n % 2 === 0;
}

export const sharedHorizontalVal = PixelPerfect(18);

export const SharedStyles = StyleSheet.create({
  shadow: {
    shadowColor: '#000000',
    shadowOffset: {
      width: 0,
      height: 1,
    },
    shadowOpacity: 0.18,
    shadowRadius: 12,

    elevation: 3,
  },

  centred: {
    justifyContent: 'center',
    alignItems: 'center',
  },
  paddingHorizontal: {
    paddingHorizontal: sharedHorizontalVal,
  },
  marginHorizontal: {
    marginHorizontal: sharedHorizontalVal,
  },
  borderRadius: {
    borderRadius: PixelPerfect(16),
  },

  textSpaceIOS: {
    marginBottom: Platform.OS === 'ios' ? PixelPerfect(8) : 0,
  },
});
