import {
  useFocusEffect,
  useNavigation,
  useRoute,
} from '@react-navigation/native';
import type {RootNavigation, RootRoute} from '../../navigation/types';
import React, {useCallback, useEffect, useMemo, useState} from 'react';
import {
  Alert,
  Image,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useSelector} from 'react-redux';
import {openExternalUrlOnce, shareOnce} from '../../services/systemActions';
import {SettingsIcon, ShareProfileIcon} from '../../assets/SVG';
import TabBar from '../../components/TabBar';
import {Container, Content} from '../../components/containers/Containers';
import {
  MetaPill,
  PremiumCard,
  ResponsiveFrame,
} from '../../components/ui/PremiumUI';
import HeaderWithBack from '../../components/view/HeaderWithBack';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import Certificates from './Certificates';
import Gallery from './Gallery';
import SavedVideos from './SavedVideos';
import {
  extractApiToken,
  extractUserProfile,
  getCurrentAccountStorageScope,
} from '../../constants/helpers';
import {
  getPortfolioProfile,
  getProfile,
  hasSession,
  PortfolioProfile,
  Profile as ProfileDto,
} from '../../services/roknApi';
import type {RootState} from '../../store/store';
import {
  portfolioUrlFor,
  trustedPortfolioShareUrl,
} from '../../services/publicLinks';
import {isolateBidirectionalText} from '../../constants/arabicFormatting';

