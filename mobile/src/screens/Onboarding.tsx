import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import React, {useState} from 'react';
import {Image, StyleSheet, Text, View} from 'react-native';
import {useDispatch} from 'react-redux';
import Button from '../components/touchables/Button';
import {Container, Content} from '../components/containers/Containers';
import {ResponsiveFrame} from '../components/ui/PremiumUI';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../constants/designSystem';
import {FinishOnBoarding} from '../store/reducers/settings';

const steps = [
  {
    number: '١',
    title: 'مقاطع قصيرة بلا حشو',
    description: 'كل مقطع يشرح فكرة واحدة بوضوح',
  },
  {
    number: '٢',
    title: 'تعلّم بالتطبيق',
    description: 'نفّذ مشروعات لتتقدم في الكورس',
  },
  {
    number: '٣',
    title: 'اسأل من داخل المقطع',
    description: 'يساعدك Rokn AI دون مغادرة الكورس',
  },
];

export default function Onboarding() {
  const navigation = useNavigation<RootNavigation>();
  const dispatch = useDispatch();
  const [finishing, setFinishing] = useState(false);

  const finish = () => {
    if (finishing) return;
    setFinishing(true);
    dispatch(FinishOnBoarding(true));
    navigation.reset({index: 0, routes: [{name: 'Home'}]});
  };

  return (
    <Container noPadding>
      <Content
        noPadding
        contentContainerStyle={styles.content}
        paddingBottom={Spacing.xl}>
        <ResponsiveFrame style={styles.frame}>
          <Image
            source={require('../assets/images/logo.png')}
            style={styles.logo}
          />
          <Text style={styles.eyebrow}>تعلّم بمقاطع قصيرة</Text>
          <Text style={styles.title}>تعلّم أسرع وطبّق أكثر</Text>
          <Text style={styles.subtitle}>
            أول منصة كورسات في العالم تنتج محتواها دقيقة بدقيقة
          </Text>
          <View style={styles.steps}>
            {steps.map(step => (
              <View key={step.number} style={styles.step}>
                <View style={styles.stepNumber}>
                  <Text style={styles.stepNumberLabel}>{step.number}</Text>
                </View>
                <View style={styles.stepCopy}>
                  <Text style={styles.stepTitle}>{step.title}</Text>
                  <Text style={styles.stepDescription}>{step.description}</Text>
                </View>
              </View>
            ))}
          </View>
          <Button loader={finishing} onPress={finish} title="ابدأ الآن" />
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  content: {flexGrow: 1, justifyContent: 'center'},
  frame: {maxWidth: 620},
  logo: {
    width: 96,
    height: 52,
    resizeMode: 'contain',
    alignSelf: 'center',
    marginBottom: Spacing.xl,
  },
  eyebrow: {
    ...Type.caption,
    color: Palette.primary,
    textAlign: 'center',
    marginBottom: Spacing.xs,
  },
  title: {
    ...Type.display,
    ...textDirection,
    color: Palette.text,
    textAlign: 'center',
  },
  subtitle: {
    ...Type.body,
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: Spacing.sm,
  },
  steps: {gap: Spacing.sm, marginTop: Spacing.xxl, marginBottom: Spacing.sm},
  step: {
    minHeight: 78,
    ...rtlRowStyle,
    alignItems: 'center',
    padding: Spacing.md,
    borderRadius: Radius.lg,
    backgroundColor: Palette.surface,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
  },
  stepNumber: {
    width: 38,
    height: 38,
    borderRadius: 19,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.primarySoft,
    borderWidth: 1,
    borderColor: 'rgba(52,120,246,0.35)',
  },
  stepNumberLabel: {...Type.bodyStrong, color: Palette.primary},
  stepCopy: {flex: 1, marginHorizontal: Spacing.md},
  stepTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  stepDescription: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 2,
  },
});
