import React, {FC, useEffect, useRef} from 'react';
import {
  View,
  ScrollView,
  ScrollViewProps,
  StyleProp,
  ViewStyle,
  StyleSheet,
} from 'react-native';
import {
  PixelPerfect,
  sharedHorizontalVal,
} from '../../constants/styleConstants';
import {SafeAreaView} from 'react-native-safe-area-context';
import LinearGradient from 'react-native-linear-gradient';
import {Palette, useResponsiveLayout} from '../../constants/designSystem';

interface containerProps {
  children?: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  fullBackground?: boolean;
  showHint?: boolean;
  noPadding?: boolean;
}
interface contentProps {
  controls?: (scrollView: ScrollView | null) => void;
  noPadding?: boolean;
  style?: StyleProp<ViewStyle>;
  contentContainerStyle?: StyleProp<ViewStyle>;
  paddingVertical?: boolean;
  children?: React.ReactNode;
  refreshControl?: ScrollViewProps['refreshControl'];
  paddingBottom?: number;
  onScroll?: ScrollViewProps['onScroll'];
  scrollEventThrottle?: number;
}
export const Container: FC<containerProps> = ({children, style, noPadding}) => {
  return (
    <LinearGradient
      colors={[Palette.canvas, Palette.canvasSoft]}
      locations={[0, 1]}
      start={{x: 0.5, y: 0}}
      end={{x: 0.5, y: 1}}
      style={[
        styles.container,
        {paddingHorizontal: noPadding ? undefined : sharedHorizontalVal},
        style,
      ]}>
      <SafeAreaView edges={['top', 'bottom']} style={styles.safeArea}>
        {children}
      </SafeAreaView>
    </LinearGradient>
  );
};
export const Content: FC<contentProps> = ({
  controls,
  children,
  noPadding,
  style,
  contentContainerStyle,
  paddingVertical,
  refreshControl,
  paddingBottom,
  onScroll,
  scrollEventThrottle,
}) => {
  const contentRef = useRef<ScrollView>(null);
  const {contentWidth, gutter} = useResponsiveLayout();
  useEffect(() => {
    controls?.(contentRef.current);
  }, [controls]);
  return (
    <ScrollView
      refreshControl={refreshControl}
      ref={contentRef}
      style={[styles.scroll, style]}
      scrollEnabled={true}
      nestedScrollEnabled={true}
      keyboardShouldPersistTaps="handled"
      onScroll={onScroll}
      scrollEventThrottle={scrollEventThrottle}
      contentContainerStyle={[
        styles.scrollContent,
        paddingVertical && {paddingVertical: PixelPerfect(30)},
        contentContainerStyle,
      ]}>
      <View
        style={[
          styles.contentFrame,
          {
            maxWidth: contentWidth,
            paddingHorizontal: noPadding ? undefined : gutter,
            paddingBottom: paddingBottom ?? PixelPerfect(100),
          },
        ]}>
        {children}
      </View>
    </ScrollView>
  );
};
const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: Palette.canvas,
  },
  safeArea: {
    flex: 1,
  },
  scroll: {
    flex: 1,
  },
  scrollContent: {
    flexGrow: 1,
  },
  contentFrame: {
    width: '100%',
    alignSelf: 'center',
  },
});
