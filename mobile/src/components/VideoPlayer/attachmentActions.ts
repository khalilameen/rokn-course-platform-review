import Clipboard from '@react-native-clipboard/clipboard';
import {Alert, Linking, NativeModules, Platform} from 'react-native';
import RNFS from 'react-native-fs';
import Share from 'react-native-share';
import {CourseAttachment} from './types';
import {loadCourseLearningData} from './courseLearning/mapping';
import {remainingServerMilliseconds} from '../../utils/serverClock';
import {safeFilenameStem} from '../../utils/unicodeText';
import {nativeAttachmentRecovery} from './attachmentDownloadPolicy';

const downloadFlights = new Map<
  string,
  Promise<{copied: boolean; downloaded: boolean; downloadId?: number}>
>();
const activePrivateDownloadJobs = new Set<number>();
const activeAndroidDownloadIds = new Set<number>();
let privateDownloadGeneration = 0;
const STORAGE_RESERVE_BYTES = 32 * 1024 * 1024;
const MIME_EXTENSIONS: Record<string, string> = {
  'application/pdf': 'pdf',
  'application/zip': 'zip',
  'application/x-zip-compressed': 'zip',
  'application/msword': 'doc',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
    'docx',
  'application/vnd.ms-excel': 'xls',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
  'application/vnd.ms-powerpoint': 'ppt',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation':
    'pptx',
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'text/plain': 'txt',
};
const EXTENSION_MIME: Record<string, string> = Object.fromEntries(
  Object.entries(MIME_EXTENSIONS).map(([mime, extension]) => [extension, mime]),
);

const normalizeExtension = (value?: string) => {
  const normalized = String(value || '')
    .trim()
    .toLowerCase()
    .split(';')[0];
  if (MIME_EXTENSIONS[normalized]) return MIME_EXTENSIONS[normalized];
  const tail = normalized.includes('/')
    ? normalized.split('/').pop() || ''
    : normalized;
  const clean = tail.replace(/^\./, '').replace(/[^a-z0-9]/g, '');
  if (!clean || clean.length > 8) {
    return '';
  }
  return clean === 'jpeg' ? 'jpg' : clean === 'plain' ? 'txt' : clean;
};

const mimeTypeFor = (attachment: CourseAttachment, fileName: string) => {
  const supplied = String(attachment.mimeType || attachment.fileType || '')
    .trim()
    .toLowerCase()
    .split(';')[0];
  if (supplied.includes('/')) return supplied;
  const extension = normalizeExtension(fileName.split('.').pop());
  return EXTENSION_MIME[extension] || 'application/octet-stream';
};

const safeFileName = (attachment: CourseAttachment) => {
  const fromUrl = attachment.url.split('?')[0].split('/').pop();
  const extensionFromUrl = fromUrl?.includes('.')
    ? normalizeExtension(fromUrl.split('.').pop())
    : '';
  const extension =
    extensionFromUrl || normalizeExtension(attachment.fileType) || 'file';
  const cleanTitle = safeFilenameStem(attachment.title);
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
  try {
    const canOpen = await Linking.canOpenURL(url);
    if (!canOpen) return false;
    await Linking.openURL(url);
    return true;
  } catch {
    return false;
  }
};

const attachmentUrlNeedsRefresh = (attachment: CourseAttachment) => {
  if (!attachment.temporary) return false;
  const remaining = remainingServerMilliseconds(attachment.expiresAt);
  return remaining === null || remaining <= 90_000;
};

const refreshAttachment = async (attachment: CourseAttachment) => {
  if (!attachment.courseId || !attachment.moduleId) return null;
  const {course} = await loadCourseLearningData(attachment.courseId, {
    reconcilePending: false,
  });
  const module = course.modules.find(item => item.id === attachment.moduleId);
  return module?.attachments.find(item => item.id === attachment.id) || null;
};

const usableAttachment = async (
  attachment: CourseAttachment,
  forceRefresh = false,
) => {
  if (!forceRefresh && !attachmentUrlNeedsRefresh(attachment)) {
    return attachment;
  }
  const refreshed = await refreshAttachment(attachment);
  if (!refreshed || !isAllowedRemoteUrl(refreshed.url)) {
    throw new Error('ATTACHMENT_URL_REFRESH_FAILED');
  }
  return refreshed;
};

