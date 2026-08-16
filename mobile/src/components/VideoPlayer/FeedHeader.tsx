import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import React, {useEffect, useState} from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import Svg, {Circle, Path} from 'react-native-svg';
import {
  formatArabicNumber,
  toArabicDigits,
} from '../../constants/arabicFormatting';
import {rtlRowStyle, textDirection} from '../../constants/designSystem';
import {Fonts} from '../../constants/styleConstants';
import {VideoFitMode, VideoQuality} from './types';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';

const SPEED_OPTIONS = [0.75, 1, 1.25, 1.5, 2];

interface FeedHeaderProps {
  playbackSpeed: number;
  onPlaybackSpeedChange: (speed: number) => void;
  selectedQuality: VideoQuality;
  qualityOptions: VideoQuality[];
  onQualityChange: (quality: VideoQuality) => void;
  fitMode: VideoFitMode;
  onFitModeChange: (mode: VideoFitMode) => void;
  topInset?: number;
}

const BackIcon = () => (
  <Svg width={22} height={22} viewBox="0 0 24 24">
    <Path
      d="M8.5 5 15.5 12l-7 7"
      fill="none"
      stroke="#fff"
      strokeWidth={2.1}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const DotsIcon = () => (
  <Svg width={24} height={24} viewBox="0 0 24 24">
    {[6, 12, 18].map(y => (
      <Circle key={y} cx={12} cy={y} r={1.7} fill="#fff" />
    ))}
  </Svg>
);

const CheckIcon = () => (
  <Svg width={17} height={17} viewBox="0 0 20 20">
    <Path
      d="m4 10.4 3.7 3.5L16 5.8"
      fill="none"
      stroke="#76A9FF"
      strokeWidth={2.2}
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </Svg>
);

const qualityLabel = (quality: VideoQuality) =>
  quality === 'auto' ? 'تلقائي · موصى به' : toArabicDigits(quality);

const FeedHeader = ({
  playbackSpeed,
  onPlaybackSpeedChange,
  selectedQuality,
  qualityOptions,
  onQualityChange,
  fitMode,
  onFitModeChange,
  topInset = 0,
}: FeedHeaderProps) => {
  const navigation = useNavigation<RootNavigation>();
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!qualityOptions.includes(selectedQuality)) {
      onQualityChange('auto');
    }
  }, [onQualityChange, qualityOptions, selectedQuality]);

  return (
    <View pointerEvents="box-none" style={StyleSheet.absoluteFill}>
      <View style={[styles.header, {top: topInset + 8}]}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="العودة"
          hitSlop={10}
          style={styles.iconButton}
          onPress={() => goBackOrHome(navigation)}>
          <BackIcon />
        </Pressable>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="إعدادات المشاهدة"
          hitSlop={10}
          style={styles.iconButton}
          onPress={() => setOpen(value => !value)}>
          <DotsIcon />
        </Pressable>
      </View>

      {open && (
        <>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="إغلاق إعدادات المشاهدة"
            style={styles.backdrop}
            onPress={() => setOpen(false)}
          />
          <View style={[styles.panel, {top: topInset + 58}]}>
            <Text style={styles.panelTitle}>إعدادات المشاهدة</Text>

            <Text style={styles.sectionLabel}>السرعة</Text>
            <View style={styles.speedRow}>
              {SPEED_OPTIONS.map(speed => {
                const selected = playbackSpeed === speed;
                return (
                  <Pressable
                    key={speed}
                    accessibilityRole="button"
                    style={[styles.speedChip, selected && styles.selectedChip]}
                    onPress={() => onPlaybackSpeedChange(speed)}>
                    <Text
                      style={[
                        styles.speedText,
                        selected && styles.selectedText,
                      ]}>
                      {formatArabicNumber(speed, {maximumFractionDigits: 2})}×
                    </Text>
                  </Pressable>
                );
              })}
            </View>

            <View style={styles.divider} />
            <Text style={styles.sectionLabel}>الجودة</Text>
            <View style={styles.qualityGrid}>
              {qualityOptions.map(quality => {
                const selected = selectedQuality === quality;
                return (
                  <Pressable
                    key={quality}
                    accessibilityRole="button"
                    style={[
                      styles.qualityChip,
                      selected && styles.selectedChip,
                    ]}
                    onPress={() => onQualityChange(quality)}>
                    <Text
                      style={[
                        styles.qualityText,
                        selected && styles.selectedText,
                      ]}>
                      {qualityLabel(quality)}
                    </Text>
                    {selected && <CheckIcon />}
                  </Pressable>
                );
              })}
            </View>

            <View style={styles.divider} />
            <Text style={styles.sectionLabel}>عرض الفيديو</Text>
            <View style={styles.fitRow}>
              {(
                [
                  ['cover', 'ملء الشاشة'],
                  ['contain', 'إظهار الفيديو كاملًا'],
                ] as const
              ).map(([mode, label]) => {
                const selected = fitMode === mode;
                return (
                  <Pressable
                    key={mode}
                    accessibilityRole="button"
                    style={[styles.fitChip, selected && styles.selectedChip]}
                    onPress={() => onFitModeChange(mode)}>
                    <Text
                      style={[
                        styles.qualityText,
                        selected && styles.selectedText,
                      ]}>
                      {label}
                    </Text>
                    {selected && <CheckIcon />}
                  </Pressable>
                );
              })}
            </View>
          </View>
        </>
      )}
    </View>
  );
};

