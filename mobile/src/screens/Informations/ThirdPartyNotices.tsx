import React, {useState} from 'react';
import {
  Alert,
  Linking,
  Platform,
  Pressable,
  SectionList,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import RNFS from 'react-native-fs';
import Share from 'react-native-share';
import {Container} from '../../components/containers/Containers';
import {MetaPill, PremiumCard} from '../../components/ui/PremiumUI';
import HeaderWithBack from '../../components/view/HeaderWithBack';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
  useResponsiveLayout,
} from '../../constants/designSystem';
import generatedNotices from '../../data/thirdPartyNotices.generated.json';
import generatedNativeNotices from '../../data/nativeThirdPartyNotices.generated.json';
import {
  buildNoticeSections,
  type NativeNotice,
  type NativeThirdPartyNoticeData,
  type PackageNotice,
  type ThirdPartyNoticeData,
} from './thirdPartyNoticeModel';

const notices = generatedNotices as ThirdPartyNoticeData;
const nativeNotices = generatedNativeNotices as NativeThirdPartyNoticeData;
const sections = buildNoticeSections(notices, nativeNotices);
const selectedLicenseCount = new Set(notices.packages.map(item => item.license))
  .size;

type NoticeBundle = 'npm' | 'native';

const shareBundledNotice = async (bundle: NoticeBundle) => {
  const sourceName =
    bundle === 'npm'
      ? 'THIRD_PARTY_NOTICES.md'
      : 'NATIVE_THIRD_PARTY_NOTICES.md';
  const destination = `${RNFS.CachesDirectoryPath}/rokn-${bundle}-third-party-notices.md`;
  await RNFS.unlink(destination).catch(() => undefined);
  try {
    if (Platform.OS === 'android') {
      const contents = await RNFS.readFileAssets(sourceName, 'utf8');
      await RNFS.writeFile(destination, contents, 'utf8');
    } else {
      await RNFS.copyFile(`${RNFS.MainBundlePath}/${sourceName}`, destination);
    }
    await Share.open({
      failOnCancel: false,
      saveToFiles: true,
      title:
        bundle === 'npm'
          ? 'إشعارات مكتبات JavaScript الكاملة'
          : 'إشعارات مكتبات المنصة الكاملة',
      type: 'text/plain',
      url: `file://${destination}`,
    });
  } finally {
    await RNFS.unlink(destination).catch(() => undefined);
  }
};

const PackageRow = ({
  item,
  expanded,
  onToggle,
}: {
  item: PackageNotice;
  expanded: boolean;
  onToggle: () => void;
}) => (
  <PremiumCard style={styles.packageCard}>
    <Pressable
      accessibilityHint="يعرض بيانات الرخصة ورابط مصدر الحزمة"
      accessibilityLabel={`${item.name}، الإصدار ${item.version}، رخصة ${item.license}`}
      accessibilityRole="button"
      accessibilityState={{expanded}}
      onPress={onToggle}
      style={({pressed}) => [styles.packageHeader, pressed && styles.pressed]}>
      <View style={styles.packageCopy}>
        <Text selectable style={styles.packageName}>
          {item.name}
        </Text>
        <Text style={styles.version}>v{item.version}</Text>
      </View>
      <MetaPill label={item.license} tone="primary" />
    </Pressable>

    {expanded ? (
      <View style={styles.expandedBody}>
        <Text style={styles.choiceNote}>
          الرخصة المختارة: {item.license}
          {item.declaredLicense && item.declaredLicense !== item.license
            ? ` · المعلنة: ${item.declaredLicense}`
            : ''}
        </Text>
        <Text style={styles.legalMetadata}>
          {item.legalSource === 'package-root'
            ? `ملفات قانونية أصلية من الحزمة: ${item.legalFileCount}`
            : 'الحزمة لا تنشر ملفًا قانونيًا مستقلًا؛ تمت مراجعة الغياب وربطه بالإصدار.'}
        </Text>
        {item.apacheNotice ? (
          <Text style={styles.legalMetadata}>
            Apache NOTICE:{' '}
            {item.apacheNotice === 'included'
              ? 'مرفق من الحزمة'
              : 'غير منشور في الحزمة'}
          </Text>
        ) : null}
        <Pressable
          accessibilityHint="يفتح صفحة الإصدار على موقع npm"
          accessibilityRole="link"
          onPress={() =>
            void Linking.openURL(item.sourceUrl).catch(() => undefined)
          }
          style={({pressed}) => [styles.sourceLink, pressed && styles.pressed]}>
          <Text style={styles.sourceLinkLabel}>عرض المصدر والحزمة</Text>
        </Pressable>
      </View>
    ) : null}
  </PremiumCard>
);

