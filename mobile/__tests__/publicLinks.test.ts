import {
  trustedCertificateVerificationUrl,
  trustedPortfolioShareUrl,
} from '../src/services/publicLinks';

describe('public share link trust boundary', () => {
  const deployment =
    'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud';
  const slug = 'rokn-aaaaaaaaaaaaaaaaaaaaaaaa';
  const credential = '11111111-1111-4111-8111-111111111111';

  it('accepts canonical and configured API-origin portfolio links', () => {
    expect(trustedPortfolioShareUrl(`https://rokn.app/@${slug}`)).toBeTruthy();
    expect(trustedPortfolioShareUrl(`${deployment}/@${slug}`)).toBeTruthy();
  });

  it('keeps lookalike hosts and malformed capabilities out', () => {
    expect(
      trustedPortfolioShareUrl(`https://rokn.app.evil.example/@${slug}`),
    ).toBeNull();
    expect(trustedPortfolioShareUrl(`${deployment}/@student-6`)).toBeNull();
  });

  it('uses the same deployment trust boundary for certificate verification', () => {
    expect(
      trustedCertificateVerificationUrl(
        `${deployment}/c/${credential}`,
        credential,
      ),
    ).toBeTruthy();
    expect(
      trustedCertificateVerificationUrl(
        `https://example.com/c/${credential}`,
        credential,
      ),
    ).toBeNull();
  });
});