const attachmentFlightKey = (attachment: CourseAttachment) =>
  [
    attachment.courseId || 'course',
    attachment.moduleId || 'module',
    attachment.id,
    attachment.downloadVersion || 'current',
    attachment.url,
  ].join('|');

const nativeStableKey = (attachment: CourseAttachment) => {
  return [
    attachment.courseId || 'course',
    attachment.moduleId || 'module',
    attachment.id,
    attachment.downloadVersion || attachment.url.split('?')[0],
  ].join(':');
};

const hasLocalSpace = async (expectedBytes?: number) => {
  if (!expectedBytes || expectedBytes <= 0) return true;
  try {
    const {freeSpace} = await RNFS.getFSInfo();
    // iOS stages one copy and Save to Files may create the durable second copy.
    return freeSpace >= expectedBytes * 2 + STORAGE_RESERVE_BYTES;
  } catch {
    return true;
  }
};

const downloadPrivateFile = (fromUrl: string, toFile: string) => {
  const task = RNFS.downloadFile({
    fromUrl,
    toFile,
    background: true,
    discretionary: true,
  });
  activePrivateDownloadJobs.add(task.jobId);
  return {
    jobId: task.jobId,
    promise: task.promise.finally(() => {
      activePrivateDownloadJobs.delete(task.jobId);
    }),
  };
};

