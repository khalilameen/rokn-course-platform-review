import React from 'react';
import {
  Image,
  ImageSourcePropType,
  Modal,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {RoknCoinStack} from '../../components/ui/RoknCoin';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../../constants/arabicFormatting';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
} from '../../constants/designSystem';
import type {EngagementMessage} from '../../services/api/engagement';

export type HomeCampaign = {
  id: string;
  title: string;
  description: string;
  courseId?: string;
  image?: ImageSourcePropType;
  badge: string;
  actionLabel: string;
};

type Props = {
  campaign: HomeCampaign | null;
  campaignImageFailed: boolean;
  onCampaignImageError: () => void;
  onDismissCampaign: (open: boolean) => void;
  onDismissWelcome: () => void;
  guestPrompt: EngagementMessage | null;
  onDismissGuestPrompt: () => void;
  onOpenGuestPrompt: () => void;
  welcomeMessage: EngagementMessage | null;
  rewardPrompt: EngagementMessage | null;
  onDismissRewardPrompt: () => void;
  onOpenRewardPrompt: () => void;
  welcomeBonus: number | null;
};

export const HomeOverlays = ({
  campaign,
  campaignImageFailed,
  onCampaignImageError,
  onDismissCampaign,
  onDismissWelcome,
  guestPrompt,
  onDismissGuestPrompt,
  onOpenGuestPrompt,
  welcomeMessage,
  rewardPrompt,
  onDismissRewardPrompt,
  onOpenRewardPrompt,
  welcomeBonus,
}: Props) => (
  <>
    <Modal
      animationType="fade"
      onRequestClose={onDismissWelcome}
      transparent
      visible={welcomeBonus !== null}>
      <View style={styles.overlay}>
        <View style={styles.welcomeCard}>
          {welcomeMessage?.imageUrl ? (
            <Image source={{uri: welcomeMessage.imageUrl}} style={styles.promptImage} />
          ) : (
            <RoknCoinStack size={112} />
          )}
          <Text style={styles.welcomeTitle}>{welcomeMessage?.title || 'رصيدك بدأ'}</Text>
          <Text style={styles.welcomeText}>
            {welcomeMessage?.description ||
              `نزلنا لك ${formatArabicNumber(
                Number(welcomeBonus || 0),
              )} عملة ركن في المحفظة. دي عملات داخل التطبيق، مش جنيهات.`}
          </Text>
          <Pressable
            accessibilityRole="button"
            onPress={onDismissWelcome}
            style={({pressed}) => [
              styles.actionButton,
              pressed && styles.pressed,
            ]}>
            <Text style={styles.actionButtonText}>
              {welcomeMessage?.actionLabel || 'شوف الكورسات'}
            </Text>
          </Pressable>
        </View>
      </View>
    </Modal>

    <Modal
      animationType="fade"
      onRequestClose={onDismissRewardPrompt}
      transparent
      visible={rewardPrompt !== null}>
      <View style={styles.overlay}>
        <View style={styles.welcomeCard}>
          {rewardPrompt?.imageUrl ? (
            <Image source={{uri: rewardPrompt.imageUrl}} style={styles.promptImage} />
          ) : (
            <RoknCoinStack size={112} />
          )}
          <Text style={styles.welcomeTitle}>{rewardPrompt?.title}</Text>
          <Text style={styles.welcomeText}>{rewardPrompt?.description}</Text>
          <Pressable accessibilityRole="button" onPress={onOpenRewardPrompt} style={({pressed}) => [styles.actionButton, pressed && styles.pressed]}>
            <Text style={styles.actionButtonText}>{rewardPrompt?.actionLabel || 'شوف المهمة'}</Text>
          </Pressable>
          <Pressable accessibilityRole="button" onPress={onDismissRewardPrompt} style={({pressed}) => [styles.secondaryButton, pressed && styles.pressed]}>
            <Text style={styles.secondaryButtonText}>{rewardPrompt?.secondaryActionLabel || 'مش دلوقتي'}</Text>
          </Pressable>
        </View>
      </View>
    </Modal>

    <Modal
      animationType="fade"
      onRequestClose={onDismissGuestPrompt}
      transparent
      visible={guestPrompt !== null}>
      <View style={styles.overlay}>
        <View style={styles.welcomeCard}>
          {guestPrompt?.imageUrl ? (
            <Image source={{uri: guestPrompt.imageUrl}} style={styles.promptImage} />
          ) : (
            <RoknCoinStack size={112} />
          )}
          <Text style={styles.welcomeTitle}>{guestPrompt?.title}</Text>
          <Text style={styles.welcomeText}>{guestPrompt?.description}</Text>
          <Pressable
            accessibilityRole="button"
            onPress={onOpenGuestPrompt}
            style={({pressed}) => [styles.actionButton, pressed && styles.pressed]}>
            <Text style={styles.actionButtonText}>{guestPrompt?.actionLabel}</Text>
          </Pressable>
          <Pressable
            accessibilityRole="button"
            onPress={onDismissGuestPrompt}
            style={({pressed}) => [styles.secondaryButton, pressed && styles.pressed]}>
            <Text style={styles.secondaryButtonText}>
              {guestPrompt?.secondaryActionLabel || 'كمّل كزائر'}
            </Text>
          </Pressable>
        </View>
      </View>
    </Modal>

    <Modal
      animationType="fade"
      onRequestClose={() => onDismissCampaign(false)}
      transparent
      visible={campaign !== null}>
      <View style={styles.overlay}>
        <View style={styles.campaignCard}>
          <View style={styles.campaignVisual}>
            {campaign?.image && !campaignImageFailed ? (
              <Image
                accessibilityIgnoresInvertColors
                onError={onCampaignImageError}
                source={campaign.image}
                style={styles.campaignCourseImage}
              />
            ) : (
              <Image
                accessibilityIgnoresInvertColors
                source={require('../../assets/images/authLogo.png')}
                style={styles.campaignFallbackLogo}
              />
            )}
          </View>
          <Pressable
            accessibilityLabel="إغلاق"
            accessibilityRole="button"
            hitSlop={10}
            onPress={() => onDismissCampaign(false)}
            style={styles.campaignClose}>
            <Text style={styles.campaignCloseText}>×</Text>
          </Pressable>
          <Text style={styles.campaignBadge}>{campaign?.badge}</Text>
          <Text style={styles.campaignTitle}>
            {formatArabicDisplayText(campaign?.title)}
          </Text>
          <Text style={styles.campaignText}>
            {formatArabicDisplayText(campaign?.description)}
          </Text>
          <Pressable
            accessibilityRole="button"
            onPress={() => onDismissCampaign(true)}
            style={({pressed}) => [
              styles.actionButton,
              pressed && styles.pressed,
            ]}>
            <Text style={styles.actionButtonText}>{campaign?.actionLabel}</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  </>
);

