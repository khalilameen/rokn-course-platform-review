import {useWindowDimensions} from 'react-native';
import {Colors, Fonts, PixelPerfect} from './styleConstants';

export const Palette = {
  canvas: '#070A10',
  canvasSoft: '#0B1018',
  surface: '#111620',
  surfaceRaised: '#171D29',
  surfacePressed: '#1D2533',
  line: '#252C38',
  lineSoft: 'rgba(255,255,255,0.07)',
  // White text on the former #3478F6 missed WCAG AA for normal copy.
  // This remains unmistakably Rokn blue while clearing the contrast floor.
  primary: '#2C69DB',
  primaryPressed: '#245CC7',
  primarySoft: 'rgba(52,120,246,0.14)',
  action: '#2C69DB',
  actionPressed: '#245CC7',
  coin: '#D8A63C',
  coinSoft: 'rgba(216,166,60,0.13)',
  success: '#48B98A',
  danger: '#F06469',
  text: '#F7F9FC',
  textMuted: '#9BA6B8',
  textFaint: '#768297',
  overlay: 'rgba(3,5,9,0.76)',
} as const;

export const Spacing = {
  xxs: PixelPerfect(4),
  xs: PixelPerfect(8),
  sm: PixelPerfect(12),
  md: PixelPerfect(16),
  lg: PixelPerfect(20),
  xl: PixelPerfect(24),
  xxl: PixelPerfect(32),
  section: PixelPerfect(40),
} as const;

export const Radius = {
  sm: PixelPerfect(10),
  md: PixelPerfect(14),
  lg: PixelPerfect(18),
  xl: PixelPerfect(24),
  pill: 999,
} as const;

export const Type = {
  display: {fontFamily: Fonts.bold, fontSize: PixelPerfect(30), lineHeight: PixelPerfect(42)},
  title: {fontFamily: Fonts.bold, fontSize: PixelPerfect(22), lineHeight: PixelPerfect(32)},
  section: {fontFamily: Fonts.bold, fontSize: PixelPerfect(18), lineHeight: PixelPerfect(28)},
  body: {fontFamily: Fonts.regular, fontSize: PixelPerfect(15), lineHeight: PixelPerfect(25)},
  bodyStrong: {fontFamily: Fonts.semiBold, fontSize: PixelPerfect(15), lineHeight: PixelPerfect(25)},
  caption: {fontFamily: Fonts.regular, fontSize: PixelPerfect(12), lineHeight: PixelPerfect(20)},
  button: {fontFamily: Fonts.bold, fontSize: PixelPerfect(16), lineHeight: PixelPerfect(24)},
} as const;

export const Accessibility = {
  // Interactive targets retain Android's 48dp accessibility baseline.
  // Visual icons can stay smaller inside the target.
  minTouchTarget: Math.max(48, PixelPerfect(48)),
} as const;

/**
 * React Native resolves left/right text alignment against the native layout
 * direction. Rokn's native tree runs in RTL, so the
 * Android and iOS text stacks swap `right` to the physical left edge.
 * Supplying `left` here therefore means the Arabic logical start: the
 * physical right edge. Keep this counter-intuitive value centralized.
 */
export const rtlTextAlign = 'left' as const;

export const textDirection = {
  direction: 'rtl' as const,
  textAlign: rtlTextAlign,
  writingDirection: 'rtl' as const,
};

/**
 * Native RTL is forced before React mounts, so Yoga already places the first
 * child on the physical right. row-reverse here reverses Arabic a second time.
 */
export const rtlRowStyle = {
  direction: 'rtl' as const,
  flexDirection: 'row' as const,
};

/** A fixed row-icon slot that prevents overlap from large text. */
export const fixedIconSlot = {
  width: Accessibility.minTouchTarget,
  minWidth: Accessibility.minTouchTarget,
  height: Accessibility.minTouchTarget,
  flexShrink: 0 as const,
  alignItems: 'center' as const,
  justifyContent: 'center' as const,
};

/** A text column that is allowed to wrap instead of pushing into row actions. */
export const flexibleTextColumn = {
  flexGrow: 1 as const,
  flexShrink: 1 as const,
  minWidth: 0,
};

export const rowDirection = 'row' as const;

/** Logical start is the physical right edge because native RTL is enabled. */
export const rtlStartAlignment = 'flex-start' as const;

/** Responsive values for rails, grids and readable tablet layouts. */
export const useResponsiveLayout = () => {
  const {width, height, fontScale} = useWindowDimensions();
  const shortestSide = Math.min(width, height);
  const isTablet = shortestSide >= 600;
  const isLargeTablet = shortestSide >= 820;
  const gutter = isTablet ? 28 : 18;
  const maxContentWidth = isLargeTablet ? 1120 : isTablet ? 920 : width;
  const contentWidth = Math.min(width, maxContentWidth);
  const gridColumns = isLargeTablet ? 4 : isTablet ? 3 : 2;
  const gridGap = isTablet ? 18 : 12;
  const gridCardWidth =
    (contentWidth - gutter * 2 - gridGap * (gridColumns - 1)) / gridColumns;
  const railCardWidth = Math.min(
    isTablet ? 250 : 184,
    Math.max(156, contentWidth * (isTablet ? 0.28 : 0.48)),
  );

  return {
    width,
    height,
    fontScale,
    isTablet,
    isLargeTablet,
    gutter,
    contentWidth,
    maxContentWidth,
    gridColumns,
    gridGap,
    gridCardWidth,
    railCardWidth,
  };
};

export const surfaceShadow = {
  shadowColor: Colors.black,
  shadowOffset: {width: 0, height: 10},
  shadowOpacity: 0.2,
  shadowRadius: 24,
  elevation: 5,
};
