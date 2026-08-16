import React from 'react';
import {Pressable, Text, View} from 'react-native';
import styles from './styles';

type CourseCodeRedemptionActionProps = {
  onPress: () => void;
  visible: boolean;
};

export const CourseCodeRedemptionAction = ({
  onPress,
  visible,
}: CourseCodeRedemptionActionProps) => {
  if (!visible) return null;

  return (
    <View style={styles.redemptionCard}>
      <View style={styles.redemptionCopy}>
        <Text style={styles.redemptionTitle}>لديك كود من جهة تعليمية؟</Text>
        <Text style={styles.redemptionDescription}>
          فعّل كود الوصول على حسابك لتجد الكورس جاهزًا هنا.
        </Text>
      </View>
      <Pressable
        accessibilityHint="يفتح نافذة آمنة لإدخال كود الوصول إلى هذا الكورس"
        accessibilityLabel="تفعيل كود جهة تعليمية"
        accessibilityRole="button"
        onPress={onPress}
        style={({pressed}) => [
          styles.redemptionButton,
          pressed && styles.pressed,
        ]}>
        <Text style={styles.redemptionButtonText}>تفعيل الكود</Text>
      </Pressable>
    </View>
  );
};
