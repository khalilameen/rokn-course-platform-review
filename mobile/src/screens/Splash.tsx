import React, {useEffect} from 'react';
import {
  AccessibilityInfo,
  Image,
  StatusBar,
  StyleSheet,
  Text,
  useWindowDimensions,
  View,
} from 'react-native';
import Animated, {
  Easing,
  useAnimatedStyle,
  useSharedValue,
  withTiming,
} from 'react-native-reanimated';
import {Palette, Spacing, Type} from '../constants/designSystem';

export default function Splash() {
  const {width} = useWindowDimensions();
  const opacity = useSharedValue(0);
  const scale = useSharedValue(0.985);
  const translateY = useSharedValue(4);

  useEffect(() => {
    let mounted = true;

    AccessibilityInfo.isReduceMotionEnabled()
      .then(reduceMotion => {
        if (!mounted) return;
        if (reduceMotion) {
          opacity.value = 1;
          scale.value = 1;
          translateY.value = 0;
          return;
        }

        opacity.value = withTiming(1, {
          duration: 360,
          easing: Easing.out(Easing.cubic),
        });
        scale.value = withTiming(1, {
          duration: 440,
          easing: Easing.out(Easing.cubic),
        });
        translateY.value = withTiming(0, {
          duration: 420,
          easing: Easing.out(Easing.cubic),
        });
      })
      .catch(() => {
        opacity.value = 1;
        scale.value = 1;
        translateY.value = 0;
      });

    return () => {
      mounted = false;
    };
  }, [opacity, scale, translateY]);

  const animatedStyle = useAnimatedStyle(() => ({
    opacity: opacity.value,
    transform: [{translateY: translateY.value}, {scale: scale.value}],
  }));

  const logoWidth = Math.min(Math.max(width * 0.42, 154), 208);

  return (
    <View style={styles.container}>
      <StatusBar
        backgroundColor={Palette.canvas}
        barStyle="light-content"
      />

      <Animated.View
        accessibilityLabel="ركن دقيقة بدقيقة"
        accessibilityRole="image"
        style={[styles.brand, animatedStyle]}>
        <Image
          resizeMode="contain"
          source={require('../assets/images/logo.png')}
          style={{width: logoWidth, height: logoWidth / 3.35}}
        />
        <Text maxFontSizeMultiplier={1.25} style={styles.tagline}>
          دقيقة بدقيقة
        </Text>
      </Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
    backgroundColor: Palette.canvas,
  },
  brand: {
    alignItems: 'center',
    justifyContent: 'center',
    padding: Spacing.xl,
  },
  tagline: {
    ...Type.caption,
    color: Palette.textMuted,
    textAlign: 'center',
    writingDirection: 'rtl',
    marginTop: Spacing.md,
  },
});