const styles = StyleSheet.create({
  overlay: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: Spacing.xl,
    backgroundColor: Palette.overlay,
  },
  welcomeCard: {
    width: '100%',
    maxWidth: 440,
    alignItems: 'center',
    padding: Spacing.xl,
    borderRadius: Radius.xl,
    borderWidth: 1,
    borderColor: 'rgba(216,166,60,0.28)',
    backgroundColor: Palette.surfaceRaised,
  },
  welcomeTitle: {
    ...Type.title,
    ...textDirection,
    color: Palette.text,
    textAlign: 'center',
    marginTop: Spacing.xs,
  },
  welcomeText: {
    ...Type.body,
    writingDirection: 'rtl',
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: Spacing.xs,
  },
  actionButton: {
    width: '100%',
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.lg,
    borderRadius: Radius.md,
    backgroundColor: Palette.primary,
  },
  actionButtonText: {...Type.bodyStrong, color: '#FFFFFF'},
  secondaryButton: {
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: Spacing.xs,
    paddingHorizontal: Spacing.lg,
  },
  secondaryButtonText: {...Type.bodyStrong, color: Palette.textMuted},
  promptImage: {
    width: 112,
    height: 92,
    borderRadius: Radius.md,
    resizeMode: 'cover',
  },
  campaignCard: {
    width: '100%',
    maxWidth: 440,
    alignItems: 'center',
    padding: Spacing.xl,
    paddingTop: Spacing.xxl,
    borderRadius: Radius.xl,
    borderWidth: 1,
    borderColor: 'rgba(216,166,60,0.22)',
    backgroundColor: Palette.surfaceRaised,
  },
  campaignClose: {
    position: 'absolute',
    top: Spacing.sm,
    right: Spacing.sm,
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
  },
  campaignCloseText: {fontSize: 30, lineHeight: 34, color: Palette.textMuted},
  campaignVisual: {
    width: 108,
    height: 88,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: Spacing.xs,
  },
  campaignCourseImage: {
    width: 108,
    height: 88,
    borderRadius: Radius.md,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
    resizeMode: 'cover',
  },
  campaignFallbackLogo: {
    width: 54,
    height: 54,
    resizeMode: 'contain',
  },
  campaignBadge: {
    ...Type.caption,
    color: '#A9C9FF',
    backgroundColor: Palette.primarySoft,
    borderRadius: Radius.pill,
    overflow: 'hidden',
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.xs,
  },
  campaignTitle: {
    ...Type.title,
    writingDirection: 'rtl',
    color: Palette.text,
    textAlign: 'center',
    marginTop: Spacing.lg,
  },
  campaignText: {
    ...Type.body,
    writingDirection: 'rtl',
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: Spacing.sm,
  },
  pressed: {opacity: 0.75},
});
