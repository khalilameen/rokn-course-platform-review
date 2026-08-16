import React, {useState} from 'react';
import {
  Pressable,
  StyleSheet,
  Text,
  useWindowDimensions,
  View,
} from 'react-native';
import {textDirection} from '../../constants/designSystem';
import {formatArabicDisplayText, formatArabicNumber} from '../../constants/arabicFormatting';
import {Fonts} from '../../constants/styleConstants';
import {CourseReel} from './types';

interface FeedFooterProps {
  data: CourseReel;
  bottomInset?: number;
}

const FeedFooter = ({data, bottomInset = 0}: FeedFooterProps) => {
  const [expanded, setExpanded] = useState(false);
  const {height, fontScale} = useWindowDimensions();
  const compact = height < 620 || fontScale > 1.25;
  return (
    <View
      pointerEvents="box-none"
      style={[
        styles.container,
        compact && styles.containerCompact,
        {bottom: (compact ? 34 : 48) + bottomInset},
      ]}>
      <View style={styles.numberPill}>
        <Text style={styles.numberText} maxFontSizeMultiplier={1.15}>
          الخطوة {formatArabicNumber(data.reelNumber)}
        </Text>
      </View>
      <Text
        style={styles.title}
        numberOfLines={2}>
        {formatArabicDisplayText(data.title)}
      </Text>
      {!!data.caption && (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="عرض الكابشن كاملًا"
          hitSlop={{top: 10, bottom: 10, left: 4, right: 4}}
          onPress={() => setExpanded(value => !value)}>
          <Text
            style={styles.caption}
            numberOfLines={expanded ? (compact ? 4 : 6) : 2}>
            {formatArabicDisplayText(data.caption)}
          </Text>
          {!expanded && data.caption.length > 95 && (
            <Text style={styles.more} maxFontSizeMultiplier={1.15}>
              المزيد
            </Text>
          )}
        </Pressable>
      )}
    </View>
  );
};

export default FeedFooter;

const styles = StyleSheet.create({
  container: {
    position: 'absolute',
    left: 16,
    right: 82,
    zIndex: 20,
    alignItems: 'flex-end',
  },
  containerCompact: {
    left: 12,
    right: 70,
  },
  numberPill: {
    minHeight: 25,
    paddingHorizontal: 10,
    borderRadius: 13,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(5,10,18,.55)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.15)',
    marginBottom: 8,
  },
  numberText: {
    color: 'rgba(255,255,255,.82)',
    fontFamily: Fonts.medium,
    fontSize: 11,
  },
  title: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 18,
    lineHeight: 27,
  },
  caption: {
    ...textDirection,
    marginTop: 4,
    color: 'rgba(255,255,255,.86)',
    fontFamily: Fonts.regular,
    fontSize: 13,
    lineHeight: 21,
  },
  more: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 12,
    marginTop: 2,
  },
});
