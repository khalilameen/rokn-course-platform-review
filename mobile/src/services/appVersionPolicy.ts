import type {DistributionChannel} from '../constants/distribution';

export type AppUpdateNotice = {
  latestVersion: string | null;
  message: string;
  releaseNotes: string | null;
  downloadUrl: string | null;
  isBlocking: boolean;
  hasUnsafeDownloadUrl: boolean;
};

export type VersionCheckPayload =
  | {
      platform: 'android';
      version: number;
      distribution_channel: 'play' | 'direct';
    }
  | {
      platform: 'ios';
      version: string;
      build_number: number;
      distribution_channel: 'appstore';
    };

type ParsedUrl = URL & {
  protocol: string;
  hostname: string;
  username: string;
  password: string;
};

const cleanText = (value: unknown, maxLength: number) =>
  typeof value === 'string' && value.trim()
    ? value.trim().replace(/\s+/g, ' ').slice(0, maxLength)
    : null;

const asRecord = (value: unknown): Record<string, unknown> | null =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;

export const createVersionCheckPayload = ({
  platform,
  version,
  androidVersionCode,
  iosBuildNumber,
  distributionChannel,
}: {
  platform: string;
  version: unknown;
  androidVersionCode: unknown;
  iosBuildNumber: unknown;
  distributionChannel: DistributionChannel;
}): VersionCheckPayload | null => {
  if (platform === 'android') {
    const versionCode = Number(androidVersionCode);
    return Number.isInteger(versionCode) &&
      versionCode > 0 &&
      (distributionChannel === 'play' || distributionChannel === 'direct')
      ? {
          platform,
          version: versionCode,
          distribution_channel: distributionChannel,
        }
      : null;
  }
  if (platform === 'ios') {
    const versionName = typeof version === 'string' ? version.trim() : '';
    const buildNumber = Number(iosBuildNumber);
    return versionName &&
      Number.isInteger(buildNumber) &&
      buildNumber > 0 &&
      distributionChannel === 'appstore'
      ? {
          platform,
          version: versionName,
          build_number: buildNumber,
          distribution_channel: distributionChannel,
        }
      : null;
  }
  return null;
};

export const trustedUpdateUrl = (
  value: unknown,
  channel: DistributionChannel,
) => {
  if (typeof value !== 'string' || !value.trim()) return null;
  try {
    const url = new URL(value.trim()) as unknown as ParsedUrl;
    if (url.protocol !== 'https:' || url.username || url.password) {
      return null;
    }

    const allowedHosts: Record<DistributionChannel, readonly string[]> = {
      play: ['play.google.com'],
      appstore: ['apps.apple.com'],
      direct: ['rokn.app', 'www.rokn.app', 'rokn.com', 'www.rokn.com'],
    };
    return allowedHosts[channel].includes(url.hostname.toLowerCase())
      ? url.toString()
      : null;
  } catch {
    return null;
  }
};

/** Maps the AppVersionController response after URL validation. */
export const parseAppVersionResponse = (
  payload: unknown,
  channel: DistributionChannel,
): AppUpdateNotice | null => {
  const envelope = asRecord(payload) ?? {};
  const nested = asRecord(envelope.data);
  const data = asRecord(nested?.data) ?? nested ?? envelope;
  if (data.update_required !== true) return null;

  const rawDownloadUrl = cleanText(data.download_url, 2048);
  const downloadUrl = trustedUpdateUrl(rawDownloadUrl, channel);
  const requestedForceUpdate = data.is_force_update === true;

  return {
    latestVersion: cleanText(data.latest_version, 40),
    message:
      cleanText(data.update_message, 240) || 'في نسخة أحدث من ركن جاهزة ليك',
    releaseNotes: cleanText(data.release_notes, 600),
    downloadUrl,
    // Invalid download URLs cannot activate a blocking screen. The policy
    // remains visible as a recoverable configuration warning.
    isBlocking: requestedForceUpdate && downloadUrl !== null,
    hasUnsafeDownloadUrl: rawDownloadUrl !== null && downloadUrl === null,
  };
};
