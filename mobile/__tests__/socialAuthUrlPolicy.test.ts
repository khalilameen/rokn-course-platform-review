import {resolveSocialAuthStartUrl} from '../src/services/socialAuthUrlPolicy';

const apiBase = 'https://rokn.app/api/v1/';

describe('social OAuth start URL policy', () => {
  it('accepts the matching provider endpoint on the configured API origin', () => {
    expect(
      resolveSocialAuthStartUrl(
        'https://rokn.app/api/v1/social-auth/facebook/start?campaign=welcome',
        apiBase,
        'facebook',
      ),
    ).toBe(
      'https://rokn.app/api/v1/social-auth/facebook/start?campaign=welcome',
    );
  });

  it.each([
    'http://rokn.app/api/v1/social-auth/google/start',
    'https://evil.example/social-auth/google/start',
    'https://rokn.app/api/v1/social-auth/facebook/start',
    'https://rokn.app/api/v1/social-auth/google/start#redirect',
    'not a URL',
  ])('rejects %s and derives the trusted endpoint', configuredUrl => {
    expect(
      resolveSocialAuthStartUrl(configuredUrl, apiBase, 'google'),
    ).toBe('https://rokn.app/api/v1/social-auth/google/start');
  });

  it('fails closed when the configured API origin itself is not HTTPS', () => {
    expect(() =>
      resolveSocialAuthStartUrl(undefined, 'http://rokn.app/api/v1/', 'tiktok'),
    ).toThrow('AUTH_ORIGIN_INVALID');
  });
});
