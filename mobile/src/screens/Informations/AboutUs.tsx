import React from 'react';
import {Image, StyleSheet, Text, View} from 'react-native';
import {Container, Content} from '../../components/containers/Containers';
import {
  PremiumCard,
  ResponsiveFrame,
  SectionHeading,
} from '../../components/ui/PremiumUI';
import HeaderWithBack from '../../components/view/HeaderWithBack';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';

const PRINCIPLES = [
  {
    index: '٠١',
    title: 'مقطع قصير بدل محاضرة طويلة',
    description:
      'كل مقطع يشرح فكرة واحدة بوضوح لتتقدم في وقت قصير',
  },
  {
    index: '٠٢',
    title: 'التطبيق قبل الاستهلاك',
    description:
      'مشاريع عبور عملية تحوّل المشاهدة إلى عمل حقيقي، من دون تعطيل من بذل مجهودًا صادقًا.',
  },
  {
    index: '٠٣',
    title: 'المساعدة داخل الرحلة',
    description:
      'مدرب ذكي يجيبك في سياق ما تتعلمه، كي لا تضطر إلى ترك الكورس والبحث بعيدًا.',
  },
];

export default function AboutUs() {
  const {isTablet} = useResponsiveLayout();

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame>
          <HeaderWithBack title="عن رُكن" />

          <PremiumCard style={[styles.hero, isTablet && styles.heroTablet]}>
            <View style={[styles.logoShell, isTablet && styles.logoShellTablet]}>
              <Image
                resizeMode="contain"
                source={require('../../assets/images/authLogo.png')}
                style={styles.logo}
              />
            </View>
            <View style={styles.heroCopy}>
              <Text style={styles.eyebrow}>
                منصة تعليم عربية من مصر
              </Text>
              <Text style={styles.heroTitle}>
                نتعلم كما نعيش اليوم:{'\n'}بسرعة، لكن بعمق.
              </Text>
              <Text style={styles.heroDescription}>
                رُكن يحوّل منطق منصات الفيديو القصير إلى تجربة تعليمية منظمة:
                كورسات من مقاطع قصيرة مترابطة ومشروعات تثبت ما تعلمته ومسار واضح حتى
                الشهادة والبورتفوليو.
              </Text>
            </View>
          </PremiumCard>

          <SectionHeading
            eyebrow="فلسفة المنتج"
            style={styles.heading}
            title="بسيط في الاستخدام، جاد في النتيجة"
          />

          <View style={[styles.cards, isTablet && styles.cardsTablet]}>
            {PRINCIPLES.map(item => (
              <PremiumCard
                accessibilityLabel={`${item.title}. ${item.description}`}
                key={item.index}
                style={[styles.principleCard, isTablet && styles.principleCardTablet]}>
                <Text style={styles.index}>{item.index}</Text>
                <Text style={styles.cardTitle}>{item.title}</Text>
                <Text style={styles.cardDescription}>{item.description}</Text>
              </PremiumCard>
            ))}
          </View>

          <PremiumCard style={styles.promiseCard}>
            <View style={styles.promiseLine} />
            <View style={styles.promiseCopy}>
              <Text style={styles.promiseTitle}>وعد رُكن</Text>
              <Text style={styles.promiseDescription}>
                لا نحشو الشاشة بما لا يخدم تعلّمك، ولا نقيس النجاح بعدد الساعات.
                هدفنا أن تصل إلى إنجاز تستطيع عرضه، لا إلى قائمة فيديوهات شاهدتها
                فقط.
              </Text>
            </View>
          </PremiumCard>

          <Text style={styles.footer}>صُمّم بعناية للمتعلم العربي.</Text>
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  hero: {
    padding: Spacing.xl,
    marginTop: Spacing.sm,
    backgroundColor: Palette.surfaceRaised,
  },
  heroTablet: {...rtlRowStyle, alignItems: 'center', padding: Spacing.xxl},
  logoShell: {
    width: 88,
    height: 88,
    borderRadius: Radius.xl,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.primarySoft,
    borderWidth: 1,
    borderColor: 'rgba(89,148,255,0.22)',
    alignSelf: 'flex-end',
    marginBottom: Spacing.lg,
  },
  logoShellTablet: {marginBottom: 0, marginEnd: Spacing.xxl},
  logo: {width: '70%', height: '70%'},
  heroCopy: {flex: 1},
  eyebrow: {
    ...Type.caption,
    ...textDirection,
    color: Palette.primary,
    marginBottom: Spacing.xs,
  },
  heroTitle: {
    ...Type.display,
    ...textDirection,
    color: Palette.text,
  },
  heroDescription: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.md,
    maxWidth: 720,
  },
  heading: {marginTop: Spacing.xl, marginBottom: Spacing.sm},
  cards: {gap: Spacing.sm},
  cardsTablet: {...rtlRowStyle},
  principleCard: {padding: Spacing.lg},
  principleCardTablet: {flex: 1, minHeight: 210},
  index: {...Type.caption, ...textDirection, color: Palette.primary},
  cardTitle: {
    ...Type.section,
    ...textDirection,
    color: Palette.text,
    marginTop: Spacing.md,
  },
  cardDescription: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  promiseCard: {
    ...rtlRowStyle,
    padding: Spacing.lg,
    marginTop: Spacing.lg,
    backgroundColor: 'rgba(52,120,246,0.075)',
  },
  promiseLine: {
    width: 3,
    borderRadius: Radius.pill,
    backgroundColor: Palette.primary,
    marginEnd: Spacing.md,
  },
  promiseCopy: {flex: 1},
  promiseTitle: {
    ...Type.bodyStrong,
    ...textDirection,
    color: Palette.text,
  },
  promiseDescription: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.xs,
  },
  footer: {
    ...Type.caption,
    color: Palette.textFaint,
    textAlign: 'center',
    writingDirection: 'rtl',
    marginVertical: Spacing.xl,
  },
});
