import {useCallback, useState} from 'react';
import type {
  LayoutChangeEvent,
  NativeScrollEvent,
  NativeSyntheticEvent,
} from 'react-native';
import {shouldShowStickyCourseAction} from '../../../utils/courseDetailsPresentation';

export const useStickyCourseAction = (heroHeight: number) => {
  const [showStickyAction, setShowStickyAction] = useState(false);
  const [primaryActionLocalBottom, setPrimaryActionLocalBottom] = useState<
    number | null
  >(null);

  const onScroll = useCallback(
    (event: NativeSyntheticEvent<NativeScrollEvent>) => {
      setShowStickyAction(
        shouldShowStickyCourseAction({
          scrollOffset: event.nativeEvent.contentOffset.y,
          heroHeight,
          primaryActionLocalBottom,
        }),
      );
    },
    [heroHeight, primaryActionLocalBottom],
  );

  const onPrimaryActionLayout = useCallback((event: LayoutChangeEvent) => {
    const {height, y} = event.nativeEvent.layout;
    setPrimaryActionLocalBottom(y + height);
  }, []);

  return {onPrimaryActionLayout, onScroll, showStickyAction};
};