export default FeedHeader;

const styles = StyleSheet.create({
  header: {
    position: 'absolute',
    left: 12,
    right: 12,
    ...rtlRowStyle,
    justifyContent: 'space-between',
    zIndex: 70,
  },
  iconButton: {
    width: 42,
    height: 42,
    borderRadius: 21,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(5,9,14,.5)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.13)',
  },
  backdrop: {
    ...StyleSheet.absoluteFillObject,
    zIndex: 72,
    backgroundColor: 'rgba(0,0,0,.22)',
  },
  panel: {
    position: 'absolute',
    left: 12,
    right: 12,
    zIndex: 73,
    alignSelf: 'center',
    maxWidth: 430,
    padding: 18,
    borderRadius: 22,
    backgroundColor: 'rgba(12,17,25,.97)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.13)',
    shadowColor: '#000',
    shadowOpacity: 0.35,
    shadowRadius: 22,
    shadowOffset: {width: 0, height: 12},
    elevation: 18,
  },
  panelTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 17,
    marginBottom: 17,
  },
  sectionLabel: {
    ...textDirection,
    color: 'rgba(255,255,255,.58)',
    fontFamily: Fonts.medium,
    fontSize: 12,
    marginBottom: 10,
  },
  speedRow: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    gap: 8,
  },
  speedChip: {
    minWidth: 52,
    minHeight: 44,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,.065)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.09)',
  },
  selectedChip: {
    backgroundColor: 'rgba(35,111,232,.2)',
    borderColor: 'rgba(81,142,241,.55)',
  },
  speedText: {
    color: 'rgba(255,255,255,.74)',
    fontFamily: Fonts.medium,
    fontSize: 13,
    fontVariant: ['tabular-nums'],
  },
  selectedText: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
  },
  divider: {
    height: 1,
    backgroundColor: 'rgba(255,255,255,.1)',
    marginVertical: 17,
  },
  qualityGrid: {
    ...rtlRowStyle,
    flexWrap: 'wrap',
    gap: 8,
  },
  qualityChip: {
    minHeight: 44,
    minWidth: 86,
    paddingHorizontal: 11,
    borderRadius: 12,
    ...rtlRowStyle,
    justifyContent: 'center',
    alignItems: 'center',
    gap: 5,
    backgroundColor: 'rgba(255,255,255,.055)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
  qualityText: {
    ...textDirection,
    color: 'rgba(255,255,255,.75)',
    fontFamily: Fonts.regular,
    fontSize: 14,
  },
  fitRow: {
    ...rtlRowStyle,
    gap: 8,
  },
  fitChip: {
    flex: 1,
    minHeight: 44,
    paddingHorizontal: 10,
    borderRadius: 12,
    ...rtlRowStyle,
    justifyContent: 'center',
    alignItems: 'center',
    gap: 5,
    backgroundColor: 'rgba(255,255,255,.055)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,.08)',
  },
});
