import {Platform} from 'react-native';
import RNFS from 'react-native-fs';

import {getCurrentAccountStorageScope} from '../constants/helpers';
import {secureRandomUuid} from '../utils/secureRandom';

export type LearnerDraftFile = {
  uri: string;
  type?: string;
  fileName?: string;
  size?: number;
};

const CACHE_ROOT = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts`;
const MAX_ACCOUNT_CACHE_BYTES = 192 * 1024 * 1024;
const MAX_CACHE_AGE_MS = 31 * 24 * 60 * 60 * 1000;
const accountFileOperations = new Map<string, Promise<void>>();
const filePath = (uri?: string) =>
  String(uri || '')
    .replace(/^file:\/\//, '')
    .replace(/\\/g, '/');

const safeExtension = (file: LearnerDraftFile) => {
  const named = String(file.fileName || '').match(/\.([a-z0-9]{1,8})$/i)?.[1];
  if (named) return named.toLowerCase();
  return (
    {
      'image/jpeg': 'jpg',
      'image/png': 'png',
      'image/webp': 'webp',
      'video/mp4': 'mp4',
      'video/quicktime': 'mov',
      'video/webm': 'webm',
    }[String(file.type || '').toLowerCase()] || 'bin'
  );
};

const isManagedPath = (path: string) => {
  const normalizedRoot = CACHE_ROOT.replace(/\\/g, '/').replace(/\/$/, '');
  return (
    path.startsWith(`${normalizedRoot}/`) &&
    !path
      .slice(normalizedRoot.length + 1)
      .split('/')
      .includes('..')
  );
};

const withAccountFileLock = async <T>(
  accountScope: string,
  operation: () => Promise<T>,
): Promise<T> => {
  const previous = accountFileOperations.get(accountScope) ?? Promise.resolve();
  let release: () => void = () => undefined;
  const current = new Promise<void>(resolve => {
    release = resolve;
  });
  accountFileOperations.set(accountScope, current);
  await previous.catch(() => undefined);
  try {
    return await operation();
  } finally {
    release();
    if (accountFileOperations.get(accountScope) === current) {
      accountFileOperations.delete(accountScope);
    }
  }
};

const trimAccountDraftFiles = async (
  accountScope: string,
  protectedPath?: string,
): Promise<void> => {
  const accountDirectory = `${CACHE_ROOT}/${accountScope}`;
  if (!(await RNFS.exists(accountDirectory).catch(() => false))) return;

  const kindDirectories = await RNFS.readDir(accountDirectory).catch(() => []);
  const files = (
    await Promise.all(
      kindDirectories
        .filter(item => item.isDirectory())
        .map(item => RNFS.readDir(item.path).catch(() => [])),
    )
  )
    .flat()
    .filter(item => item.isFile() && isManagedPath(filePath(item.path)))
    .map(item => ({
      modifiedAt: item.mtime?.getTime() || 0,
      path: filePath(item.path),
      size: Math.max(0, Number(item.size) || 0),
    }))
    .sort((left, right) => right.modifiedAt - left.modifiedAt);

  const now = Date.now();
  let retainedBytes = 0;
  for (const file of files) {
    const isProtected = file.path === protectedPath;
    const expired =
      !isProtected &&
      (!file.modifiedAt || now - file.modifiedAt > MAX_CACHE_AGE_MS);
    const exceedsBudget =
      !isProtected && retainedBytes + file.size > MAX_ACCOUNT_CACHE_BYTES;
    if (expired || exceedsBudget) {
      await RNFS.unlink(file.path).catch(() => undefined);
      continue;
    }
    retainedBytes += file.size;
  }
};

export const removeLearnerDraftFile = async (
  file?: Pick<LearnerDraftFile, 'uri'> | null,
): Promise<void> => {
  const path = filePath(file?.uri);
  if (!path || !isManagedPath(path)) return;
  await RNFS.unlink(path).catch(() => undefined);
};

export const cacheLearnerDraftFile = async (
  kind: 'avatar' | 'feedback' | 'portfolio' | 'project',
  source: LearnerDraftFile,
  maximumBytes: number,
): Promise<LearnerDraftFile> => {
  if (!source.uri || maximumBytes <= 0) {
    throw new Error('LEARNER_FILE_UNAVAILABLE');
  }
  const declaredSize = Number(source.size);
  if (Number.isFinite(declaredSize) && declaredSize > maximumBytes) {
    throw new Error('LEARNER_FILE_TOO_LARGE');
  }

  const scope = await getCurrentAccountStorageScope();
  if (!/^[a-z0-9_-]+$/i.test(scope))
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  const directory = `${CACHE_ROOT}/${scope}/${kind}`;
  const destination = `${directory}/${secureRandomUuid()}.${safeExtension(
    source,
  )}`;
  return withAccountFileLock(scope, async () => {
    try {
      await RNFS.mkdir(directory);
      await trimAccountDraftFiles(scope);
      await RNFS.copyFile(source.uri, destination);
      const stat = await RNFS.stat(destination);
      const copiedSize = Number(stat.size);
      if (
        !Number.isFinite(copiedSize) ||
        copiedSize <= 0 ||
        copiedSize > maximumBytes ||
        (Number.isFinite(declaredSize) &&
          declaredSize > 0 &&
          copiedSize !== declaredSize)
      ) {
        throw new Error(
          copiedSize > maximumBytes
            ? 'LEARNER_FILE_TOO_LARGE'
            : 'LEARNER_FILE_INCOMPLETE',
        );
      }

      await trimAccountDraftFiles(scope, destination);
      return {
        ...source,
        size: copiedSize,
        uri: Platform.OS === 'ios' ? destination : `file://${destination}`,
      };
    } catch (error) {
      await RNFS.unlink(destination).catch(() => undefined);
      if (error instanceof Error && error.message.startsWith('LEARNER_FILE_')) {
        throw error;
      }
      throw new Error('LEARNER_FILE_COPY_FAILED');
    }
  });
};

export const learnerDraftFileIsReadable = async (
  file?: LearnerDraftFile | null,
): Promise<boolean> => {
  if (!file?.uri) return false;
  try {
    const stat = await RNFS.stat(filePath(file.uri));
    return Number(stat.size) > 0;
  } catch {
    return false;
  }
};

export const clearAccountLearnerDraftFiles = async (
  accountScope: string,
): Promise<void> => {
  if (!/^[a-z0-9_-]+$/i.test(accountScope)) {
    throw new Error('INVALID_ACCOUNT_STORAGE_SCOPE');
  }
  const directory = `${CACHE_ROOT}/${accountScope}`;
  await withAccountFileLock(accountScope, async () => {
    if (await RNFS.exists(directory).catch(() => false)) {
      await RNFS.unlink(directory).catch(() => undefined);
    }
  });
};
