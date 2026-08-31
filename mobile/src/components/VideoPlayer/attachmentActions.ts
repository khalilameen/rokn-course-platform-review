import Clipboard from '@react-native-clipboard/clipboard';
import {Alert, Linking, NativeModules, Platform} from 'react-native';
import RNFS from 'react-native-fs';
import Share from 'react-native-share';
import {CourseAttachment} from './types';

const normalizeExtension = (value?: string) => {
  const normalized = String(value || '')
    .trim()
    .toLowerCase()
    .split(';')[0];
  const tail = normalized.includes('/')
    ? normalized.split('/').pop() || ''
    : normalized;
  const clean = tail.replace(/^\./, '').replace(/[^a-z0-9]/g, '');
  if (!clean || clean.length > 8) {
    return '';
  }
  return clean === 'jpeg' ? 'jpg' : clean === 'plain' ? 'txt' : clean;
};

const safeFileName = (attachment: CourseAttachment) => {
  const fromUrl = attachment.url.split('?')[0].split('/').pop();
  const extensionFromUrl = fromUrl?.includes('.')
    ? normalizeExtension(fromUrl.split('.').pop())
    : '';
  const extension =
    extensionFromUrl || normalizeExtension(attachment.fileType) || 'file';
  const cleanTitle = attachment.title
    .replace(/[^a-zA-Z0-9\u0600-\u06FF _-]/g, '')
    .trim()
    .replace(/\s+/g, '-')
    .slice(0, 80);
  return `${cleanTitle || `rokn-${attachment.id}`}.${extension}`;
};

const isAllowedRemoteUrl = (value: string) => {
  try {
    const parsed = new URL(value) as unknown as {
      hostname: string;
      protocol: string;
    };
    return (
      Boolean(parsed.hostname) &&
      (parsed.protocol === 'https:' ||
        (__DEV__ && parsed.protocol === 'http:'))
    );
  } catch {
    return false;
  }
};

const openRemoteDownload = async (url: string) => {
  const canOpen = await Linking.canOpenURL(url);
  if (!canOpen) return false;
  await Linking.openURL(url);
  return true;
};

export const openCourseAttachment = async (attachment: CourseAttachment) => {
  if (!isAllowedRemoteUrl(attachment.url)) {
    Alert.alert(
      'الرابط غير متاح',
      'حاول مرة أخرى\nأو تواصل مع الدعم',
    );
    return {copied: false, downloaded: false};
  }

  if (attachment.platform === 'computer') {
    Clipboard.setString(attachment.url);
    const temporaryLink = /[?&]expires=\d+/i.test(attachment.url);
    Alert.alert(
      'تم نسخ الرابط',
      temporaryLink
        ? 'افتحه على الكمبيوتر خلال ٣٠ دقيقة\nوانسخ رابطًا جديدًا إذا انتهت مدته'
        : 'افتح الرابط على الكمبيوتر لتنزيل الملفات',
    );
    return {copied: true, downloaded: false};
  }

  const fileName = safeFileName(attachment);
  if (Platform.OS === 'android' && NativeModules.RoknDownloads?.enqueue) {
    try {
      const downloadId = await NativeModules.RoknDownloads.enqueue(
        attachment.url,
        attachment.title,
        fileName,
      );
      Alert.alert(
        'بدأ التنزيل',
        'تابع التقدم من إشعار التنزيل',
      );
      return {copied: false, downloaded: true, downloadId};
    } catch {
      // Fall through to the direct URL so a native integration issue never blocks the learner.
    }
  }

  // Android's system DownloadManager is the durable owner of downloads. If a
  // device/vendor build cannot expose the native bridge, hand the HTTPS URL to
  // the system instead of accumulating invisible copies in app documents.
  if (Platform.OS === 'android') {
    if (await openRemoteDownload(attachment.url)) {
      return {copied: false, downloaded: true};
    }
    Alert.alert(
      'تعذر تنزيل الملف',
      'تحقق من الاتصال ثم حاول مرة أخرى',
    );
    return {copied: false, downloaded: false};
  }

  // iOS needs a local staging file before the system Save/Share sheet. Keep
  // that copy in cache and remove it after the handoff so every attachment
  // does not leave a hidden duplicate inside the app.
  const target = `${RNFS.CachesDirectoryPath}/${fileName}`;

  try {
    // A cancelled/failed attempt can leave a partial cache file with the same
    // name. Remove it before retrying and again on every failure path.
    await RNFS.unlink(target).catch(() => undefined);
    const result = await RNFS.downloadFile({
      fromUrl: attachment.url,
      toFile: target,
      background: true,
      discretionary: true,
    }).promise;
    if (result.statusCode >= 200 && result.statusCode < 300) {
      try {
        await Share.open({
          url: `file://${target}`,
          saveToFiles: true,
          failOnCancel: false,
          title: attachment.title,
        });
      } finally {
        await RNFS.unlink(target).catch(() => undefined);
      }
      return {copied: false, downloaded: true};
    }
    throw new Error(`Download failed (${result.statusCode})`);
  } catch {
    await RNFS.unlink(target).catch(() => undefined);
    if (await openRemoteDownload(attachment.url)) {
      return {copied: false, downloaded: true};
    }
    Alert.alert('تعذر تنزيل الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
    return {copied: false, downloaded: false};
  }
};
