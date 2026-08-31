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
        <Text style={styles.redemptionTitle}>كود جهة تعليمية</Text>
        <Text style={styles.redemptionDescription}>
          فعّل الكود على حسابك لفتح الكورس
        </Text>
      </View>
      <Pressable
        accessibilityHint="يفتح إدخال كود الوصول إلى هذا الكورس"
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
