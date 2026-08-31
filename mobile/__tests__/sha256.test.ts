import {sha256Base64Url, sha256Hex} from '../src/utils/sha256';

describe('portable SHA-256', () => {
  it('matches the RFC 7636 PKCE S256 vector', () => {
    expect(
      sha256Base64Url(
        'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
      ),
    ).toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
  });

  it('matches the standard SHA-256 empty-string vector', () => {
    expect(sha256Hex('')).toBe(
      'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    );
  });
});
