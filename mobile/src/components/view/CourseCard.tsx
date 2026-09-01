import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import React, {memo} from 'react';
import {
  ImageSourcePropType,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {Fonts, PixelPerfect} from '../../constants/styleConstants';
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
import {MetaPill} from '../ui/PremiumUI';
import {CourseArtwork} from '../ui/CourseArtwork';

export interface Course {
  id: string;
  title: string;
  description: string;
  label?: string;
  labelTone?: 'neutral' | 'primary' | 'coin' | 'success';
  instructor?: string;
  image?: ImageSourcePropType;
  coinPrice?: number;
  durationMinutes?: number;
  ratingAverage?: number;
  ratingsCount?: number;
  studentsCount?: number;
  progress?: number;
  owned?: boolean;
  published?: boolean;
}

interface CourseCardProps {
  item: Course;
  index: number;
  width?: number;
}

const CourseCard = memo<CourseCardProps>(
  ({item, width}) => {
    const navigation = useNavigation<RootNavigation>();
    const {largeText, railCardWidth} = useResponsiveLayout();
    const isAvailable = item.published !== false;
    const opensLearning = isAvailable && item.owned === true;
    const progress = Math.max(0, Math.min(100, Number(item.progress || 0)));
    const accessibilitySummary = [
      item.title,
      item.durationMinutes
        ? formatArabicMinutes(Math.round(item.durationMinutes))
        : undefined,
      item.ratingsCount && item.ratingAverage
        ? `التقييم ${formatArabicNumber(item.ratingAverage)} من ٥`
        : undefined,
      item.studentsCount
        ? formatArabicStudents(item.studentsCount)
        : undefined,
      item.owned && progress > 0 ? `اكتمل ${Math.round(progress)}٪` : undefined,
    ]
      .filter(Boolean)
      .join('، ');

    return (
      <Pressable
        accessibilityHint={
          isAvailable
            ? opensLearning
              ? 'يفتح الكورس من آخر مقطع متاح'
              : 'يفتح تفاصيل الكورس'
            : item.label === 'قريبًا'
            ? 'بطاقة معاينة لكورس سيتوفر قريبًا'
            : 'بطاقة معاينة للكورس'
        }
        accessibilityLabel={formatArabicDisplayText(accessibilitySummary)}
        accessibilityRole="button"
        accessibilityState={{disabled: !isAvailable}}
        disabled={!isAvailable}
        onPress={() =>
          navigation.navigate(
            opensLearning ? 'Reels' : 'CourseDetails',
            opensLearning
              ? {courseId: item.id}
              : {
                  courseId: item.id,
                  coinPrice: item.coinPrice,
                  title: item.title,
                  description: item.description,
                },
          )
        }
        style={({pressed}) => [
          styles.courseItem,
          {width: width ?? railCardWidth},
          pressed && styles.pressed,
        ]}>
        <View style={styles.imageWrap}>
          <CourseArtwork
            fallback={require('../../assets/images/courseSlider.jpg')}
            source={item.image}
            style={styles.courseImage}
          />
          {!!item.label && (
            <MetaPill
              label={formatArabicDisplayText(item.label)}
              tone={item.labelTone}
              style={styles.labelContainer}
            />
          )}
          {typeof item.progress === 'number' && item.progress > 0 && (
            <View style={styles.progressTrack}>
              <View
                style={[
                  styles.progressFill,
                  {width: `${Math.min(100, item.progress)}%`},
                ]}
              />
            </View>
          )}
        </View>
        <Text numberOfLines={largeText ? 4 : 2} style={styles.courseTitle}>
          {formatArabicDisplayText(item.title)}
        </Text>
        {!!item.instructor && (
          <Text numberOfLines={largeText ? 2 : 1} style={styles.instructor}>
            {formatArabicDisplayText(item.instructor)}
          </Text>
        )}
        {(Boolean(item.durationMinutes) ||
          Boolean(item.ratingsCount && item.ratingAverage) ||
          Boolean(item.studentsCount)) && (
          <View style={styles.publicMetaRow}>
            {!!item.durationMinutes && (
              <Text style={styles.publicMetaText}>
                {formatArabicMinutes(Math.round(item.durationMinutes))}
              </Text>
            )}
            {!!item.ratingsCount && !!item.ratingAverage && (
              <Text style={styles.publicMetaText}>
                ★ {formatArabicNumber(item.ratingAverage)}
              </Text>
            )}
            {!!item.studentsCount && (
              <Text style={styles.publicMetaText}>
                {formatArabicStudents(item.studentsCount)}
              </Text>
            )}
          </View>
        )}
        {(item.published === false || item.owned) && (
          <View style={styles.metaRow}>
            {item.published === false ? (
              <Text style={styles.upcomingLabel}>قريبًا</Text>
            ) : (
              <Text style={styles.ownedLabel}>
                {progress >= 100
                  ? 'راجع الكورس'
                  : progress > 0
                  ? 'استكمل من مكانك'
                  : 'ابدأ التعلّم الآن'}
              </Text>
            )}
          </View>
        )}
      </Pressable>
    );
  },
  (previous, next) =>
    previous.item === next.item &&
    previous.index === next.index &&
    previous.width === next.width,
);

const styles = StyleSheet.create({
  courseItem: {
    minWidth: 154,
    direction: 'rtl',
    alignItems: 'stretch',
    paddingBottom: Spacing.xs,
  },
  imageWrap: {
    width: '100%',
    aspectRatio: 1.42,
    borderRadius: Radius.lg,
    overflow: 'hidden',
    backgroundColor: Palette.surfaceRaised,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
  },
  courseImage: {width: '100%', height: '100%', resizeMode: 'cover'},
  courseTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    width: '100%',
    alignSelf: 'stretch',
    marginTop: Spacing.sm,
    minHeight: PixelPerfect(48),
  },
  labelContainer: {
    position: 'absolute',
    top: Spacing.xs,
    right: Spacing.xs,
  },
  instructor: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    width: '100%',
    alignSelf: 'stretch',
  },
  publicMetaRow: {
    ...rtlRowStyle,
    width: '100%',
    alignItems: 'center',
    gap: Spacing.xs,
    marginTop: Spacing.xxs,
    flexWrap: 'wrap',
  },
  publicMetaText: {
    ...Type.caption,
    color: Palette.textMuted,
    fontFamily: Fonts.medium,
  },
  metaRow: {
    width: '100%',
    minHeight: 22,
    alignItems: 'stretch',
    justifyContent: 'center',
    marginTop: Spacing.xxs,
  },
  ownedLabel: {
    ...Type.caption,
    ...textDirection,
    color: '#8BB5FF',
    fontFamily: Fonts.semiBold,
  },
  upcomingLabel: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    fontFamily: Fonts.semiBold,
  },
  progressTrack: {
    position: 'absolute',
    start: 0,
    end: 0,
    bottom: 0,
    height: 3,
    backgroundColor: 'rgba(255,255,255,0.16)',
  },
  progressFill: {height: '100%', backgroundColor: Palette.primary},
  pressed: {opacity: 0.8, transform: [{scale: 0.985}]},
});

CourseCard.displayName = 'CourseCard';
export default CourseCard;
