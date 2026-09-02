import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import React, {useEffect, useMemo, useRef, useState} from 'react';
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
  getCurrentAccountStorageScope,
  getItem,
} from '../constants/helpers';
import {
  Palette,
  Radius,
  Spacing,
  Type,
  textDirection,
} from '../constants/designSystem';
import {saveLoginData} from '../store/reducers/auth';
import {updateSecureSessionForOwner} from '../services/secureSession';
import {
  getPortfolioProfile,
  getProfile,
  hasSession,
  updatePortfolioProfile,
  updateProfile,
} from '../services/roknApi';
import type {RootState} from '../store/store';
import {asRecord, learnerErrorMessage} from '../utils/errorPayload';
import {
  cacheLearnerDraftFile,
  removeLearnerDraftFile,
} from '../services/learnerDraftFiles';
import {secureRandomUuid} from '../utils/secureRandom';
import {showMediaPickerFailure} from '../services/mediaPickerErrors';

export default function EditAccount() {
  const navigation = useNavigation<RootNavigation>();
  const dispatch = useDispatch();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const user = extractUserProfile(storedUser);
  const hasStoredToken = Boolean(extractApiToken(storedUser));
  const identityKey = hasStoredToken
    ? String(user.id ?? user.user_id ?? 'authenticated')
    : 'guest';
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
    {uri: string; type?: string; fileName?: string; size?: number} | undefined
  >();
  const [profileRevision, setProfileRevision] = useState(0);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [hydrationState, setHydrationState] = useState<
    'loading' | 'ready' | 'error'
  >('loading');
  const [reloadProfile, setReloadProfile] = useState(0);
  const [saving, setSaving] = useState(false);
  const mountedRef = useRef(true);
  const saveFlightRef = useRef(false);
  const pickerFlightRef = useRef(false);
  const identityRef = useRef(identityKey);
  const avatarUploadRef = useRef(avatarUpload);
  avatarUploadRef.current = avatarUpload;
  const profileRequestRef = useRef<{fingerprint: string; id: string} | null>(
    null,
  );
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
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      if (!saveFlightRef.current) {
        void removeLearnerDraftFile(avatarUploadRef.current);
      }
    };
  }, []);

  useEffect(() => {
    if (identityRef.current === identityKey) return;
    identityRef.current = identityKey;
    const staleDraft = avatarUploadRef.current;
    avatarUploadRef.current = undefined;
    setAvatarUpload(undefined);
    profileRequestRef.current = null;
    setName('');
    setJobTitle('');
    setUsername('');
    setEmail('');
    setAvatar(require('../assets/images/default-avatar.png'));
    setProfileRevision(0);
    setServerSession(null);
    void removeLearnerDraftFile(staleDraft);
  }, [identityKey]);

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
      const accountScope = await getCurrentAccountStorageScope();
      const [profileResult, portfolioResult] = await Promise.allSettled([
        getProfile(),
        getPortfolioProfile(),
      ]);
      if (!active || (await getCurrentAccountStorageScope()) !== accountScope) {
        return;
      }
      if (active && profileResult.status === 'fulfilled') {
        const profile = profileResult.value;
        setName(profile.name);
        setJobTitle(profile.jobTitle);
        setEmail(profile.email);
        if (profile.portfolioSlug) setUsername(profile.portfolioSlug);
        if (profile.avatar) setAvatar({uri: profile.avatar});
        setProfileRevision(profile.profileRevision);
      }
      if (active && portfolioResult.status === 'fulfilled') {
        setUsername(portfolioResult.value.slug);
      }
      if (active) {
        setHydrationState(
          profileResult.status === 'fulfilled' &&
            (Boolean(profileResult.value.portfolioSlug) ||
              portfolioResult.status === 'fulfilled')
            ? 'ready'
            : 'error',
        );
      }
    })();
    return () => {
      active = false;
    };
  }, [identityKey, reloadProfile]);

  const chooseAvatar = async () => {
    if (pickerFlightRef.current || saving) return;
    pickerFlightRef.current = true;
    try {
      const result = await launchImageLibrary({
        mediaType: 'photo',
        selectionLimit: 1,
        quality: 0.8,
      });
      if (!mountedRef.current) return;
      if (result.errorCode === 'permission') {
        showMediaPickerFailure(result.errorCode);
        return;
      }
      if (result.errorCode) {
        showMediaPickerFailure(result.errorCode);
        return;
      }
      const asset = result.assets?.[0];
      if (asset?.fileSize && asset.fileSize > 2 * 1024 * 1024) {
        Alert.alert('الصورة كبيرة', 'اختر صورة أصغر من ٢ ميجابايت');
        return;
      }
      if (asset?.uri) {
        const cached = await cacheLearnerDraftFile(
          'avatar',
          {
            uri: asset.uri,
            type: asset.type,
            fileName: asset.fileName,
            size: asset.fileSize,
          },
          2 * 1024 * 1024,
        );
        if (!mountedRef.current) {
          await removeLearnerDraftFile(cached);
          return;
        }
        const previous = avatarUpload;
        setAvatar({uri: cached.uri});
        setAvatarUpload(cached);
        profileRequestRef.current = null;
        await removeLearnerDraftFile(previous);
      }
    } catch (error: unknown) {
      if (mountedRef.current) {
        showMediaPickerFailure(
          typeof error === 'object' && error && 'errorCode' in error
            ? String(error.errorCode)
            : undefined,
        );
      }
    } finally {
      pickerFlightRef.current = false;
    }
  };

  const save = async () => {
    if (
      serverSession !== true ||
      hydrationState !== 'ready' ||
      !name.trim() ||
      !usernameValid ||
      saveFlightRef.current
    )
      return;
    saveFlightRef.current = true;
    setSaving(true);
    let remoteProfileSaved = false;
    try {
      const accountScope = await getCurrentAccountStorageScope();
      const sessionAtStart = await getItem(AsyncKeys.USER_DATA);
      const ownerAtStart = extractUserProfile(sessionAtStart);
      const expectedOwner = String(
        ownerAtStart.id ?? ownerAtStart.user_id ?? '',
      ).trim();
      if (!expectedOwner) {
        throw new Error('PROFILE_SESSION_OWNER_UNAVAILABLE');
      }
      let remoteAvatar = storedAvatar;
      if (serverSession) {
        const requestFingerprint = JSON.stringify([
          name.trim(),
          jobTitle.trim(),
          normalizedUsername,
          avatarUpload?.uri || '',
          avatarUpload?.size || 0,
          profileRevision,
        ]);
        if (profileRequestRef.current?.fingerprint !== requestFingerprint) {
          profileRequestRef.current = {
            fingerprint: requestFingerprint,
            id: secureRandomUuid(),
          };
        }
        const profile = await updateProfile({
          name: name.trim(),
          jobTitle: jobTitle.trim(),
          avatar: avatarUpload,
          portfolioSlug: normalizedUsername,
          portfolioHeadline: jobTitle.trim(),
          clientRequestId: profileRequestRef.current.id,
          expectedProfileRevision: profileRevision,
        });
        // During a rolling deploy an older API may ignore the new multipart
        // fields. Keep that short transition functional, while the current API
        // saves the whole form atomically in the request above.
        const portfolio =
          profile.portfolioSlug === normalizedUsername
            ? {
                slug: profile.portfolioSlug,
                headline: profile.portfolioHeadline,
              }
            : await updatePortfolioProfile({
                slug: normalizedUsername,
                headline: jobTitle.trim(),
              });
        if (avatarUpload && !profile.avatar) {
          throw new Error('PROFILE_AVATAR_NOT_PERSISTED');
        }
        remoteAvatar = profile.avatar || remoteAvatar;
        setProfileRevision(profile.profileRevision);
        setUsername(portfolio.slug);
        remoteProfileSaved = true;
      }
      if ((await getCurrentAccountStorageScope()) !== accountScope) {
        throw new Error('ACCOUNT_CHANGED_DURING_PROFILE_UPDATE');
      }
      const next = await updateSecureSessionForOwner(
        expectedOwner,
        activeSession => {
          const activeRecord = asRecord(activeSession) ?? {};
          const activeData = asRecord(activeRecord.data);
          const activeUser = extractUserProfile(activeSession);
          const updatedProfile = {
            ...activeUser,
            name: name.trim(),
            job_title: jobTitle.trim(),
            username: normalizedUsername,
            portfolio_slug: normalizedUsername,
            avatar: remoteAvatar,
            profile_image: remoteAvatar,
          };
          return activeRecord.user
            ? {...activeRecord, user: updatedProfile}
            : activeData?.user
            ? {
                ...activeRecord,
                data: {...activeData, user: updatedProfile},
              }
            : activeData && !activeRecord.name
            ? {...activeRecord, data: {...activeData, ...updatedProfile}}
            : {...activeRecord, ...updatedProfile};
        },
      );
      dispatch(saveLoginData(next));
      profileRequestRef.current = null;
      await removeLearnerDraftFile(avatarUpload).catch(() => undefined);
      if (mountedRef.current) {
        setAvatarUpload(undefined);
        navigation.goBack();
      }
    } catch (error: unknown) {
      if (mountedRef.current) {
        if (remoteProfileSaved) {
          profileRequestRef.current = null;
          setReloadProfile(value => value + 1);
          await removeLearnerDraftFile(avatarUpload).catch(() => undefined);
          setAvatarUpload(undefined);
          Alert.alert(
            'حُفظت التغييرات',
            'سيُحدّث الحساب تلقائيًا',
          );
        } else {
          Alert.alert(
            'تعذّر حفظ التغييرات',
            learnerErrorMessage(error, 'لم تكتمل التغييرات\nحاول مرة أخرى'),
          );
        }
      }
    } finally {
      saveFlightRef.current = false;
      if (mountedRef.current) setSaving(false);
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
              onAction={() =>
                navigation.replace('Login', {
                  returnTo: {name: 'EditAccount'},
                })
              }
              state="error"
              title="سجّل الدخول لتعديل حسابك"
            />
          ) : hydrationState === 'error' ? (
            <StatusView
              actionLabel="إعادة المحاولة"
              description="حاول مرة أخرى"
              onAction={() => setReloadProfile(value => value + 1)}
              state="error"
              title="تعذّر تحديث بيانات الحساب"
            />
          ) : (
            <>
              <View style={styles.avatarArea}>
                <Image
                  accessibilityLabel="صورة الحساب"
                  onError={() =>
                    setAvatar(require('../assets/images/default-avatar.png'))
                  }
                  source={avatar}
                  style={styles.avatar}
                />
                <Pressable
                  accessibilityLabel="تغيير صورة الحساب"
                  accessibilityRole="button"
                  onPress={chooseAvatar}
                  style={styles.changePhoto}>
                  <Text style={styles.changePhotoLabel}>تغيير الصورة</Text>
                </Pressable>
              </View>
              <PremiumCard style={styles.form}>
                <Text style={styles.label}>الاسم الظاهر</Text>
                <TextInput
                  accessibilityLabel="الاسم الظاهر"
                  autoCapitalize="words"
                  onChangeText={setName}
                  style={styles.input}
                  value={name}
                />
                <Text style={styles.label}>المسمى المهني (اختياري)</Text>
                <TextInput
                  accessibilityLabel="المسمى المهني"
                  onChangeText={setJobTitle}
                  placeholder="مصمم منتجات رقمية"
                  placeholderTextColor={Palette.textFaint}
                  style={styles.input}
                  value={jobTitle}
                />
                <Text style={styles.label}>اسم المستخدم</Text>
                <View style={styles.usernameRow}>
                  <Text style={styles.at}>@</Text>
                  <TextInput
                    accessibilityLabel="اسم المستخدم"
                    autoCapitalize="none"
                    autoCorrect={false}
                    onChangeText={setUsername}
                    style={[styles.input, styles.usernameInput]}
                    value={username}
                  />
                </View>
                <Text
                  accessibilityLiveRegion="polite"
                  style={[
                    styles.hint,
                    !!username && !usernameValid && styles.invalidHint,
                  ]}>
                  {username && !usernameValid
                    ? 'استخدم من ٣ إلى ٣٠ حرفًا إنجليزيًا أو رقمًا أو شرطة'
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
                  يتبع حساب Google أو TikTok أو Facebook الذي سجلت به
                </Text>
              </PremiumCard>
              <Button
                disable={
                  saving ||
                  hydrationState !== 'ready' ||
                  !name.trim() ||
                  !usernameValid
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
