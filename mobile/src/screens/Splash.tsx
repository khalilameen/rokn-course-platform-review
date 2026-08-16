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
import LinearGradient from 'react-native-linear-gradient';
import Animated, {
  Easing,
  useAnimatedStyle,
  useSharedValue,
  withTiming,
} from 'react-native-reanimated';
import {Palette, Radius, Spacing, Type} from '../constants/designSystem';

export default function Splash() {
  const {width, height} = useWindowDimensions();
  const opacity = useSharedValue(0);
  const scale = useSharedValue(0.96);
  const translateY = useSharedValue(10);

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
          duration: 520,
          easing: Easing.out(Easing.cubic),
        });
        scale.value = withTiming(1, {
          duration: 620,
          easing: Easing.out(Easing.cubic),
        });
        translateY.value = withTiming(0, {
          duration: 580,
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

  const haloSize = Math.min(Math.max(width * 0.88, 360), height * 0.72, 760);
  const markSize = Math.min(Math.max(Math.min(width, height) * 0.17, 76), 116);

  return (
    <LinearGradient
      colors={[Palette.canvas, '#0A1220', Palette.canvas]}
      locations={[0, 0.53, 1]}
      style={styles.container}>
      <StatusBar
        backgroundColor="transparent"
        barStyle="light-content"
        translucent
      />

      <View
        pointerEvents="none"
        style={[
          styles.halo,
          {
            width: haloSize,
            height: haloSize,
            borderRadius: haloSize / 2,
          },
        ]}
      />
      <View pointerEvents="none" style={styles.accent} />

      <Animated.View
        accessibilityLabel="رُكن — تعليم قصير، أثر يبقى"
        accessibilityRole="image"
        style={[styles.brand, animatedStyle]}>
        <View
          style={[
            styles.markShell,
            {width: markSize, height: markSize, borderRadius: markSize * 0.29},
          ]}>
          <Image
            resizeMode="contain"
            source={require('../assets/images/authLogo.png')}
            style={styles.mark}
          />
        </View>
        <Text maxFontSizeMultiplier={1.25} style={styles.name}>
          رُكن
        </Text>
        <View style={styles.rule} />
        <Text maxFontSizeMultiplier={1.25} style={styles.tagline}>
          تعليم قصير. أثر يبقى.
        </Text>
      </Animated.View>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
  },
  halo: {
    position: 'absolute',
    backgroundColor: 'rgba(52,120,246,0.075)',
    borderWidth: 1,
    borderColor: 'rgba(89,148,255,0.10)',
  },
  accent: {
    position: 'absolute',
    width: 84,
    height: 2,
    borderRadius: Radius.pill,
    backgroundColor: Palette.primary,
    opacity: 0.42,
    transform: [{rotate: '-38deg'}, {translateY: -138}, {translateX: 124}],
  },
  brand: {
    alignItems: 'center',
    justifyContent: 'center',
    padding: Spacing.xl,
  },
  markShell: {
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,0.035)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.10)',
    shadowColor: Palette.primary,
    shadowOffset: {width: 0, height: 16},
    shadowOpacity: 0.22,
    shadowRadius: 30,
    elevation: 8,
  },
  mark: {width: '72%', height: '72%'},
  name: {
    ...Type.display,
    color: Palette.text,
    marginTop: Spacing.lg,
    textAlign: 'center',
    writingDirection: 'rtl',
  },
  rule: {
    width: 28,
    height: 2,
    borderRadius: Radius.pill,
    backgroundColor: Palette.primary,
    marginTop: Spacing.sm,
    marginBottom: Spacing.sm,
  },
  tagline: {
    ...Type.caption,
    color: Palette.textMuted,
    textAlign: 'center',
    writingDirection: 'rtl',
  },
});
