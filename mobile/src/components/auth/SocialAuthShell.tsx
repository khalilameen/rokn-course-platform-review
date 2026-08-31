import {useNavigation, useRoute} from '@react-navigation/native';
import type {RouteProp} from '@react-navigation/native';
import type {RootNavigation} from '../../navigation/types';
import React, {useEffect, useState} from 'react';
import * as AppleAuthentication from 'expo-apple-authentication';
import {
  ActivityIndicator,
  Alert,
  Image,
  type ImageSourcePropType,
  Pressable,
  Platform,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {useDispatch, useSelector} from 'react-redux';
import Svg, {Path} from 'react-native-svg';
import {Container, Content} from '../containers/Containers';
import {ResponsiveFrame} from '../ui/PremiumUI';
import {
  Accessibility,
  Palette,
  Radius,
  Spacing,
  Type,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';
import {
  AsyncKeys,
  extractApiToken,
  getItem,
  removeItem,
  saveItem,
  getCurrentAccountStorageScope,
} from '../../constants/helpers';
import {LogOut, saveLoginData} from '../../store/reducers/auth';
import {
  signInWithSocialProvider,
  getSocialAuthMethods,
} from '../../services/socialAuth';
import type {
  SocialAuthMethods,
  SocialProvider,
} from '../../services/socialAuth';
import {
  socialAuthFailureCode,
  socialAuthMessage,
} from '../../services/socialAuthErrors';
import {reportClientError} from '../../services/operationalTelemetry';
import {
  LOGIN_RETURN_TO_PARAMLESS_ROUTES,
  type LoginRouteParams,
  type LoginReturnTo,
  type LoginReturnToParamlessRoute,
} from '../../navigation/types';
import {migrateGuestLearningState} from '../VideoPlayer/courseLearningApi';
import type {RootState} from '../../store/store';

type LoginRoute = RouteProp<{Login: LoginRouteParams}, 'Login'>;

const isParamlessReturnTo = (
  value: LoginReturnTo,
): value is Extract<LoginReturnTo, {name: LoginReturnToParamlessRoute}> =>
  LOGIN_RETURN_TO_PARAMLESS_ROUTES.includes(
    value.name as LoginReturnToParamlessRoute,
  );

const validReturnTo = (value?: LoginReturnTo): value is LoginReturnTo => {
  if (!value) return false;
  if (isParamlessReturnTo(value)) return value.params === undefined;
  return (
    (value.name === 'CourseDetails' || value.name === 'Reels') &&
    typeof value.params?.courseId === 'string' &&
    value.params.courseId.trim().length > 0
  );
};

const providers: Array<{
  id: SocialProvider;
  label: string;
  image?: ImageSourcePropType;
  brandMark?: 'tiktok';
}> = [
  {
    id: 'facebook',
    label: 'المتابعة بحساب Facebook',
    image: require('../../assets/images/facebook.png'),
  },
  {
    id: 'google',
    label: 'المتابعة بحساب Google',
    image: require('../../assets/images/google.png'),
  },
  {
    id: 'tiktok',
    label: 'المتابعة بحساب TikTok',
    brandMark: 'tiktok',
  },
  ...(Platform.OS === 'ios'
    ? ([
        {
          id: 'apple',
          label: 'المتابعة بحساب Apple',
        },
      ] as const)
    : []),
];

const TikTokMark = () => (
  <Svg accessibilityElementsHidden width={24} height={24} viewBox="0 0 24 24">
    <Path
      d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.03-.5-.04-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.45 3.98-2.14 6.15-1.74.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-2.98.42-.6.44-1.02 1.11-1.13 1.85-.09.53-.05 1.08.15 1.58.19.47.5.89.91 1.2.78.61 1.9.74 2.79.29.59-.3 1.03-.85 1.16-1.5.07-.25.05-.51.05-.76.01-4.25-.01-8.51.01-12.76z"
      fill="#FFFFFF"
    />
  </Svg>
);

export default function SocialAuthShell() {
  const navigation = useNavigation<RootNavigation>();
  const route = useRoute<LoginRoute>();
  const dispatch = useDispatch();
  const currentSession = useSelector((state: RootState) => state.auth.userData);
  const [loading, setLoading] = useState<SocialProvider | null>(null);
  // undefined means loading. null keeps the providers tappable so the chosen
  // button can retry discovery instead of turning one network error into a
  // dead-end screen.
  const [authMethods, setAuthMethods] = useState<
    SocialAuthMethods | null | undefined
  >(undefined);

  const finishAuthenticatedNavigation = () => {
    const returnTo = route.params?.returnTo;
    if (validReturnTo(returnTo)) {
      if (isParamlessReturnTo(returnTo)) {
        const navigationState = navigation.getState?.();
        const previousRoute =
          navigationState?.routes?.[
            Math.max(0, (navigationState?.index ?? 0) - 1)
          ];
        if (previousRoute?.name === returnTo.name && navigation.canGoBack?.()) {
          // Returning to the mounted screen also preserves local UI intent,
          // such as the selected Saved or Portfolio tab inside Profile.
          navigation.goBack();
          return;
        }
        navigation.reset({
          index: 1,
          routes: [{name: 'Home'}, {name: returnTo.name}],
        });
        return;
      }
      const targetParams =
        returnTo.name === 'CourseDetails'
          ? {
              ...returnTo.params,
              courseId: returnTo.params.courseId.trim(),
            }
          : {
              ...returnTo.params,
              courseId: returnTo.params.courseId.trim(),
            };
      navigation.reset({
        index: 1,
        routes: [
          {name: 'Home'},
          {
            name: returnTo.name,
            params: targetParams,
          },
        ],
      });
      return;
    }
    navigation.reset({index: 0, routes: [{name: 'Home'}]});
  };

  useEffect(() => {
    let active = true;
    getSocialAuthMethods()
      .then(methods => active && setAuthMethods(methods))
      .catch(() => active && setAuthMethods(null));
    return () => {
      active = false;
    };
  }, []);

  const continueWith = async (provider: SocialProvider) => {
    if (loading || (authMethods && !authMethods.providers.includes(provider))) {
      return;
    }
    setLoading(provider);
    try {
      const availableMethods = authMethods ?? (await getSocialAuthMethods());
      setAuthMethods(availableMethods);
      const session = await signInWithSocialProvider(
        provider,
        availableMethods,
      );
      const guestScope = extractApiToken(currentSession)
        ? null
        : await getCurrentAccountStorageScope();
      const sessionSaved = await saveItem(AsyncKeys.USER_DATA, session);
      if (!sessionSaved) throw new Error('SESSION_STORAGE_UNAVAILABLE');
      await saveItem(AsyncKeys.IS_LOGIN, true);
      if (guestScope) {
        await migrateGuestLearningState(guestScope);
      }
      const welcomeBonus = Number(session?.welcome_bonus_granted || 0);
      if (welcomeBonus > 0) {
        await saveItem(AsyncKeys.PENDING_WELCOME_BONUS, welcomeBonus);
      }
      const restoredSession = await getItem(AsyncKeys.USER_DATA);
      if (!extractApiToken(restoredSession)) {
        throw new Error('SESSION_STORAGE_UNAVAILABLE');
      }
      dispatch(saveLoginData(restoredSession));
      finishAuthenticatedNavigation();
    } catch (error) {
      const code = socialAuthFailureCode(error);
      if (code !== 'LOGIN_CANCELLED') {
        void reportClientError(new Error(code), {
          source: `auth.${provider}`,
        });
      }
      const message = socialAuthMessage(code);
      if (message) Alert.alert('تعذّر تسجيل الدخول', message);
    } finally {
      setLoading(null);
    }
  };

  const retryAuthMethods = async () => {
    setAuthMethods(undefined);
    try {
      setAuthMethods(await getSocialAuthMethods());
    } catch {
      setAuthMethods(null);
    }
  };

  const enterFreePreview = async () => {
    if (extractApiToken(currentSession)) {
      finishAuthenticatedNavigation();
      return;
    }
    // Guest browsing is explicit and never masquerades as a registered user.
    await removeItem(AsyncKeys.USER_DATA);
    await removeItem(AsyncKeys.IS_LOGIN);
    dispatch(LogOut());
    navigation.reset({index: 0, routes: [{name: 'Home'}]});
  };

  const recommendedProvider = authMethods?.recommendedProvider;
  const recommendationText = authMethods?.recommendationText || null;
  const providerOrder = [
    ...(recommendedProvider ? [recommendedProvider] : []),
    ...(authMethods?.providers ?? []),
  ].filter((value, index, list) => value && list.indexOf(value) === index);
  const visibleProviders = authMethods
    ? providers.filter(provider =>
        authMethods.providers.includes(provider.id),
      )
    : providers;
  const orderedProviders = authMethods
    ? [...visibleProviders].sort((first, second) => {
        const firstIndex = providerOrder.indexOf(first.id);
        const secondIndex = providerOrder.indexOf(second.id);
        if (firstIndex < 0 && secondIndex < 0) return 0;
        if (firstIndex < 0) return 1;
        if (secondIndex < 0) return -1;
        return firstIndex - secondIndex;
      })
    : providers;

  return (
    <Container noPadding>
      <Content
        noPadding
        contentContainerStyle={styles.scrollContent}
        paddingBottom={Spacing.xl}>
        <ResponsiveFrame style={styles.frame}>
          <View style={styles.hero}>
            <Image
              source={require('../../assets/images/authLogo.png')}
              style={styles.logo}
            />
            <Text style={styles.title}>سجّل دخولك إلى ركن</Text>
            <Text style={styles.subtitle}>
              احفظ تقدمك ومحفوظاتك وارجع لها في أي وقت
            </Text>
          </View>

          <View style={styles.providers}>
            {orderedProviders.map(provider => {
              const methodsAreLoading = authMethods === undefined;
              const disabled = Boolean(loading) || methodsAreLoading;

              if (provider.id === 'apple') {
                return (
                  <View
                    key={provider.id}
                    pointerEvents={disabled ? 'none' : 'auto'}
                    style={disabled ? styles.providerDisabled : undefined}>
                    <AppleAuthentication.AppleAuthenticationButton
                      buttonStyle={
                        AppleAuthentication.AppleAuthenticationButtonStyle.WHITE
                      }
                      buttonType={
                        AppleAuthentication.AppleAuthenticationButtonType
                          .CONTINUE
                      }
                      cornerRadius={14}
                      onPress={() => void continueWith('apple')}
                      style={styles.appleProvider}
                    />
                  </View>
                );
              }

              return (
                <View
                  key={provider.id}
                  style={
                    provider.id === recommendedProvider && recommendationText
                      ? styles.recommendedProviderWrap
                      : undefined
                  }>
                  {provider.id === recommendedProvider &&
                    recommendationText && (
                      <View
                        pointerEvents="none"
                        style={styles.recommendedBadge}>
                        <Text
                          adjustsFontSizeToFit
                          minimumFontScale={0.72}
                          numberOfLines={1}
                          style={styles.recommendedText}>
                          {recommendationText}
                        </Text>
                      </View>
                    )}
                  <Pressable
                    accessibilityLabel={provider.label}
                    accessibilityRole="button"
                    accessibilityState={{
                      busy: loading === provider.id,
                      disabled,
                    }}
                    disabled={disabled}
                    onPress={() => void continueWith(provider.id)}
                    style={({pressed}) => [
                      styles.provider,
                      provider.id === 'google' && styles.googleProvider,
                      provider.id === 'tiktok' && styles.tiktokProvider,
                      provider.id === 'facebook' && styles.facebookProvider,
                      ((loading && loading !== provider.id) ||
                        methodsAreLoading) &&
                        styles.providerDisabled,
                      pressed && styles.pressed,
                    ]}>
                    <View style={styles.providerIcon}>
                      {loading === provider.id ? (
                        <ActivityIndicator
                          color={
                            provider.id === 'google'
                              ? Palette.canvas
                              : '#FFFFFF'
                          }
                          size="small"
                        />
                      ) : provider.image ? (
                        <Image
                          source={provider.image}
                          style={styles.providerImage}
                        />
                      ) : provider.brandMark === 'tiktok' ? (
                        <TikTokMark />
                      ) : null}
                    </View>
                    <Text
                      style={[
                        styles.providerLabel,
                        provider.id === 'google' && styles.googleLabel,
                      ]}>
                      {provider.label}
                    </Text>
                  </Pressable>
                </View>
              );
            })}
          </View>

          {authMethods === undefined && (
            <View style={styles.authStatus}>
              <ActivityIndicator color={Palette.primary} size="small" />
              <Text style={styles.authStatusText}>نجهّز طرق الدخول</Text>
            </View>
          )}
          {authMethods === null && (
            <View style={styles.authStatus}>
              <Text style={styles.authStatusText}>تعذّر تحميل طرق الدخول</Text>
              <Pressable
                accessibilityRole="button"
                onPress={() => void retryAuthMethods()}
                style={styles.retryMethods}>
                <Text style={styles.retryMethodsText}>حاول مرة أخرى</Text>
              </Pressable>
            </View>
          )}
          {authMethods && authMethods.providers.length === 0 && (
            <View style={styles.authStatus}>
              <Text style={styles.authStatusText}>
                طرق تسجيل الدخول غير متاحة الآن
              </Text>
            </View>
          )}

          <Text style={styles.legal}>
            بالمتابعة أنت توافق على{' '}
            <Text
              onPress={() => navigation.navigate('TermsOfUse')}
              style={styles.legalLink}>
              شروط الاستخدام
            </Text>{' '}
            و
            <Text
              onPress={() => navigation.navigate('PrivacyPolicy')}
              style={styles.legalLink}>
              سياسة الخصوصية
            </Text>
          </Text>

          <Pressable
            accessibilityRole="button"
            onPress={() => void enterFreePreview()}
            style={({pressed}) => [
              styles.reviewButton,
              pressed && styles.pressed,
            ]}>
            <Text style={styles.reviewButtonText}>استكشف المحتوى المجاني</Text>
          </Pressable>
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  scrollContent: {flexGrow: 1, justifyContent: 'center'},
  frame: {
    maxWidth: 520,
    justifyContent: 'center',
    paddingHorizontal: Spacing.xl,
  },
  hero: {
    alignItems: 'center',
    paddingTop: Spacing.xl,
    paddingBottom: Spacing.xxl,
  },
  logo: {width: 92, height: 92, resizeMode: 'contain'},
  title: {
    ...Type.title,
    writingDirection: 'rtl',
    color: Palette.text,
    textAlign: 'center',
    marginTop: Spacing.lg,
  },
  subtitle: {
    ...Type.body,
    writingDirection: 'rtl',
    color: Palette.textMuted,
    textAlign: 'center',
    marginTop: Spacing.xs,
  },
  providers: {direction: 'rtl', gap: Spacing.sm},
  recommendedProviderWrap: {direction: 'rtl', paddingTop: 9},
  provider: {
    minHeight: 58,
    ...rtlRowStyle,
    alignItems: 'center',
    borderRadius: Radius.md,
    borderWidth: 1,
    paddingHorizontal: Spacing.md,
  },
  googleProvider: {backgroundColor: '#FFFFFF', borderColor: '#FFFFFF'},
  tiktokProvider: {backgroundColor: '#111111', borderColor: '#30343B'},
  facebookProvider: {backgroundColor: '#1877F2', borderColor: '#1877F2'},
  appleProvider: {width: '100%', height: 58},
  providerIcon: {
    width: Accessibility.minTouchTarget,
    height: Accessibility.minTouchTarget,
    alignItems: 'center',
    justifyContent: 'center',
  },
  providerImage: {width: 27, height: 27, resizeMode: 'contain'},
  providerLabel: {
    ...Type.bodyStrong,
    ...textDirection,
    color: '#FFFFFF',
    flex: 1,
    textAlign: 'center',
  },
  googleLabel: {color: '#202124'},
  recommendedBadge: {
    position: 'absolute',
    zIndex: 2,
    top: 0,
    end: 14,
    maxWidth: '86%',
    minHeight: 20,
    justifyContent: 'center',
    borderRadius: Radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 1,
    backgroundColor: '#682F39',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: '#B97862',
  },
  recommendedText: {
    fontFamily: 'Cairo-SemiBold',
    fontSize: 8.5,
    lineHeight: 13,
    direction: 'rtl',
    writingDirection: 'rtl',
    textAlign: 'center',
    color: '#FFE9DF',
    flexShrink: 1,
  },
  legal: {
    ...Type.caption,
    writingDirection: 'rtl',
    color: Palette.textFaint,
    textAlign: 'center',
    marginTop: Spacing.xl,
  },
  legalLink: {color: '#8BB5FF', textDecorationLine: 'underline'},
  authStatus: {
    minHeight: 32,
    ...rtlRowStyle,
    flexWrap: 'wrap',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 7,
    marginTop: Spacing.sm,
  },
  authStatusText: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    textAlign: 'center',
  },
  retryMethods: {
    minHeight: 32,
    justifyContent: 'center',
    paddingHorizontal: Spacing.sm,
    borderRadius: Radius.pill,
    backgroundColor: Palette.primarySoft,
  },
  retryMethodsText: {...Type.caption, color: '#8BB5FF'},
  reviewButton: {
    alignSelf: 'center',
    minHeight: Accessibility.minTouchTarget,
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
    marginTop: Spacing.sm,
  },
  reviewButtonText: {...Type.caption, color: Palette.textMuted},
  providerDisabled: {opacity: 0.46},
  pressed: {opacity: 0.78, transform: [{scale: 0.99}]},
});
