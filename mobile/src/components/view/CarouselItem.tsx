import React, {memo} from 'react';
import {ImageBackground, StyleSheet, Text, View} from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import {DemoCourse} from '../../data/demoContent';
import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import Button from '../touchables/Button';
import {MetaPill} from '../ui/PremiumUI';

const CarouselItem = ({
  course,
  isFocused: _isFocused,
  onButtonPress,
}: {
  course: DemoCourse;
  isFocused: boolean;
  onButtonPress: () => void;
}) => {
  const {isTablet} = useResponsiveLayout();

  return (
    <View style={styles.outer}>
      <ImageBackground source={course.image} style={styles.imageBackground}>
        <LinearGradient
          colors={['rgba(7,10,16,0.03)', 'rgba(7,10,16,0.55)', '#070A10']}
          locations={[0.15, 0.58, 1]}
          style={styles.gradient}>
          <View style={styles.copy}>
            <MetaPill
              label="كورس الأسبوع"
              style={styles.weekPill}
              tone="primary"
            />
            <Text numberOfLines={2} style={styles.title}>
              {formatArabicDisplayText(course.title)}
            </Text>
            {isTablet && (
              <Text numberOfLines={2} style={styles.description}>
                {formatArabicDisplayText(course.description)}
              </Text>
            )}
            <View style={styles.ctaRow}>
              <Button
                accessibilityLabel={formatArabicDisplayText(`عرض ${course.title}`)}
                onPress={onButtonPress}
                style={styles.button}
                title="ابدأ التعلّم الآن"
              />
            </View>
          </View>
        </LinearGradient>
      </ImageBackground>
    </View>
  );
};

const styles = StyleSheet.create({
  outer: {flex: 1, paddingHorizontal: Spacing.md},
  imageBackground: {
    flex: 1,
    justifyContent: 'flex-end',
    borderRadius: Radius.xl,
    overflow: 'hidden',
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    backgroundColor: Palette.surface,
  },
  gradient: {flex: 1, justifyContent: 'flex-end'},
  copy: {
    width: '100%',
    maxWidth: 620,
    direction: 'rtl',
    alignSelf: 'stretch',
    alignItems: 'stretch',
    paddingHorizontal: Spacing.lg,
    paddingBottom: Spacing.lg,
    paddingTop: Spacing.sm,
  },
  title: {
    ...Type.title,
    ...textDirection,
    color: Palette.text,
    width: '92%',
    marginLeft: 'auto',
    marginTop: Spacing.xs,
  },
  description: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    width: '92%',
    marginLeft: 'auto',
    marginTop: Spacing.xs,
  },
  weekPill: {alignSelf: 'flex-start'},
  ctaRow: {width: '100%', alignItems: 'center', marginTop: Spacing.xs},
  button: {minWidth: 184, marginTop: 0},
});

export default memo(CarouselItem);