type ProfileTab = 'portfolio' | 'certificates' | 'saved';
export default function Profile() {
  const navigation = useNavigation<RootNavigation>();
  const route = useRoute<RootRoute<'Profile'>>();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const user = extractUserProfile(storedUser);
  const hasStoredToken = Boolean(extractApiToken(storedUser));
  const identityKey = hasStoredToken
    ? String(user.id ?? user.user_id ?? 'authenticated')
    : 'guest';
  const [activeTab, setActiveTab] = useState<ProfileTab>('portfolio');
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [remoteProfile, setRemoteProfile] = useState<ProfileDto | null>(null);
  const [portfolioProfile, setPortfolioProfile] =
    useState<PortfolioProfile | null>(null);
  const [loadedIdentity, setLoadedIdentity] = useState('');
  const [profileError, setProfileError] = useState('');
  const [avatarFailed, setAvatarFailed] = useState(false);
  const [hasShareablePortfolio, setHasShareablePortfolio] = useState(false);
  const [reloadProfile, setReloadProfile] = useState(0);
  const authenticatedIdentity =
    serverSession === true || (serverSession === null && hasStoredToken);
  const visibleRemoteProfile =
    loadedIdentity === identityKey ? remoteProfile : null;
  const visiblePortfolioProfile =
    loadedIdentity === identityKey ? portfolioProfile : null;
  const displayName =
    visibleRemoteProfile?.name ||
    (authenticatedIdentity ? user.name : '') ||
    'ضيف ركن';
  const role =
    visibleRemoteProfile?.jobTitle ||
    (authenticatedIdentity ? user.job_title : '') ||
    '';
  const username =
    visiblePortfolioProfile?.slug ||
    visibleRemoteProfile?.portfolioSlug ||
    (authenticatedIdentity ? user.portfolio_slug || user.username : '') ||
    '';
  const publicPortfolioUrl =
    trustedPortfolioShareUrl(visiblePortfolioProfile?.publicUrl) ||
    trustedPortfolioShareUrl(visibleRemoteProfile?.portfolioUrl) ||
    trustedPortfolioShareUrl(username ? portfolioUrlFor(username) : '') ||
    '';
  const canSharePortfolio = Boolean(
    authenticatedIdentity && hasShareablePortfolio && publicPortfolioUrl,
  );
  const portfolioLinkLabel = publicPortfolioUrl
    ? publicPortfolioUrl.replace(/^https:\/\/(?:www\.)?/i, '').replace(/\/$/, '')
    : '';
  const avatarUri = useMemo(
    () =>
      visibleRemoteProfile?.avatar ||
      (authenticatedIdentity ? user.avatar || user.profile_image : ''),
    [
      authenticatedIdentity,
      user.avatar,
      user.profile_image,
      visibleRemoteProfile?.avatar,
    ],
  );

  useEffect(() => setAvatarFailed(false), [avatarUri]);

  useEffect(() => {
    // A shareable item belongs to one authenticated account. Never keep the
    // previous account's button visible while the next portfolio hydrates.
    setHasShareablePortfolio(false);
  }, [identityKey]);

  useEffect(() => {
    if (route.params?.tab) setActiveTab(route.params.tab);
  }, [route.params?.tab]);

  useFocusEffect(
    useCallback(() => {
      const requestGeneration = reloadProfile;
      let active = true;
      void (async () => {
        if (active) setProfileError('');
        const accountScope = await getCurrentAccountStorageScope();
        const sessionAvailable = await hasSession();
        if (
          !active ||
          requestGeneration !== reloadProfile ||
          (await getCurrentAccountStorageScope()) !== accountScope
        ) return;
        setServerSession(sessionAvailable);
        if (!sessionAvailable) {
          if (active) {
            setRemoteProfile(null);
            setPortfolioProfile(null);
            setLoadedIdentity(identityKey);
          }
          return;
        }
        const [profileResult, portfolioResult] = await Promise.allSettled([
          getProfile(),
          getPortfolioProfile(),
        ]);
        if (
          !active ||
          requestGeneration !== reloadProfile ||
          (await getCurrentAccountStorageScope()) !== accountScope
        ) {
          return;
        }
        if (active && profileResult.status === 'fulfilled') {
          setRemoteProfile(profileResult.value);
        }
        if (active && portfolioResult.status === 'fulfilled') {
          setPortfolioProfile(portfolioResult.value);
        }
        setLoadedIdentity(identityKey);
        if (
          active &&
          (profileResult.status === 'rejected' ||
            portfolioResult.status === 'rejected')
        ) {
          setProfileError('تعذّر تحديث بعض بيانات الحساب');
        }
      })();
      return () => {
        active = false;
      };
    }, [identityKey, reloadProfile]),
  );
  const tabs: {key: ProfileTab; label: string}[] = [
    {key: 'portfolio', label: 'أعمالي'},
    {key: 'certificates', label: 'الشهادات'},
    {key: 'saved', label: 'المحفوظات'},
  ];

  const sharePortfolio = async () => {
    try {
      await shareOnce('portfolio', {
        title: `بورتفوليو ${displayName} على ركن`,
        message: `شاهد أعمالي وشهاداتي الموثقة على ركن\n${publicPortfolioUrl}`,
        url: publicPortfolioUrl,
      });
    } catch {
      Alert.alert('تعذّرت المشاركة', 'حاول مرة أخرى');
    }
  };

  const openPortfolio = async () => {
    try {
      await openExternalUrlOnce(publicPortfolioUrl);
    } catch {
      Alert.alert('تعذّر فتح الرابط', 'حاول مرة أخرى');
    }
  };

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame>
          <HeaderWithBack
            hasArrow={false}
            rightContent={() => (
              <Pressable
                accessibilityLabel="الإعدادات"
                accessibilityRole="button"
                onPress={() => navigation.navigate('Settings')}
                style={styles.headerButton}>
                <SettingsIcon />
              </Pressable>
            )}
            title="حسابي"
          />

          {!!profileError && authenticatedIdentity && (
            <Pressable
              accessibilityLiveRegion="polite"
              accessibilityRole="button"
              onPress={() => setReloadProfile(value => value + 1)}
              style={styles.staleNotice}>
              <Text style={styles.staleNoticeText}>{profileError}</Text>
              <Text style={styles.staleNoticeAction}>إعادة المحاولة</Text>
            </Pressable>
          )}

          <PremiumCard style={styles.profileCard}>
            <View style={styles.profileTop}>
              <Image
                accessibilityLabel={`صورة ${displayName}`}
                onError={() => setAvatarFailed(true)}
                source={
                  avatarUri && !avatarFailed
                    ? {uri: avatarUri}
                    : require('../../assets/images/default-avatar.png')
                }
                style={styles.avatar}
              />
              <View style={styles.profileCopy}>
                <Text style={styles.name}>{displayName}</Text>
                {!!role && <Text style={styles.role}>{role}</Text>}
                {!authenticatedIdentity && (
                  <MetaPill
                    label="تصفّح كضيف"
                    tone="neutral"
                    style={styles.availability}
                  />
                )}
              </View>
              {canSharePortfolio && (
                <Pressable
                  accessibilityLabel="مشاركة البورتفوليو"
                  accessibilityRole="button"
                  onPress={() => void sharePortfolio()}
                  style={({pressed}) => [
                    styles.shareButton,
                    pressed && styles.pressed,
                  ]}>
                  <ShareProfileIcon />
                </Pressable>
              )}
            </View>
            {canSharePortfolio && (
              <Pressable
                accessibilityLabel="فتح رابط مشاركة البورتفوليو"
                accessibilityRole="link"
                onPress={() => void openPortfolio()}
                style={({pressed}) => [
                  styles.publicLink,
                  pressed && styles.pressed,
                ]}>
                <Text numberOfLines={1} style={styles.publicLinkText}>
                  {isolateBidirectionalText(portfolioLinkLabel)}
                </Text>
              </Pressable>
            )}
          </PremiumCard>

          <View accessibilityRole="tablist" style={styles.tabs}>
            {tabs.map(tab => {
              const selected = activeTab === tab.key;
              return (
                <Pressable
                  accessibilityRole="tab"
                  accessibilityState={{selected}}
                  key={tab.key}
                  onPress={() => setActiveTab(tab.key)}
                  style={({pressed}) => [
                    styles.tab,
                    selected && styles.activeTab,
                    pressed && styles.pressed,
                  ]}>
                  <Text
                    style={[
                      styles.tabLabel,
                      selected && styles.activeTabLabel,
                    ]}>
                    {tab.label}
                  </Text>
                </Pressable>
              );
            })}
          </View>

          {activeTab === 'portfolio' && (
            <Gallery
              key={`portfolio:${identityKey}`}
              onShareablePortfolioChange={setHasShareablePortfolio}
            />
          )}
          {activeTab === 'certificates' && (
            <Certificates
              key={`certificates:${identityKey}`}
              displayName={displayName}
              username={username}
            />
          )}
          {activeTab === 'saved' && <SavedVideos key={`saved:${identityKey}`} />}
        </ResponsiveFrame>
      </Content>
      <TabBar />
    </Container>
  );
}

