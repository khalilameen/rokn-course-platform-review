import {
  accountDeletionCredential,
  socialProviderForSession,
} from '../src/services/accountDeletionReauth';
import type {SocialAuthSession} from '../src/services/socialAuth';

const session = (
  id: string | number,
  provider: 'facebook' | 'google' | 'tiktok' | 'apple',
  token = 'fresh-reauth-token',
): SocialAuthSession => ({
  api_token: token,
  user: {
    id,
    name: 'Rokn learner',
    email: null,
    social_provider: provider,
  },
});

describe('account deletion social reauthentication', () => {
  it('returns only the ephemeral token for the same user and provider', () => {
    expect(accountDeletionCredential(session(42, 'facebook', 'old'), session('42', 'facebook')))
      .toBe('fresh-reauth-token');
  });

  it('fails closed when OAuth switches account or provider', () => {
    expect(() => accountDeletionCredential(session(42, 'facebook'), session(99, 'facebook')))
      .toThrow('ACCOUNT_REAUTH_IDENTITY_MISMATCH');
    expect(() => accountDeletionCredential(session(42, 'facebook'), session(42, 'google')))
      .toThrow('ACCOUNT_REAUTH_IDENTITY_MISMATCH');
  });

  it('does not infer a deletion provider from email or other profile data', () => {
    expect(socialProviderForSession({user: {id: 42, email: 'learner@example.test'}}))
      .toBeNull();
  });
});