const openCourseAttachmentInternal = async (
  attachment: CourseAttachment,
  signedUrlRefreshAttempted = false,
) => {
  const generation = privateDownloadGeneration;
  let currentAttachment: CourseAttachment;
  try {
    currentAttachment = await usableAttachment(attachment);
  } catch {
    if (generation !== privateDownloadGeneration) {
      return {copied: false, downloaded: false};
    }
    Alert.alert('تعذّر تجهيز الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
    return {copied: false, downloaded: false};
  }

  if (generation !== privateDownloadGeneration) {
    return {copied: false, downloaded: false};
  }

  if (!isAllowedRemoteUrl(currentAttachment.url)) {
    Alert.alert(
      'الرابط غير متاح',
      'حاول مرة أخرى\nأو تواصل مع الدعم',
    );
    return {copied: false, downloaded: false};
  }

  if (currentAttachment.platform === 'computer') {
    Clipboard.setString(currentAttachment.url);
    const temporaryLink = currentAttachment.temporary;
    Alert.alert(
      'تم نسخ الرابط',
      temporaryLink
        ? 'افتحه على الكمبيوتر الآن\nوإذا انتهى الرابط انسخه من جديد'
        : 'افتح الرابط على الكمبيوتر لتنزيل الملفات',
    );
    return {copied: true, downloaded: false};
  }

  if (currentAttachment.external) {
    if (await openRemoteDownload(currentAttachment.url)) {
      return {copied: false, downloaded: false};
    }
    Alert.alert('تعذّر فتح الرابط', 'تحقق من الاتصال ثم حاول مرة أخرى');
    return {copied: false, downloaded: false};
  }

  const fileName = safeFileName(currentAttachment);
  if (Platform.OS === 'android' && NativeModules.RoknDownloads?.enqueue) {
    try {
      const nativeResult = await NativeModules.RoknDownloads.enqueue(
        currentAttachment.url,
        currentAttachment.title,
        fileName,
        mimeTypeFor(currentAttachment, fileName),
        nativeStableKey(currentAttachment),
        currentAttachment.fileSizeBytes || 0,
      );
      const downloadId = Number(
        typeof nativeResult === 'object' ? nativeResult?.id : nativeResult,
      );
      const status =
        typeof nativeResult === 'object'
          ? String(nativeResult?.status || 'started')
          : 'started';
      if (Number.isFinite(downloadId)) {
        activeAndroidDownloadIds.add(downloadId);
        // New native builds can enumerate persisted DownloadManager jobs.
        // Keep only a bounded compatibility window for older native shells.
        while (activeAndroidDownloadIds.size > 128) {
          const oldest = activeAndroidDownloadIds.values().next().value;
          if (typeof oldest !== 'number') break;
          activeAndroidDownloadIds.delete(oldest);
        }
      }
      if (generation !== privateDownloadGeneration) {
        if (Number.isFinite(downloadId)) {
          void NativeModules.RoknDownloads.cancelIfActive?.(downloadId);
        }
        return {copied: false, downloaded: false};
      }
      if (status === 'running') {
        Alert.alert('التنزيل مستمر', 'تابع التقدم من إشعار التنزيل');
      } else if (status === 'completed') {
        Alert.alert('الملف في التنزيلات', 'افتحه بأي تطبيق مناسب');
      } else if (status !== 'opened') {
        Alert.alert('بدأ التنزيل', 'تابع التقدم من إشعار التنزيل');
      }
      return {
        copied: false,
        downloaded: true,
        downloadId: Number.isFinite(downloadId) ? downloadId : undefined,
      };
    } catch (error: unknown) {
      const code =
        error && typeof error === 'object' && 'code' in error
          ? String((error as {code?: unknown}).code || '')
          : '';
      const recovery = nativeAttachmentRecovery(
        code,
        signedUrlRefreshAttempted,
      );
      if (recovery === 'storage') {
        Alert.alert('المساحة لا تكفي', 'وفّر مساحة على الهاتف ثم حاول مرة أخرى');
        return {copied: false, downloaded: false};
      }
      if (recovery === 'refresh') {
        try {
          const refreshed = await usableAttachment(currentAttachment, true);
          if (generation !== privateDownloadGeneration) {
            return {copied: false, downloaded: false};
          }
          return openCourseAttachmentInternal(refreshed, true);
        } catch {
          Alert.alert('تعذّر تنزيل الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
          return {copied: false, downloaded: false};
        }
      }
      if (
        code === 'DOWNLOAD_RETRY_REQUIRES_REFRESH' &&
        signedUrlRefreshAttempted
      ) {
        Alert.alert('تعذّر تنزيل الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
        return {copied: false, downloaded: false};
      }
      // Fall through to the direct URL so a native integration issue never blocks the learner.
    }
  }

  // Android's system DownloadManager is the durable owner of private files.
  // Only a public external link may fall back to another app; a signed course
  // URL must remain cancellable at an account boundary.
  if (Platform.OS === 'android') {
    if (
      !currentAttachment.temporary &&
      (await openRemoteDownload(currentAttachment.url))
    ) {
      return {copied: false, downloaded: true};
    }
    Alert.alert(
      'تعذّر تنزيل الملف',
      'تحقق من الاتصال ثم حاول مرة أخرى',
    );
    return {copied: false, downloaded: false};
  }

  // iOS needs a local staging file before the system Save/Share sheet. Keep
  // that copy in cache and remove it after the handoff so every attachment
  // does not leave a hidden duplicate inside the app.
  const hasSpace = await hasLocalSpace(currentAttachment.fileSizeBytes);
  if (generation !== privateDownloadGeneration) {
    return {copied: false, downloaded: false};
  }
  if (!hasSpace) {
    Alert.alert('المساحة لا تكفي', 'وفّر مساحة على الهاتف ثم حاول مرة أخرى');
    return {copied: false, downloaded: false};
  }
  const cacheFolder = `${RNFS.CachesDirectoryPath}/rokn-attachments/${nativeStableKey(
    currentAttachment,
  )
    .replace(/[^a-zA-Z0-9_-]/g, '_')
    .slice(0, 120)}`;
  const target = `${cacheFolder}/${fileName}`;
  let cancelled = false;
  let activeJobId: number | undefined;

  try {
    // A cancelled/failed attempt can leave a partial cache file with the same
    // name. A full byte-for-byte staging file can also be the result of an iOS
    // background transfer that finished after the process was evicted.
    await RNFS.mkdir(cacheFolder);
    if (generation !== privateDownloadGeneration) {
      await RNFS.unlink(cacheFolder).catch(() => undefined);
      return {copied: false, downloaded: false};
    }
    const stagedSize = (await RNFS.exists(target))
      ? Number((await RNFS.stat(target)).size)
      : 0;
    const recoveredBackgroundDownload = Boolean(
      currentAttachment.fileSizeBytes &&
        stagedSize === currentAttachment.fileSizeBytes,
    );
    let result: {jobId: number; statusCode: number; bytesWritten: number};
    let download: ReturnType<typeof RNFS.downloadFile> | undefined;
    if (recoveredBackgroundDownload) {
      result = {jobId: -1, statusCode: 200, bytesWritten: stagedSize};
    } else {
      await RNFS.unlink(target).catch(() => undefined);
      download = downloadPrivateFile(currentAttachment.url, target);
      activeJobId = download.jobId;
      Alert.alert(
        'جارٍ تنزيل الملف',
        currentAttachment.fileSize
          ? `${currentAttachment.fileSize}\nسنفتح خيارات الحفظ عند اكتماله`
          : 'سنفتح خيارات الحفظ عند اكتماله',
        [
          {
            text: 'إلغاء',
            style: 'cancel',
            onPress: () => {
              cancelled = true;
              if (activeJobId !== undefined) RNFS.stopDownload(activeJobId);
            },
          },
          {text: 'إخفاء'},
        ],
      );
      result = await download.promise;
    }
    if (cancelled || generation !== privateDownloadGeneration) {
      await RNFS.unlink(cacheFolder).catch(() => undefined);
      return {copied: false, downloaded: false};
    }
    if (
      currentAttachment.temporary &&
      [401, 403, 404, 410].includes(result.statusCode)
    ) {
      await RNFS.unlink(target).catch(() => undefined);
      currentAttachment = await usableAttachment(currentAttachment, true);
      download = downloadPrivateFile(currentAttachment.url, target);
      activeJobId = download.jobId;
      result = await download.promise;
      if (cancelled || generation !== privateDownloadGeneration) {
        await RNFS.unlink(cacheFolder).catch(() => undefined);
        return {copied: false, downloaded: false};
      }
    }
    if (result.statusCode >= 200 && result.statusCode < 300) {
      const localSize = Number((await RNFS.stat(target)).size);
      if (!Number.isFinite(localSize) || localSize <= 0) {
        throw new Error('ATTACHMENT_EMPTY_DOWNLOAD');
      }
      if (
        currentAttachment.fileSizeBytes &&
        localSize !== currentAttachment.fileSizeBytes
      ) {
        throw new Error('ATTACHMENT_TRUNCATED_DOWNLOAD');
      }
      try {
        const handoff = await Share.open({
          url: `file://${target}`,
          saveToFiles: true,
          failOnCancel: false,
          title: currentAttachment.title,
        });
        if (!handoff.success || handoff.dismissedAction) {
          return {copied: false, downloaded: false};
        }
      } finally {
        await RNFS.unlink(cacheFolder).catch(() => undefined);
      }
      return {copied: false, downloaded: true};
    }
    throw new Error(`Download failed (${result.statusCode})`);
  } catch {
    await RNFS.unlink(cacheFolder).catch(() => undefined);
    if (cancelled || generation !== privateDownloadGeneration) {
      return {copied: false, downloaded: false};
    }
    if (
      !currentAttachment.temporary &&
      (await openRemoteDownload(currentAttachment.url))
    ) {
      return {copied: false, downloaded: true};
    }
    Alert.alert('تعذّر تنزيل الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
    return {copied: false, downloaded: false};
  }
};

export const openCourseAttachment = (attachment: CourseAttachment) => {
  const key = attachmentFlightKey(attachment);
  const existing = downloadFlights.get(key);
  if (existing) return existing;
  const flight = openCourseAttachmentInternal(attachment);
  downloadFlights.set(key, flight);
  const clear = () => {
    if (downloadFlights.get(key) === flight) downloadFlights.delete(key);
  };
  void flight.then(clear, clear);
  return flight;
};

/** Stop private transfers before account/session storage changes owner. */
export const quiescePrivateAttachmentDownloads = async (): Promise<void> => {
  privateDownloadGeneration += 1;
  downloadFlights.clear();
  activePrivateDownloadJobs.forEach(jobId => {
    try {
      RNFS.stopDownload(jobId);
    } catch {
      // A transfer that finished between snapshot and cancellation is safe.
    }
  });
  activePrivateDownloadJobs.clear();
  if (NativeModules.RoknDownloads?.cancelIfActive) {
    await Promise.all(
      [...activeAndroidDownloadIds].map(downloadId =>
        NativeModules.RoknDownloads.cancelIfActive(downloadId).catch(
          () => undefined,
        ),
      ),
    );
  }
  if (NativeModules.RoknDownloads?.cancelAllActive) {
    await NativeModules.RoknDownloads.cancelAllActive().catch(
      () => undefined,
    );
  }
  activeAndroidDownloadIds.clear();
  await RNFS.unlink(`${RNFS.CachesDirectoryPath}/rokn-attachments`).catch(
    () => undefined,
  );
};
