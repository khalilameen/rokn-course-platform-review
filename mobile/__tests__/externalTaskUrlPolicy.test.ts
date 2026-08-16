import {trustedExternalTaskUrl} from '../src/services/externalTaskUrlPolicy';

describe('external reward-task URL policy', () => {
  it.each([
    'https://www.instagram.com/rokn.app',
    'https://m.facebook.com/rokn',
    'https://www.tiktok.com/@rokn.app',
    'https://youtube.com/@rokn',
    'https://youtu.be/abc123',
    'https://learn.rokn.app/tasks/rules',
  ])('allows intended HTTPS task destination %s', url => {
    expect(trustedExternalTaskUrl(url)).toBe(url);
  });

  it.each([
    'instagram://user?username=rokn',
    'http://instagram.com/rokn',
    'https://instagram.com.evil.example/rokn',
    'https://evil.example/?next=https://youtube.com',
    'https://user:pass@youtube.com/watch?v=abc',
    'not a URL',
  ])('rejects untrusted task destination %s', url => {
    expect(trustedExternalTaskUrl(url)).toBeNull();
  });
});
