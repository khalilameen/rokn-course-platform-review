import {
  socialAuthFailureCode,
  socialAuthMessage,
} from '../src/services/socialAuthErrors';

describe('social auth failure presentation', () => {
  it('preserves only the backend diagnostic code', () => {
    expect(
      socialAuthFailureCode({
        response: {
          status: 422,
          data: {
            code: 'social_login_pkce_mismatch',
            message: 'free-form provider response must stay private',
          },
        },
      }),
    ).toBe('SOCIAL_LOGIN_PKCE_MISMATCH');

    expect(
      socialAuthFailureCode({
        status: 410,
        data: {code: 'social_login_expired'},
      }),
    ).toBe('SOCIAL_LOGIN_EXPIRED');
  });

  it('distinguishes network and provider failures', () => {
    expect(socialAuthFailureCode({code: 'ERR_NETWORK'})).toBe(
      'NETWORK_UNAVAILABLE',
    );
    expect(
      socialAuthFailureCode({response: {status: 503, data: {}}}),
    ).toBe('PROVIDER_UNAVAILABLE');
  });

  it('keeps learner copy short and hides cancelled attempts', () => {
    expect(socialAuthMessage('LOGIN_CANCELLED')).toBe('');
    expect(socialAuthMessage('SOCIAL_LOGIN_EXPIRED')).toBe(
      'انتهت محاولة الدخول\nحاول مرة أخرى',
    );
    expect(socialAuthMessage('LOGIN_FAILED')).toBe(
      'لم يكتمل تسجيل الدخول\nحاول مرة أخرى',
    );
    expect(socialAuthMessage('LOGIN_BROWSER_UNAVAILABLE')).toBe(
      'تعذّر فتح صفحة الدخول\nحاول مرة أخرى',
    );
  });
});
