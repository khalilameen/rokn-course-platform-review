import type {SocialAuthSession, SocialProvider} from './socialAuth';
import {extractUserProfile} from './secureSession';

const providers: SocialProvider[] = ['google', 'tiktok', 'facebook', 'apple'];
const isSocialProvider = (value: string): value is SocialProvider =>
  providers.includes(value as SocialProvider);

const userId = (session: unknown) => {
  const profile = extractUserProfile(session);
  const value = profile.id ?? profile.user_id;
  return typeof value === 'string' || typeof value === 'number'
    ? String(value).trim()
    : '';
};
const provider = (session: unknown): SocialProvider | null => {
  const value = extractUserProfile(session).social_provider;
  const normalized =
    typeof value === 'string' ? value.trim().toLowerCase() : '';
  return isSocialProvider(normalized) ? normalized : null;
};

/** Return the fresh bearer only after matching the exact current identity. */
export const accountDeletionCredential = (
  currentSession: unknown,
  reauthenticatedSession: SocialAuthSession,
): string => {
  const currentUserId = userId(currentSession);
  const reauthenticatedUserId = userId(reauthenticatedSession);
  const currentProvider = provider(currentSession);
  const reauthenticatedProvider = provider(reauthenticatedSession);
  if (
    !currentUserId ||
    !reauthenticatedUserId ||
    currentUserId !== reauthenticatedUserId ||
    !currentProvider ||
    currentProvider !== reauthenticatedProvider
  ) {
    throw new Error('ACCOUNT_REAUTH_IDENTITY_MISMATCH');
  }

  const token = reauthenticatedSession.api_token?.trim();
  if (!token) throw new Error('ACCOUNT_REAUTH_TOKEN_MISSING');
  return token;
};

export const socialProviderForSession = (
  session: unknown,
): SocialProvider | null => provider(session);
