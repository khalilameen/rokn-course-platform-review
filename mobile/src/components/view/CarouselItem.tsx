import React, {memo} from 'react';
import {ImageBackground, StyleSheet, Text, View} from 'react-native';
import LinearGradient from 'react-native-linear-gradient';
import {DemoCourse} from '../../data/demoContent';
import {
  formatArabicDisplayText,
  formatArabicMinutes,
  formatArabicNumber,
  formatArabicStudents,
} from '../../constants/arabicFormatting';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
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
          colors={[
            'rgba(7,10,16,0.03)',
            'rgba(7,10,16,0.55)',
            Palette.canvas,
          ]}
          locations={[0.15, 0.58, 1]}
          style={styles.gradient}>
          <View style={styles.copy}>
            {!!course.label && (
              <MetaPill
                label={formatArabicDisplayText(course.label)}
                style={styles.weekPill}
                tone={course.labelTone}
              />
            )}
            <Text numberOfLines={2} style={styles.title}>
              {formatArabicDisplayText(course.title)}
            </Text>
            {isTablet && (
              <Text numberOfLines={2} style={styles.description}>
                {formatArabicDisplayText(course.description)}
              </Text>
            )}
            {(Boolean(course.durationMinutes) ||
              Boolean(course.ratingsCount && course.ratingAverage) ||
              Boolean(course.studentsCount)) && (
              <View style={styles.metaRow}>
                {!!course.durationMinutes && (
                  <Text style={styles.metaText}>
                    {formatArabicMinutes(Math.round(course.durationMinutes))}
                  </Text>
                )}
                {!!course.ratingsCount && !!course.ratingAverage && (
                  <Text style={styles.metaText}>
                    ★ {formatArabicNumber(course.ratingAverage)}
                  </Text>
                )}
                {!!course.studentsCount && (
                  <Text style={styles.metaText}>
                    {formatArabicStudents(course.studentsCount)}
                  </Text>
                )}
              </View>
            )}
            <View style={styles.ctaRow}>
              <Button
                accessibilityLabel={formatArabicDisplayText(`عرض ${course.title}`)}
                onPress={onButtonPress}
                style={styles.button}
                title={course.owned ? 'استكمل الكورس' : 'عرض الكورس'}
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
  metaRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: Spacing.md,
    marginTop: Spacing.sm,
  },
  metaText: {...Type.caption, color: Palette.textMuted},
  weekPill: {alignSelf: 'flex-start'},
  ctaRow: {width: '100%', alignItems: 'center', marginTop: Spacing.xs},
  button: {minWidth: 184, marginTop: 0},
});

export default memo(CarouselItem);
