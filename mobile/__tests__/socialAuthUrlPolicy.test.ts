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

  it('accepts an independent HTTPS host only when discovery declares that exact API base', () => {
    expect(
      resolveSocialAuthStartUrl(
        'https://identity.rokn.app/api/v1/social-auth/tiktok/start',
        apiBase,
        'tiktok',
        'https://identity.rokn.app/api/v1',
      ),
    ).toBe('https://identity.rokn.app/api/v1/social-auth/tiktok/start');
    expect(
      resolveSocialAuthStartUrl(
        'https://identity.rokn.app/api/v1/social-auth/tiktok/start',
        apiBase,
        'tiktok',
      ),
    ).toBe('');
  });

  it.each([
    'http://rokn.app/api/v1/social-auth/google/start',
    'https://evil.example/social-auth/google/start',
    'https://rokn.app/api/v1/social-auth/facebook/start',
    'https://rokn.app/api/v1/social-auth/google/start#redirect',
    'https://user:secret@rokn.app/api/v1/social-auth/google/start',
    'https://rokn.app/api/v2/social-auth/google/start',
    'not a URL',
  ])('rejects malformed, credentialed, or mismatched URL %s', configuredUrl => {
    expect(
      resolveSocialAuthStartUrl(configuredUrl, apiBase, 'google'),
    ).toBe('');
  });

  it('fails closed when the configured API origin itself is not HTTPS', () => {
    expect(() =>
      resolveSocialAuthStartUrl(undefined, 'http://rokn.app/api/v1/', 'tiktok'),
    ).toThrow('AUTH_ORIGIN_INVALID');
  });

  it.each([
    'http://identity.rokn.app/api/v1',
    'https://user:secret@identity.rokn.app/api/v1',
    'https://identity.rokn.app/api/v2',
  ])('fails closed for an invalid advertised API base %s', advertised => {
    expect(() =>
      resolveSocialAuthStartUrl(
        'https://identity.rokn.app/api/v1/social-auth/google/start',
        apiBase,
        'google',
        advertised,
      ),
    ).toThrow(/AUTH_DISCOVERY_/);
  });
});
