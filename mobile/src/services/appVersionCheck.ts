import {Platform} from 'react-native';
import appConfig from '../../app.json';
import {publicRequest, type RoknRequestConfig} from '../constants/api';
import {DISTRIBUTION_CHANNEL} from '../constants/distribution';
import {
  AppUpdateNotice,
  createVersionCheckPayload,
  parseAppVersionResponse,
} from './appVersionPolicy';
import {getItem, saveItem} from '../constants/helpers';
import {serverNowMs} from '../utils/serverClock';

type UpdateDismissal = {
  identity: string;
  dismissedAt: number;
};

const OPTIONAL_UPDATE_COOLDOWN_MS = 72 * 60 * 60 * 1000;
const UPDATE_DISMISSAL_KEY = '@rokn/app-update-dismissal/v1';
let memoryDismissal: UpdateDismissal | null = null;

const noticeIdentity = (notice: AppUpdateNotice) =>
  [
    DISTRIBUTION_CHANNEL,
    notice.contractVersion,
    notice.latestVersionCode,
    notice.latestBuildNumber,
    notice.latestVersion,
    notice.downloadUrl,
  ]
    .filter(Boolean)
    .join('|')
    .slice(0, 2300);

export const shouldPresentAppUpdate = async (notice: AppUpdateNotice) => {
  if (notice.isBlocking) return true;
  const dismissal =
    memoryDismissal || (await getItem<UpdateDismissal>(UPDATE_DISMISSAL_KEY));
  if (
    !dismissal ||
    dismissal.identity !== noticeIdentity(notice) ||
    !Number.isFinite(dismissal.dismissedAt)
  ) {
    return true;
  }
  memoryDismissal = dismissal;
  const elapsed = serverNowMs() - dismissal.dismissedAt;
  return elapsed < 0 || elapsed >= OPTIONAL_UPDATE_COOLDOWN_MS;
};

export const dismissAppUpdate = async (notice: AppUpdateNotice) => {
  if (notice.isBlocking) return false;
  const dismissal = {
    identity: noticeIdentity(notice),
    dismissedAt: serverNowMs(),
  };
  memoryDismissal = dismissal;
  return saveItem(UPDATE_DISMISSAL_KEY, dismissal);
};

export type AppUpdateCheckResult = {
  authoritative: boolean;
  notice: AppUpdateNotice | null;
};

const hasPolicyDecision = (payload: unknown) => {
  const record =
    payload && typeof payload === 'object' && !Array.isArray(payload)
      ? (payload as Record<string, unknown>)
      : {};
  const nested =
    record.data && typeof record.data === 'object' && !Array.isArray(record.data)
      ? (record.data as Record<string, unknown>)
      : record;
  const data =
    nested.data &&
    typeof nested.data === 'object' &&
    !Array.isArray(nested.data)
      ? (nested.data as Record<string, unknown>)
      : nested;
  return typeof data.update_required === 'boolean';
};

export const checkAppUpdatePolicy =
  async (): Promise<AppUpdateCheckResult> => {
    const payload = createVersionCheckPayload({
      platform: Platform.OS,
      version: appConfig.expo.version,
      androidVersionCode: appConfig.expo.android.versionCode,
      iosBuildNumber: appConfig.expo.ios.buildNumber,
      distributionChannel: DISTRIBUTION_CHANNEL,
    });
    if (!payload) return {authoritative: false, notice: null};

    try {
      const requestConfig: RoknRequestConfig = {
        skipAuthorization: true,
        skipPersistedSessionInvalidation: true,
        timeout: 6_000,
      };
      const response = await publicRequest.post(
        'app/check-version',
        payload,
        requestConfig,
      );
      return {
        authoritative: hasPolicyDecision(response.data),
        notice: parseAppVersionResponse(response, DISTRIBUTION_CHANNEL),
      };
    } catch {
      return {authoritative: false, notice: null};
    }
  };

/** Best-effort at startup: this can never hold the splash or reject launch. */
export const checkForAppUpdate = async (): Promise<AppUpdateNotice | null> => {
  return (await checkAppUpdatePolicy()).notice;
};
