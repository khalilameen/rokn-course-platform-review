import {Dimensions, Platform} from 'react-native';

import appConfig from '../../app.json';
import {publicRequest} from '../constants/api';

export type ProductFeedbackCategory =
  | 'problem'
  | 'idea'
  | 'content'
  | 'playback';

export type FeedbackAttachment = {
  fileName?: string;
  type?: string;
  uri: string;
};

export type ProductFeedbackContext = {
  locale?: string;
  sourceScreen?: string;
};

const backendCategory: Record<ProductFeedbackCategory, string> = {
  problem: 'bug',
  idea: 'suggestion',
  content: 'course_content',
  playback: 'playback',
};

const normalizeScreenKey = (value?: string) => {
  const normalized = String(value || 'feedback')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 64);
  return normalized || 'feedback';
};

const osMajor = () => {
  const value = Number.parseInt(String(Platform.Version).split('.')[0], 10);
  return Number.isInteger(value) && value > 0 && value <= 255
    ? value
    : undefined;
};

const buildNumber = () => {
  const value = Number(
    Platform.OS === 'ios'
      ? appConfig.expo.ios?.buildNumber
      : appConfig.expo.android?.versionCode,
  );
  return Number.isInteger(value) && value > 0 ? value : undefined;
};

type NativeUpload = {name: string; type: string; uri: string};
type NativeFormData = FormData & {
  append(name: string, value: string | NativeUpload): void;
};

const createFeedbackBody = ({
  attachment,
  category,
  context,
  message,
}: {
  attachment?: FeedbackAttachment;
  category: ProductFeedbackCategory;
  context?: ProductFeedbackContext;
  message: string;
}) => {
  const screen = Dimensions.get('window');
  const form = new FormData() as NativeFormData;
  form.append('category', backendCategory[category]);
  form.append('message', message.trim());
  form.append('platform', Platform.OS);
  form.append('app_version', appConfig.expo.version);
  form.append('screen_key', normalizeScreenKey(context?.sourceScreen));
  form.append('locale', String(context?.locale || 'ar').slice(0, 16));
  form.append(
    'screen_size',
    `${Math.round(screen.width)}x${Math.round(screen.height)}`,
  );
  form.append('font_scale', String(screen.fontScale || 1));
  const currentBuildNumber = buildNumber();
  if (currentBuildNumber) {
    form.append('build_number', String(currentBuildNumber));
  }
  const currentOsMajor = osMajor();
  if (currentOsMajor) {
    form.append('os_major', String(currentOsMajor));
  }
  form.append('device_tier', 'unknown');
  form.append('network_type', 'unknown');
  if (attachment) {
    form.append('screenshot', {
      name: attachment.fileName || `rokn-feedback-${Date.now()}.jpg`,
      type: attachment.type || 'image/jpeg',
      uri: attachment.uri,
    });
  }
  return form;
};

/**
 * Feedback remains server-owned and session-sized. The screenshot is uploaded
 * from its picker URI and is never copied into Rokn's persistent storage.
 */
export const submitProductFeedback = async (input: {
  attachment?: FeedbackAttachment;
  category: ProductFeedbackCategory;
  context?: ProductFeedbackContext;
  message: string;
}) => {
  await publicRequest.post('feedback', createFeedbackBody(input), {
    timeout: 30000,
  });
};
