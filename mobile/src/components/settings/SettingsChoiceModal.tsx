import React from 'react';
import {Modal, Pressable, ScrollView, StyleSheet, Text} from 'react-native';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';

export type SettingsChoice = 'quality' | 'reminderTime' | null;

type Props = {
  bottomInset: number;
  choice: SettingsChoice;
  onClose: () => void;
  onSelect: (key: string) => void;
  quality: string;
  reminderHour: number;
};

const choicesFor = (choice: SettingsChoice) => {
  if (choice === 'reminderTime') {
    return [
      {key: '10', label: 'صباحًا · ١٠:٠٠'},
      {key: '15', label: 'بعد الظهر · ٣:٠٠'},
      {key: '20', label: 'مساءً · ٨:٠٠'},
    ];
  }
  return [
    {key: 'auto', label: 'تلقائي — موصى به'},
    {key: 'data_saver', label: 'توفير البيانات'},
    {key: '720p', label: '٧٢٠p'},
    {key: '1080p', label: '١٠٨٠p'},
  ];
};

export const SettingsChoiceModal = ({
  bottomInset,
  choice,
  onClose,
  onSelect,
  quality,
  reminderHour,
}: Props) => {
  const selectedKey =
    choice === 'reminderTime'
      ? String(reminderHour)
      : quality;
  const title =
    choice === 'reminderTime'
      ? 'وقت مناسب لتذكيرك'
      : 'جودة الفيديو الافتراضية';

  return (
    <Modal
      animationType="fade"
      onRequestClose={onClose}
      transparent
      visible={Boolean(choice)}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="إغلاق النافذة"
        onPress={onClose}
        style={styles.overlay}>
        <Pressable
          accessible={false}
          onPress={event => event.stopPropagation()}
          style={[
            styles.sheet,
            {paddingBottom: Math.max(Spacing.xl, bottomInset + Spacing.md)},
          ]}>
          <ScrollView
            bounces={false}
            contentContainerStyle={styles.content}
            showsVerticalScrollIndicator={false}>
            <Text style={styles.title}>{title}</Text>
            {choicesFor(choice).map(option => {
              const selected = option.key === selectedKey;
              return (
                <Pressable
                  accessibilityRole="radio"
                  accessibilityState={{checked: selected}}
                  key={option.key}
                  onPress={() => onSelect(option.key)}
                  style={[styles.row, selected && styles.rowSelected]}>
                  <Text
                    style={[styles.label, selected && styles.labelSelected]}>
                    {option.label}
                  </Text>
                  {selected && <Text style={styles.check}>✓</Text>}
                </Pressable>
              );
            })}
          </ScrollView>
        </Pressable>
      </Pressable>
    </Modal>
  );
};

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    backgroundColor: Palette.overlay,
    justifyContent: 'flex-end',
    alignItems: 'center',
  },
  sheet: {
    width: '100%',
    maxWidth: 620,
    maxHeight: '90%',
    backgroundColor: Palette.canvasSoft,
    borderTopLeftRadius: Radius.xl,
    borderTopRightRadius: Radius.xl,
    padding: Spacing.xl,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    overflow: 'hidden',
  },
  content: {paddingBottom: Spacing.xs},
  title: {
    ...Type.title,
    ...textDirection,
    color: Palette.text,
    marginBottom: Spacing.md,
  },
  row: {
    minHeight: 56,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: Spacing.sm,
    paddingVertical: Spacing.xs,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: Palette.lineSoft,
    borderRadius: Radius.sm,
  },
  rowSelected: {backgroundColor: Palette.primarySoft},
  label: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
    flex: 1,
    minWidth: 0,
  },
  labelSelected: {color: '#A9C9FF'},
  check: {
    ...Type.bodyStrong,
    color: Palette.primary,
    flexShrink: 0,
    marginStart: Spacing.sm,
  },
});