const NativeRow = ({item}: {item: NativeNotice}) => (
  <PremiumCard style={styles.packageCard}>
    <View style={styles.nativeRow}>
      <View style={styles.packageCopy}>
        <Text selectable style={styles.packageName}>
          {item.coordinate}
        </Text>
        <Text style={styles.version}>
          {item.legalDocumentCount} ملف قانوني محفوظ
        </Text>
      </View>
      <MetaPill
        label={
          item.licenses.length > 0 ? item.licenses.join(' / ') : 'مراجعة مطلوبة'
        }
        tone="primary"
      />
    </View>
  </PremiumCard>
);

export default function ThirdPartyNotices() {
  const [expandedPackage, setExpandedPackage] = useState<string | null>(null);
  const [sharingNotice, setSharingNotice] = useState<NoticeBundle | null>(null);
  const {contentWidth, gutter} = useResponsiveLayout();

  const onShareNotice = async (bundle: NoticeBundle) => {
    if (sharingNotice) return;
    setSharingNotice(bundle);
    try {
      await shareBundledNotice(bundle);
    } catch {
      Alert.alert(
        'تعذر فتح الإشعارات',
        'ملف الإشعارات موجود داخل التطبيق، لكن تعذر تسليمه لتطبيق العرض أو المشاركة.',
      );
    } finally {
      setSharingNotice(null);
    }
  };

  return (
    <Container noPadding>
      <SectionList
        contentContainerStyle={[
          styles.content,
          {maxWidth: contentWidth, paddingHorizontal: gutter},
        ]}
        sections={sections}
        extraData={{expandedPackage, sharingNotice}}
        initialNumToRender={12}
        keyExtractor={item => item.coordinate}
        ListHeaderComponent={
          <View>
            <HeaderWithBack title="المكتبات مفتوحة المصدر" />
            <Text style={styles.intro}>
              يستخدم رُكن مكتبات مفتوحة المصدر ساهم أصحابها في بناء التجربة.
              يمكنك مراجعة كل حزمة ورخصتها ومصدرها هنا، بينما تُرفق النصوص
              القانونية الكاملة داخل إصدار التطبيق.
            </Text>
            <View style={styles.summaryRow}>
              <MetaPill label={`${notices.packageCount} حزمة`} tone="primary" />
              <MetaPill label={`${selectedLicenseCount} رخصة`} tone="neutral" />
              <MetaPill
                label={`${
                  nativeNotices.androidDependencyCount +
                  nativeNotices.androidProjectComponentCount
                } مكتبة Android`}
                tone="neutral"
              />
              {nativeNotices.podDependencyCount !== null ? (
                <MetaPill
                  label={`${nativeNotices.podDependencyCount} مكتبة iOS`}
                  tone="neutral"
                />
              ) : null}
            </View>
            <Text style={styles.disclosure}>
              القائمة مولّدة من تبعيات نسخة الإنتاج في package-lock.json، وتُرفق
              معها إشعارات مكتبات المنصة المحلولة فعليًا، ولا تشمل أدوات التطوير
              الخالصة.
            </Text>
            <View style={styles.noticeActions}>
              {(['npm', 'native'] as const).map(bundle => (
                <Pressable
                  accessibilityHint="يفتح نسخة كاملة تعمل دون اتصال ويمكن حفظها أو مشاركتها"
                  accessibilityRole="button"
                  disabled={sharingNotice !== null}
                  key={bundle}
                  onPress={() => void onShareNotice(bundle)}
                  style={({pressed}) => [
                    styles.noticeAction,
                    pressed && styles.pressed,
                  ]}>
                  <Text style={styles.noticeActionLabel}>
                    {sharingNotice === bundle
                      ? 'جارٍ تجهيز الملف…'
                      : bundle === 'npm'
                      ? 'فتح إشعارات npm الكاملة'
                      : 'فتح إشعارات المنصة الكاملة'}
                  </Text>
                </Pressable>
              ))}
            </View>
          </View>
        }
        maxToRenderPerBatch={12}
        renderItem={({item}) => {
          if (item.kind === 'npm') {
            return (
              <PackageRow
                expanded={expandedPackage === item.coordinate}
                item={item.notice}
                onToggle={() =>
                  setExpandedPackage(current =>
                    current === item.coordinate ? null : item.coordinate,
                  )
                }
              />
            );
          }
          if (item.kind === 'native') {
            return <NativeRow item={item.notice} />;
          }
          return (
            <PremiumCard style={styles.packageCard}>
              <Text style={styles.statusText}>
                يُنشأ جرد CocoaPods الموثق داخل بناء iOS بعد مطابقة Podfile.lock
                مع مصادر Pods المثبتة. لا يسمح فحص الإصدار بنشر iOS قبل اكتماله.
              </Text>
            </PremiumCard>
          );
        }}
        renderSectionHeader={({section}) => (
          <View style={styles.sectionHeader}>
            <Text style={styles.sectionTitle}>{section.title}</Text>
            <Text style={styles.sectionCount}>{section.countLabel}</Text>
          </View>
        )}
        showsVerticalScrollIndicator={false}
        stickySectionHeadersEnabled
        windowSize={7}
      />
    </Container>
  );
}

