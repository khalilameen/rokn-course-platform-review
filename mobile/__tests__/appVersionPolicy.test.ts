import {
  createVersionCheckPayload,
  parseAppVersionResponse,
  trustedUpdateUrl,
} from '../src/services/appVersionPolicy';

describe('app update policy', () => {
  it('sends Android versionCode and iOS version plus build_number', () => {
    expect(
      createVersionCheckPayload({
        platform: 'android',
        version: '1.0.14',
        androidVersionCode: 15,
        iosBuildNumber: '14',
        distributionChannel: 'play',
      }),
    ).toEqual({
      platform: 'android',
      version: 15,
      distribution_channel: 'play',
    });
    expect(
      createVersionCheckPayload({
        platform: 'ios',
        version: '1.0.14',
        androidVersionCode: 15,
        iosBuildNumber: '14',
        distributionChannel: 'appstore',
      }),
    ).toEqual({
      platform: 'ios',
      version: '1.0.14',
      build_number: 14,
      distribution_channel: 'appstore',
    });
  });

  it('rejects a distribution channel that cannot belong to the platform', () => {
    expect(
      createVersionCheckPayload({
        platform: 'android',
        version: '1.0.15',
        androidVersionCode: 16,
        iosBuildNumber: '15',
        distributionChannel: 'appstore',
      }),
    ).toBeNull();
    expect(
      createVersionCheckPayload({
        platform: 'ios',
        version: '1.0.15',
        androidVersionCode: 16,
        iosBuildNumber: '15',
        distributionChannel: 'direct',
      }),
    ).toBeNull();
  });

  it.each([
    ['play', 'https://play.google.com/store/apps/details?id=com.rokn'],
    ['appstore', 'https://apps.apple.com/eg/app/rokn/id123'],
    ['direct', 'https://rokn.app/downloads/Rokn.apk'],
    ['direct', 'https://www.rokn.com/releases/Rokn.apk'],
  ] as const)('allows the %s channel store host', (channel, url) => {
    expect(trustedUpdateUrl(url, channel)).toBe(url);
  });

  it.each([
    ['play', 'https://rokn.app/Rokn.apk'],
    ['play', 'https://play.google.com.evil.example/store/apps/com.rokn'],
    ['appstore', 'https://itunes.apple.com/app/rokn'],
    ['direct', 'https://cdn.example/Rokn.apk'],
    ['direct', 'http://rokn.app/Rokn.apk'],
  ] as const)('rejects an unsafe %s channel URL', (channel, url) => {
    expect(trustedUpdateUrl(url, channel)).toBeNull();
  });

  it('maps the exact backend fields and blocks an actionable forced update', () => {
    expect(
      parseAppVersionResponse(
        {
          data: {
            update_required: true,
            is_force_update: true,
            latest_version: '1.0.15',
            update_message: 'نسخة أحدث وأخف',
            download_url:
              'https://play.google.com/store/apps/details?id=com.rokn',
            release_notes: 'تحسين تشغيل الفيديو',
          },
        },
        'play',
      ),
    ).toMatchObject({
      latestVersion: '1.0.15',
      message: 'نسخة أحدث وأخف',
      isBlocking: true,
      hasUnsafeDownloadUrl: false,
    });
  });

  it('does not brick launch when a forced update has an unsafe URL', () => {
    expect(
      parseAppVersionResponse(
        {
          data: {
            update_required: true,
            is_force_update: true,
            download_url: 'https://evil.example/Rokn.apk',
          },
        },
        'direct',
      ),
    ).toMatchObject({
      downloadUrl: null,
      isBlocking: false,
      hasUnsafeDownloadUrl: true,
    });
  });

  it('stays silent when the backend says no update is required', () => {
    expect(
      parseAppVersionResponse(
        {data: {update_required: false, is_force_update: false}},
        'play',
      ),
    ).toBeNull();
  });
});
