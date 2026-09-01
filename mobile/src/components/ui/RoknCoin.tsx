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
        <LinearGradient id="coinEdge" x1="0" y1="0" x2="0" y2="1">
          <Stop offset="0" stopColor="#F6C999" />
          <Stop offset="0.55" stopColor="#C47A3E" />
          <Stop offset="1" stopColor="#814016" />
        </LinearGradient>
        <LinearGradient id="coinFace" x1="0.12" y1="0.04" x2="0.88" y2="0.96">
          <Stop offset="0" stopColor="#FFE2BB" />
          <Stop offset="0.46" stopColor="#E8AB70" />
          <Stop offset="1" stopColor="#B6672E" />
        </LinearGradient>
        <LinearGradient id="coinMark" x1="0" y1="0" x2="1" y2="1">
          <Stop offset="0" stopColor="#F8D2A5" />
          <Stop offset="1" stopColor="#C67B42" />
        </LinearGradient>
      </Defs>
      <Circle cx="50" cy="53" r="45" fill="#713512" />
      <Circle cx="50" cy="49" r="46" fill="url(#coinEdge)" />
      <Circle
        cx="50"
        cy="48"
        r="39.5"
        fill="url(#coinFace)"
        stroke="#FFE0B5"
        strokeWidth="1.4"
      />
      <Circle
        cx="50"
        cy="48"
        r="35"
        fill="none"
        stroke="#9A5224"
        strokeOpacity="0.46"
        strokeWidth="1.2"
      />
      <Path
        d="M29 23h28c13 0 21 7 21 18 0 9-5 15-14 18l15 18H61L49 60h-6v17H29V23Zm14 11v15h12c6 0 9-3 9-8s-3-7-9-7H43Z"
        fill="url(#coinMark)"
        fillRule="evenodd"
        stroke="#87451D"
        strokeWidth="1.6"
        strokeLinejoin="round"
      />
      <Path d="m49 37 10 5-10 5V37Z" fill="#8A481F" />
      <Path
        d="M47 81h34V60"
        fill="none"
        stroke="#85431C"
        strokeWidth="2.7"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <Path
        d="M18 35c6-13 17-22 31-25"
        fill="none"
        stroke="#FFE8C8"
        strokeOpacity="0.72"
        strokeWidth="2.4"
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
