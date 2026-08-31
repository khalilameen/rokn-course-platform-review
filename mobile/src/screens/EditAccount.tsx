import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import React, {useEffect, useMemo, useState} from 'react';
import {
  Alert,
  Image,
  type ImageSourcePropType,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import {launchImageLibrary} from 'react-native-image-picker';
import {useDispatch, useSelector} from 'react-redux';
import Button from '../components/touchables/Button';
import {Container, Content} from '../components/containers/Containers';
import {
  PremiumCard,
  ResponsiveFrame,
  StatusView,
} from '../components/ui/PremiumUI';
import HeaderWithBack from '../components/view/HeaderWithBack';
import {
  AsyncKeys,
  extractApiToken,
  extractUserProfile,
  getItem,
  saveItem,
} from '../constants/helpers';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
} from '../constants/designSystem';
import {saveLoginData} from '../store/reducers/auth';
import {
  getPortfolioProfile,
  getProfile,
  hasSession,
  updatePortfolioProfile,
  updateProfile,
} from '../services/roknApi';
import type {RootState} from '../store/store';
import {asRecord, errorPayload} from '../utils/errorPayload';

export default function EditAccount() {
  const navigation = useNavigation<RootNavigation>();
  const dispatch = useDispatch();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const storedSession = asRecord(storedUser) ?? {};
  const storedSessionData = asRecord(storedSession.data);
  const user = extractUserProfile(storedUser);
  const hasStoredToken = Boolean(extractApiToken(storedUser));
  const [name, setName] = useState(user.name ?? '');
  const [jobTitle, setJobTitle] = useState(
    !hasStoredToken && user.job_title === 'مصمم واجهات ومستقل'
      ? ''
      : user.job_title ?? '',
  );
  const [username, setUsername] = useState(
    user.portfolio_slug ?? user.username ?? '',
  );
  const [email, setEmail] = useState(user.email ?? '');
  const storedAvatar = user.avatar || user.profile_image;
  const [avatar, setAvatar] = useState<ImageSourcePropType>(
    storedAvatar
      ? {uri: storedAvatar}
      : require('../assets/images/default-avatar.png'),
  );
  const [avatarUpload, setAvatarUpload] = useState<
    {uri: string; type?: string; fileName?: string} | undefined
  >();
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [hydrationState, setHydrationState] = useState<
    'loading' | 'ready' | 'error'
  >('loading');
  const [reloadProfile, setReloadProfile] = useState(0);
  const [saving, setSaving] = useState(false);
  const normalizedUsername = useMemo(
    () =>
      username
        .trim()
        .replace(/^@/, '')
        .toLowerCase()
        .replace(/[._\s]+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, ''),
    [username],
  );
  const usernameValid =
    normalizedUsername.length >= 3 && normalizedUsername.length <= 30;

  useEffect(() => {
    let active = true;
    void (async () => {
      if (active) setHydrationState('loading');
      const sessionAvailable = await hasSession();
      if (!active) return;
      setServerSession(sessionAvailable);
      if (!sessionAvailable) {
        setHydrationState('error');
        return;
      }
      const [profileResult, portfolioResult] = await Promise.allSettled([
        getProfile(),
        getPortfolioProfile(),
      ]);
      if (active && profileResult.status === 'fulfilled') {
        const profile = profileResult.value;
        setName(profile.name);
        setJobTitle(profile.jobTitle);
        setEmail(profile.email);
        if (profile.avatar) setAvatar({uri: profile.avatar});
      }
      if (active && portfolioResult.status === 'fulfilled') {
        setUsername(portfolioResult.value.slug);
      }
      if (active) {
        setHydrationState(
          profileResult.status === 'fulfilled' &&
            portfolioResult.status === 'fulfilled'
            ? 'ready'
            : 'error',
        );
      }
    })();
    return () => {
      active = false;
    };
  }, [reloadProfile]);

  const chooseAvatar = async () => {
    const result = await launchImageLibrary({
      mediaType: 'photo',
      selectionLimit: 1,
      quality: 0.8,
    });
    const asset = result.assets?.[0];
    if (asset?.fileSize && asset.fileSize > 2 * 1024 * 1024) {
      Alert.alert('الصورة كبيرة', 'اختر صورة أصغر من ٢ ميجابايت');
      return;
    }
    if (asset?.uri) {
      setAvatar({uri: asset.uri});
      setAvatarUpload({
        uri: asset.uri,
        type: asset.type,
        fileName: asset.fileName,
      });
    }
  };

  const save = async () => {
    if (
      serverSession !== true ||
      hydrationState !== 'ready' ||
      !name.trim() ||
      !usernameValid
    )
      return;
    setSaving(true);
    try {
      let remoteAvatar = avatarUpload?.uri ?? storedAvatar;
      if (serverSession) {
        const [profile, portfolio] = await Promise.all([
          updateProfile({
            name: name.trim(),
            jobTitle: jobTitle.trim(),
            avatar: avatarUpload,
          }),
          updatePortfolioProfile({
            slug: normalizedUsername,
            headline: jobTitle.trim(),
          }),
        ]);
        remoteAvatar = profile.avatar || remoteAvatar;
        setUsername(portfolio.slug);
      }
      const updatedProfile = {
        ...user,
        name: name.trim(),
        job_title: jobTitle.trim(),
        username: normalizedUsername,
        portfolio_slug: normalizedUsername,
        avatar: remoteAvatar,
        profile_image: remoteAvatar,
      };
      const next = storedSession.user
        ? {...storedSession, user: updatedProfile}
        : storedSessionData?.user
        ? {
            ...storedSession,
            data: {...storedSessionData, user: updatedProfile},
          }
        : storedSessionData && !storedSession.name
        ? {...storedSession, data: {...storedSessionData, ...updatedProfile}}
        : {...storedSession, ...updatedProfile};
      const sessionSaved = await saveItem(AsyncKeys.USER_DATA, next);
      if (!sessionSaved) throw new Error('SESSION_STORAGE_UNAVAILABLE');
      const restoredSession = await getItem(AsyncKeys.USER_DATA);
      dispatch(saveLoginData(restoredSession ?? next));
      navigation.goBack();
    } catch (error: unknown) {
      const payload = errorPayload(error);
      const errors = asRecord(payload.errors);
      const firstError = errors
        ? String(
            Object.values(errors).flatMap(value =>
              Array.isArray(value) ? value : [value],
            )[0] || '',
          )
        : '';
      Alert.alert(
        'تعذّر حفظ التغييرات',
        firstError ||
          String(payload.message || 'تعديلاتك محفوظة\nحاول مرة أخرى'),
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <Container noPadding>
      <Content noPadding>
        <ResponsiveFrame style={styles.frame}>
          <HeaderWithBack title="بيانات الحساب" />
          {serverSession === false ? (
            <StatusView
              actionLabel="سجّل الدخول"
              description="بيانات الحساب مرتبطة بطريقة الدخول التي اخترتها"
              onAction={() => navigation.replace('Login')}
              state="error"
              title="سجّل الدخول لتعديل حسابك"
            />
          ) : hydrationState === 'error' ? (
            <StatusView
              actionLabel="إعادة المحاولة"
              description="لم نعرض نسخة قديمة كي لا تحفظها فوق بيانات حسابك."
              onAction={() => setReloadProfile(value => value + 1)}
              state="error"
              title="تعذّر تحديث بيانات الحساب"
            />
          ) : (
            <>
              <View style={styles.avatarArea}>
                <Image source={avatar} style={styles.avatar} />
                <Pressable
                  accessibilityRole="button"
                  onPress={chooseAvatar}
                  style={styles.changePhoto}>
                  <Text style={styles.changePhotoLabel}>تغيير الصورة</Text>
                </Pressable>
              </View>
              <PremiumCard style={styles.form}>
                <Text style={styles.label}>الاسم الظاهر</Text>
                <TextInput
                  onChangeText={setName}
                  style={styles.input}
                  value={name}
                />
                <Text style={styles.label}>المسمى المهني (اختياري)</Text>
                <TextInput
                  onChangeText={setJobTitle}
                  placeholder="مثال: مصمم منتجات رقمية"
                  placeholderTextColor={Palette.textFaint}
                  style={styles.input}
                  value={jobTitle}
                />
                <Text style={styles.label}>اسم المستخدم</Text>
                <View style={styles.usernameRow}>
                  <Text style={styles.at}>@</Text>
                  <TextInput
                    autoCapitalize="none"
                    onChangeText={setUsername}
                    style={[styles.input, styles.usernameInput]}
                    value={username}
                  />
                </View>
                <Text
                  style={[
                    styles.hint,
                    !!username && !usernameValid && styles.invalidHint,
                  ]}>
                  {username && !usernameValid
                    ? 'استخدم ٣–٣٠ حرفًا إنجليزيًا أو رقمًا أو شرطة فقط.'
                    : `سيكون رابطك العام rokn.app/@${
                        normalizedUsername || 'username'
                      }`}
                </Text>
                <Text style={styles.label}>البريد المرتبط بالحساب</Text>
                <View style={[styles.input, styles.readonly]}>
                  <Text numberOfLines={1} style={styles.readonlyText}>
                    {email || 'غير متاح'}
                  </Text>
                </View>
                <Text style={styles.hint}>
                  يتبع حساب Google أو TikTok أو Facebook الذي سجلت به.
                </Text>
              </PremiumCard>
              <Button
                disable={
                  hydrationState !== 'ready' || !name.trim() || !usernameValid
                }
                loader={saving}
                onPress={save}
                title="حفظ التغييرات"
              />
            </>
          )}
        </ResponsiveFrame>
      </Content>
    </Container>
  );
}

const styles = StyleSheet.create({
  frame: {maxWidth: 680},
  avatarArea: {alignItems: 'center', paddingVertical: Spacing.lg},
  avatar: {
    width: 92,
    height: 92,
    borderRadius: 46,
    borderWidth: 2,
    borderColor: Palette.line,
  },
  changePhoto: {
    minHeight: 44,
    justifyContent: 'center',
    paddingHorizontal: Spacing.md,
  },
  changePhotoLabel: {...Type.bodyStrong, color: '#8BB5FF'},
  form: {padding: Spacing.lg, marginBottom: Spacing.sm},
  label: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textMuted,
    marginTop: Spacing.sm,
    marginBottom: Spacing.xs,
  },
  input: {
    ...Type.body,
    ...textDirection,
    color: Palette.text,
    minHeight: 52,
    borderRadius: Radius.md,
    backgroundColor: Palette.surfaceRaised,
    borderWidth: 1,
    borderColor: Palette.line,
    paddingHorizontal: Spacing.md,
  },
  usernameRow: {flexDirection: 'row', alignItems: 'center'},
  at: {...Type.bodyStrong, color: Palette.textMuted, marginEnd: Spacing.xs},
  usernameInput: {flex: 1},
  hint: {
    ...Type.caption,
    ...textDirection,
    color: Palette.textFaint,
    marginTop: Spacing.xs,
  },
  invalidHint: {color: Palette.danger},
  readonly: {justifyContent: 'center', opacity: 0.72},
  readonlyText: {...Type.body, ...textDirection, color: Palette.textMuted},
});
