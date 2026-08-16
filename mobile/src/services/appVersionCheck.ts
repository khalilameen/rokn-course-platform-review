import {Platform} from 'react-native';
import appConfig from '../../app.json';
import {publicRequest} from '../constants/api';
import {DISTRIBUTION_CHANNEL} from '../constants/distribution';
import {
  AppUpdateNotice,
  createVersionCheckPayload,
  parseAppVersionResponse,
} from './appVersionPolicy';

/** Best-effort at startup: this can never hold the splash or reject launch. */
export const checkForAppUpdate = async (): Promise<AppUpdateNotice | null> => {
  const payload = createVersionCheckPayload({
    platform: Platform.OS,
    version: appConfig.expo.version,
    androidVersionCode: appConfig.expo.android.versionCode,
    iosBuildNumber: appConfig.expo.ios.buildNumber,
    distributionChannel: DISTRIBUTION_CHANNEL,
  });
  if (!payload) return null;

  try {
    const response = await publicRequest.post('app/check-version', payload);
    return parseAppVersionResponse(response, DISTRIBUTION_CHANNEL);
  } catch {
    return null;
  }
};
