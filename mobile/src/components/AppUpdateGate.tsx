import React, {FC, useEffect, useRef, useState} from 'react';
import {
  ActivityIndicator,
  Linking,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
} from '../constants/designSystem';
import type {AppUpdateNotice} from '../services/appVersionPolicy';

type Props = {
  notice: AppUpdateNotice | null;
  onDismiss: () => void;
};

const AppUpdateGate: FC<Props> = ({notice, onDismiss}) => {
  const insets = useSafeAreaInsets();
  const [opening, setOpening] = useState(false);
  const [linkError, setLinkError] = useState(false);
  const openingFlightRef = useRef<symbol | null>(null);

  useEffect(() => {
    openingFlightRef.current = null;
    setOpening(false);
    setLinkError(false);
  }, [notice]);

  if (!notice) return null;

  const openUpdate = async () => {
    if (!notice.downloadUrl || opening || openingFlightRef.current) return;
    const flight = Symbol('open-app-update');
    openingFlightRef.current = flight;
    setOpening(true);
    setLinkError(false);
    try {
      await Linking.openURL(notice.downloadUrl);
    } catch {
      if (openingFlightRef.current === flight) setLinkError(true);
    } finally {
      if (openingFlightRef.current === flight) {
        openingFlightRef.current = null;
        setOpening(false);
      }
    }
  };

  const unavailable = notice.hasUnsafeDownloadUrl || linkError;
  const canDismiss = !notice.isBlocking || unavailable;
  return (
    <Modal
      animationType="fade"
      onRequestClose={canDismiss ? onDismiss : () => undefined}
      statusBarTranslucent
      transparent
      visible>
      <View style={styles.backdrop}>
        <ScrollView
          bounces={false}
          contentContainerStyle={[
            styles.scrollContent,
            {
              paddingTop: Math.max(insets.top, Spacing.lg),
              paddingBottom: Math.max(insets.bottom, Spacing.lg),
            },
          ]}
          showsVerticalScrollIndicator={false}>
          <View accessibilityViewIsModal style={styles.card}>
            <View style={styles.mark}>
              <Text style={styles.markText}>R</Text>
            </View>
            <Text style={styles.title}>
              {notice.isBlocking ? 'حدّث ركن للمتابعة' : 'نسخة جديدة من ركن'}
            </Text>
            <Text style={styles.message}>{notice.message}</Text>
            {!!notice.releaseNotes && (
              <Text numberOfLines={5} style={styles.notes}>
                {notice.releaseNotes}
              </Text>
            )}
            {unavailable && (
              <Text accessibilityRole="alert" style={styles.error}>
                رابط التحديث غير متاح الآن
              </Text>
            )}
            {!!notice.downloadUrl && (
              <Pressable
                accessibilityRole="button"
                disabled={opening}
                onPress={openUpdate}
                style={({pressed}) => [
                  styles.primaryButton,
                  pressed && styles.primaryButtonPressed,
                ]}>
                {opening ? (
                  <ActivityIndicator color={Palette.text} />
                ) : (
                  <Text style={styles.primaryLabel}>حدّث الآن</Text>
                )}
              </Pressable>
            )}
            {canDismiss && (
              <Pressable
                accessibilityRole="button"
                onPress={onDismiss}
                style={styles.secondaryButton}>
                <Text style={styles.secondaryLabel}>
                  {unavailable ? 'المتابعة' : 'لاحقًا'}
                </Text>
              </Pressable>
            )}
          </View>
        </ScrollView>
      </View>
    </Modal>
  );
};

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: Palette.overlay,
  },
  scrollContent: {
    flexGrow: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: Spacing.lg,
  },
  card: {
    width: '100%',
    maxWidth: 440,
    borderRadius: Radius.xl,
    borderWidth: 1,
    borderColor: Palette.line,
    backgroundColor: Palette.surfaceRaised,
    padding: Spacing.xl,
    alignItems: 'stretch',
  },
  mark: {
    width: 54,
    height: 54,
    borderRadius: Radius.lg,
    alignSelf: 'flex-end',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.primarySoft,
    marginBottom: Spacing.md,
  },
  markText: {...Type.title, color: Palette.primary},
  title: {...Type.title, ...textDirection, color: Palette.text},
  message: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  notes: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    marginTop: Spacing.md,
  },
  error: {
    ...Type.caption,
    ...textDirection,
    color: Palette.danger,
    marginTop: Spacing.md,
  },
  primaryButton: {
    minHeight: 52,
    borderRadius: Radius.md,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.primary,
    marginTop: Spacing.lg,
  },
  primaryButtonPressed: {backgroundColor: Palette.primaryPressed},
  primaryLabel: {...Type.button, color: Palette.text},
  secondaryButton: {
    minHeight: 48,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.xs,
  },
  secondaryLabel: {...Type.bodyStrong, color: Palette.textMuted},
});

export default AppUpdateGate;