const styles = StyleSheet.create({
  headerButton: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
  },
  profileCard: {padding: Spacing.lg, marginBottom: Spacing.lg},
  staleNotice: {
    minHeight: Accessibility.minTouchTarget,
    ...rtlRowStyle,
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: Spacing.sm,
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.xs,
    marginBottom: Spacing.sm,
    borderRadius: Radius.md,
    backgroundColor: 'rgba(240,100,105,0.09)',
    borderWidth: 1,
    borderColor: 'rgba(240,100,105,0.18)',
  },
  staleNoticeText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    flex: 1,
  },
  staleNoticeAction: {...Type.caption, color: '#8BB5FF', flexShrink: 0},
  profileTop: {...rtlRowStyle, alignItems: 'center'},
  avatar: {
    width: 72,
    height: 72,
    borderRadius: 36,
    borderWidth: 2,
    borderColor: Palette.line,
  },
  profileCopy: {flex: 1, marginHorizontal: Spacing.md},
  name: {...Type.title, ...textDirection, color: Palette.text},
  role: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: 2,
  },
  availability: {marginTop: Spacing.xs},
  shareButton: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    borderRadius: Radius.md,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: Palette.primarySoft,
    borderWidth: 1,
    borderColor: 'rgba(52,120,246,0.24)',
  },
  publicLink: {
    marginTop: Spacing.md,
    paddingTop: Spacing.md,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Palette.lineSoft,
  },
  publicLinkText: {
    ...Type.bodyStrong,
    color: '#8BB5FF',
    writingDirection: 'ltr',
    textAlign: 'left',
  },
  tabs: {
    ...rtlRowStyle,
    backgroundColor: Palette.surface,
    borderRadius: Radius.md,
    padding: 4,
    marginBottom: Spacing.lg,
    borderWidth: 1,
    borderColor: Palette.lineSoft,
  },
  tab: {
    flex: 1,
    minHeight: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.sm,
  },
  activeTab: {backgroundColor: Palette.surfacePressed},
  tabLabel: {...Type.caption, color: Palette.textMuted},
  activeTabLabel: {color: Palette.text, fontFamily: 'Cairo-SemiBold'},
  pressed: {opacity: 0.72},
});
