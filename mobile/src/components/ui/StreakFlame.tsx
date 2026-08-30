import React from 'react';
import Svg, {Defs, LinearGradient, Path, Stop} from 'react-native-svg';
import {BrandColors} from '../../constants/brandTokens';

export default function StreakFlame({size = 34}: {size?: number}) {
  return (
    <Svg
      accessibilityElementsHidden
      height={size}
      importantForAccessibility="no-hide-descendants"
      viewBox="0 0 48 56"
      width={size * 0.86}>
      <Defs>
        <LinearGradient id="streakOuter" x1="0" x2="1" y1="0" y2="1">
          <Stop offset="0" stopColor="#79B2FF" />
          <Stop offset="0.52" stopColor={BrandColors.primary} />
          <Stop offset="1" stopColor="#1CCFD2" />
        </LinearGradient>
      </Defs>
      <Path
        d="M27 2c2 10-4 14-1 23 2-5 7-8 11-13 1 9 9 14 9 25 0 11-9 19-22 19S2 48 2 36c0-9 6-16 13-23-1 8 1 12 5 15-1-10 2-19 7-26Z"
        fill="url(#streakOuter)"
      />
      <Path
        d="M25 29c1 5-2 7 0 11 1-3 4-5 6-8 1 5 5 8 5 14 0 5-5 9-11 9s-11-4-11-10c0-5 3-8 7-12-1 5 0 7 2 9-1-5 0-10 2-13Z"
        fill="#DDF5FF"
      />
    </Svg>
  );
}
