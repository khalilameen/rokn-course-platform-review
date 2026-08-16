import React, {memo, useCallback, useState} from 'react';
import {StyleSheet} from 'react-native';
import Carousel from 'react-native-reanimated-carousel';
import {DemoCourse} from '../../data/demoContent';
import {Spacing, useResponsiveLayout} from '../../constants/designSystem';
import CarouselItem from './CarouselItem';

interface CourseCarouselProps {
  data: DemoCourse[];
  onButtonPress: (course: DemoCourse) => void;
}

const CourseCarousel = memo<CourseCarouselProps>(({data, onButtonPress}) => {
  const [focusedIndex, setFocusedIndex] = useState(0);
  const {contentWidth, isTablet, gutter} = useResponsiveLayout();
  const height = isTablet ? Math.min(400, contentWidth * 0.44) : 314;

  const renderItem = useCallback(
    ({item, index}: {item: DemoCourse; index: number}) => (
      <CarouselItem
        course={item}
        isFocused={index === focusedIndex}
        onButtonPress={() => onButtonPress(item)}
      />
    ),
    [focusedIndex, onButtonPress],
  );

  if (!data.length) return null;

  return (
    <Carousel
      autoPlay={data.length > 1}
      autoPlayInterval={5500}
      data={data}
      height={height}
      loop={data.length > 1}
      mode="parallax"
      modeConfig={{
        parallaxScrollingScale: 0.93,
        parallaxScrollingOffset: gutter * 1.8,
      }}
      onSnapToItem={setFocusedIndex}
      renderItem={renderItem}
      style={styles.carousel}
      width={contentWidth}
    />
  );
});

const styles = StyleSheet.create({carousel: {marginBottom: Spacing.xl}});
CourseCarousel.displayName = 'CourseCarousel';
export default CourseCarousel;