const styles = StyleSheet.create({
  content: {
    width: '100%',
    alignSelf: 'center',
    paddingBottom: Spacing.section,
  },
  intro: {
    ...Type.body,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.md,
  },
  summaryRow: {
    ...rtlRowStyle,
    alignItems: 'center',
    gap: Spacing.xs,
    marginTop: Spacing.md,
  },
  disclosure: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    marginTop: Spacing.sm,
    marginBottom: Spacing.md,
  },
  noticeActions: {
    gap: Spacing.sm,
    marginBottom: Spacing.lg,
  },
  noticeAction: {
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.md,
    backgroundColor: Palette.primarySoft,
    paddingHorizontal: Spacing.md,
  },
  noticeActionLabel: {
    ...Type.caption,
    ...textDirection,
    color: '#8BB5FF',
  },
  sectionHeader: {
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: Spacing.md,
    backgroundColor: Palette.canvas,
    paddingVertical: Spacing.sm,
  },
  sectionTitle: {...Type.bodyStrong, ...textDirection, color: Palette.text},
  sectionCount: {...Type.caption, ...textDirection, color: Palette.textFaint},
  nativeRow: {
    minHeight: Accessibility.minTouchTarget,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: Spacing.md,
    padding: Spacing.md,
  },
  statusText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    padding: Spacing.md,
  },
  packageCard: {marginBottom: Spacing.sm},
  packageHeader: {
    minHeight: Accessibility.minTouchTarget,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: Spacing.md,
    padding: Spacing.md,
  },
  packageCopy: {flex: 1, minWidth: 0},
  packageName: {
    ...Type.bodyStrong,
    color: Palette.text,
    textAlign: 'left',
    writingDirection: 'ltr',
  },
  version: {
    ...Type.caption,
    color: Palette.textFaint,
    textAlign: 'left',
    writingDirection: 'ltr',
    marginTop: Spacing.xxs,
  },
  pressed: {opacity: 0.72},
  expandedBody: {
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.lineSoft,
    padding: Spacing.md,
  },
  choiceNote: {...Type.caption, ...textDirection, color: Palette.textMuted},
  legalMetadata: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    marginTop: Spacing.xs,
  },
  sourceLink: {
    minHeight: Accessibility.minTouchTarget,
    alignSelf: 'flex-end',
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
    marginTop: Spacing.sm,
    borderRadius: Radius.pill,
    backgroundColor: Palette.primarySoft,
  },
  sourceLinkLabel: {...Type.caption, ...textDirection, color: '#8BB5FF'},
});
