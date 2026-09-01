import React from 'react';
import {
  Image,
  ImageStyle,
  StyleProp,
  StyleSheet,
  Text,
  TextStyle,
  View,
  ViewStyle,
} from 'react-native';
import Svg, {Circle, Defs, LinearGradient, Path, Stop} from 'react-native-svg';
import {Fonts} from '../../constants/styleConstants';
import {formatArabicNumber} from '../../constants/arabicFormatting';
import {rtlRowStyle} from '../../constants/designSystem';

type Props = {
  size?: number;
  style?: StyleProp<ViewStyle>;
};

const RoknCoin = React.memo(({size = 30, style}: Props) => (
  <View
    accessibilityElementsHidden
    importantForAccessibility="no-hide-descendants"
    style={[{width: size, height: size}, style]}>
    <Svg width={size} height={size} viewBox="0 0 100 100">
      <Defs>
        <LinearGradient id="coinFace" x1="0" y1="0" x2="1" y2="1">
          <Stop offset="0" stopColor="#FFF1A9" />
          <Stop offset="0.42" stopColor="#F4C754" />
          <Stop offset="1" stopColor="#D99119" />
        </LinearGradient>
        <LinearGradient id="coinRim" x1="0" y1="0" x2="0" y2="1">
          <Stop offset="0" stopColor="#FFF5BE" />
          <Stop offset="0.52" stopColor="#EAB63E" />
          <Stop offset="1" stopColor="#C97B10" />
        </LinearGradient>
      </Defs>
      <Circle cx="50" cy="50" r="47" fill="url(#coinRim)" />
      <Circle
        cx="50"
        cy="50"
        r="41"
        fill="url(#coinFace)"
        stroke="#FFF0A1"
        strokeWidth="1.7"
      />
      <Circle
        cx="50"
        cy="50"
        r="36.5"
        fill="none"
        stroke="#C77A10"
        strokeOpacity="0.72"
        strokeWidth="1.4"
      />
      <Path
        d="M29 25h28c13 0 21 7 21 18 0 9-5 15-14 18l15 18H61L49 62h-6v17H29V25Zm14 11v15h12c6 0 9-3 9-8s-3-7-9-7H43Z"
        fill="#F8D66F"
        stroke="#B66F0B"
        strokeWidth="2.2"
        strokeLinejoin="round"
      />
      <Path d="m49 39 10 5-10 5V39Z" fill="#C67910" />
      <Path
        d="M47 83h34V62"
        fill="none"
        stroke="#B66F0B"
        strokeWidth="3"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <Path
        d="M17 38c5-13 16-23 30-27"
        fill="none"
        stroke="#FFF7C8"
        strokeOpacity="0.86"
        strokeWidth="3"
        strokeLinecap="round"
      />
    </Svg>
  </View>
));

RoknCoin.displayName = 'RoknCoin';

const styles = StyleSheet.create({
  amount: {
    ...rtlRowStyle,
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 6,
  },
  amountText: {
    flexShrink: 1,
    minWidth: 0,
    color: '#E9C66F',
    fontFamily: Fonts.semiBold,
    fontSize: 13,
  },
});

export default RoknCoin;

export const RoknCoinStack = ({
  size = 128,
  style,
}: {
  size?: number;
  style?: StyleProp<ImageStyle>;
}) => (
  <Image
    accessibilityElementsHidden
    importantForAccessibility="no-hide-descendants"
    source={require('../../assets/images/coins/rokn-coin-stack-3d-alpha.png')}
    style={[{width: size, height: size, resizeMode: 'contain'}, style]}
  />
);

export const CoinAmount = ({
  value,
  size = 18,
  style,
  textStyle,
}: {
  value: number;
  size?: number;
  style?: StyleProp<ViewStyle>;
  textStyle?: StyleProp<TextStyle>;
}) => (
  <View
    accessibilityLabel={`${formatArabicNumber(value)} من رصيد ركن`}
    style={[styles.amount, style]}>
    <Text
      maxFontSizeMultiplier={2}
      numberOfLines={1}
      style={[styles.amountText, textStyle]}>
      {formatArabicNumber(value)}
    </Text>
    <RoknCoin size={size} />
  </View>
);
