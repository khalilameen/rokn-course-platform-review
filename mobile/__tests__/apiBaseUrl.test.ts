import {normalizeRoknApiUrl} from '../src/constants/apiBaseUrl';

describe('normalizeRoknApiUrl', () => {
  it('adds the Rokn API path to a bare production origin', () => {
    expect(
      normalizeRoknApiUrl(
        'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud',
      ),
    ).toBe(
      'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/',
    );
  });

  it('keeps a complete API base stable', () => {
    expect(normalizeRoknApiUrl('https://rokn.app/api/v1/')).toBe(
      'https://rokn.app/api/v1/',
    );
  });

  it('completes an API root without a version', () => {
    expect(normalizeRoknApiUrl('https://rokn.app/api')).toBe(
      'https://rokn.app/api/v1/',
    );
  });
});
