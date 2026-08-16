import RNFS from 'react-native-fs';
import {Platform} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';

// Chat attachments are working files, not learner documents. Keeping them in
// the OS cache lets low-storage phones reclaim the space automatically.
const getCacheDir = (): string => `${RNFS.CachesDirectoryPath}/rokn_chat`;
const getLegacyCacheDir = (): string =>
  `${RNFS.DocumentDirectoryPath}/chat_cache`;

const CACHE_METADATA_KEY = '@chat_file_cache_metadata';
const MAX_CACHE_AGE_MS = 24 * 60 * 60 * 1000;
const MAX_CACHE_BYTES = 16 * 1024 * 1024;
const MAX_SINGLE_FILE_BYTES = 8 * 1024 * 1024;

export interface CachedFile {
  id: string;
  uri: string;
  type: 'image' | 'file';
  mimeType: string;
  name: string;
  size: number;
  cachedAt: number;
}

interface CacheMetadata {
  [fileId: string]: CachedFile;
}

const cacheErrorMessage = (error: unknown, fallback: string) =>
  error instanceof Error && error.message
    ? error.message
    : error
    ? String(error)
    : fallback;

// Initialize cache directory
export const initCacheDir = async (): Promise<void> => {
  try {
    const cacheDir = getCacheDir();

    // Check if directory exists
    const exists = await RNFS.exists(cacheDir);
    if (exists) {
      return; // Directory already exists
    }

    // Try to create directory
    try {
      await RNFS.mkdir(cacheDir);
    } catch (mkdirError: unknown) {
      // If mkdir fails, check if directory was created by another process
      const existsAfterError = await RNFS.exists(cacheDir);
      if (!existsAfterError) {
        // Directory still doesn't exist, log the error
        const errorMessage = cacheErrorMessage(
          mkdirError,
          'Failed to create cache directory',
        );
        if (__DEV__) {
          console.warn('Could not create cache directory:', errorMessage);
        }
        // Initialization retries on the first cache write.
      }
      // If directory exists now, it's fine - another process created it
    }
  } catch (error: unknown) {
    // Normalize initialization errors for logging.
    const errorMessage = cacheErrorMessage(
      error,
      'Unknown error initializing cache directory',
    );
    if (__DEV__) {
      console.warn('Error initializing cache directory:', errorMessage);
    }
    // The first cache write retries initialization.
  }
};

// Generate unique file ID
const generateFileId = (): string => {
  return `file_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
};

// Get file extension from URI or name
const getFileExtension = (uri: string, name?: string): string => {
  const fileName = name || uri.split('/').pop() || '';
  const parts = fileName.split('.');
  return parts.length > 1 ? parts.pop()!.toLowerCase() : '';
};

// Determine file type from extension or mime type
const getFileType = (mimeType: string, extension: string): 'image' | 'file' => {
  if (
    mimeType.startsWith('image/') ||
    ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension)
  ) {
    return 'image';
  }
  return 'file';
};

// Cache a file (copy from source to cache directory)
export const cacheFile = async (
  sourceUri: string,
  mimeType: string,
  name?: string,
): Promise<CachedFile> => {
  try {
    await cleanupOldFiles();
    // Ensure cache directory exists
    await initCacheDir();
    const cacheDir = getCacheDir();

    const fileId = generateFileId();
    const extension = getFileExtension(sourceUri, name);
    const fileName = `${fileId}.${extension || 'bin'}`;
    const cachedPath = `${cacheDir}/${fileName}`;

    // Copy file to cache directory
    await RNFS.copyFile(sourceUri, cachedPath);

    // Get file size
    const stat = await RNFS.stat(cachedPath);
    const size =
      typeof stat.size === 'string' ? parseInt(stat.size, 10) : stat.size;

    if (!Number.isFinite(size) || size > MAX_SINGLE_FILE_BYTES) {
      await RNFS.unlink(cachedPath).catch(() => undefined);
      throw new Error(
        'The selected file is too large for a temporary chat attachment.',
      );
    }

    const cachedFile: CachedFile = {
      id: fileId,
      uri: Platform.OS === 'ios' ? cachedPath : `file://${cachedPath}`,
      type: getFileType(mimeType, extension),
      mimeType,
      name: name || `file.${extension}`,
      size,
      cachedAt: Date.now(),
    };

    // Save metadata
    const metadataStr = await AsyncStorage.getItem(CACHE_METADATA_KEY);
    const metadata: CacheMetadata = metadataStr ? JSON.parse(metadataStr) : {};
    metadata[fileId] = cachedFile;
    await AsyncStorage.setItem(CACHE_METADATA_KEY, JSON.stringify(metadata));
    await cleanupOldFiles();

    return cachedFile;
  } catch (error: unknown) {
    // Expose a concrete cache error.
    const errorMessage = cacheErrorMessage(error, 'Unknown error caching file');
    if (__DEV__) console.error('Error caching file:', errorMessage);
    throw new Error(errorMessage);
  }
};

// Get cached file by ID
export const getCachedFile = async (
  fileId: string,
): Promise<CachedFile | null> => {
  try {
    const metadataStr = await AsyncStorage.getItem(CACHE_METADATA_KEY);
    if (!metadataStr) return null;

    const metadata: CacheMetadata = JSON.parse(metadataStr);
    const file = metadata[fileId];

    if (!file) return null;

    // Check if file still exists
    const exists = await RNFS.exists(
      Platform.OS === 'ios' ? file.uri : file.uri.replace('file://', ''),
    );
    if (!exists) {
      // Remove from metadata if file doesn't exist
      delete metadata[fileId];
      await AsyncStorage.setItem(CACHE_METADATA_KEY, JSON.stringify(metadata));
      return null;
    }

    return file;
  } catch (error) {
    if (__DEV__) console.error('Error getting cached file:', error);
    return null;
  }
};

// Convert file to base64 for API
export const fileToBase64 = async (fileUri: string): Promise<string> => {
  try {
    const uri =
      Platform.OS === 'android' ? fileUri.replace('file://', '') : fileUri;
    const base64 = await RNFS.readFile(uri, 'base64');
    return base64;
  } catch (error: unknown) {
    // Expose a concrete read error.
    const errorMessage = cacheErrorMessage(
      error,
      'Unknown error converting file to base64',
    );
    if (__DEV__)
      console.error('Error converting file to base64:', errorMessage);
    throw new Error(errorMessage);
  }
};

const deleteCachedPath = async (uri: string) => {
  const filePath = Platform.OS === 'ios' ? uri : uri.replace('file://', '');
  if (await RNFS.exists(filePath)) {
    await RNFS.unlink(filePath);
  }
};

// Bound temporary attachment storage by age and total size.
export const cleanupOldFiles = async (): Promise<void> => {
  try {
    const metadataStr = await AsyncStorage.getItem(CACHE_METADATA_KEY);
    if (!metadataStr) return;

    const metadata: CacheMetadata = JSON.parse(metadataStr);
    const expiresBefore = Date.now() - MAX_CACHE_AGE_MS;
    const newestFirst = Object.entries(metadata).sort(
      ([, left], [, right]) => right.cachedAt - left.cachedAt,
    );
    const retained: CacheMetadata = {};
    let retainedBytes = 0;

    for (const [fileId, file] of newestFirst) {
      const size = Number(file.size) || 0;
      const expired = !file.cachedAt || file.cachedAt < expiresBefore;
      const exceedsBudget = retainedBytes + size > MAX_CACHE_BYTES;
      const path =
        Platform.OS === 'ios' ? file.uri : file.uri.replace('file://', '');
      const exists = await RNFS.exists(path).catch(() => false);

      if (expired || exceedsBudget || !exists) {
        await deleteCachedPath(file.uri).catch(() => undefined);
        continue;
      }
      retained[fileId] = file;
      retainedBytes += size;
    }

    if (Object.keys(retained).length) {
      await AsyncStorage.setItem(CACHE_METADATA_KEY, JSON.stringify(retained));
    } else {
      await AsyncStorage.removeItem(CACHE_METADATA_KEY);
    }
  } catch (error) {
    if (__DEV__) console.error('Error cleaning up old files:', error);
  }
};

/**
 * Rokn AI is session-only. Remove attachment leftovers from both the current
 * cache and the document directory used by older test builds.
 */
export const clearTransientChatCache = async (): Promise<void> => {
  await Promise.all(
    [getCacheDir(), getLegacyCacheDir()].map(async directory => {
      try {
        if (await RNFS.exists(directory)) {
          await RNFS.unlink(directory);
        }
      } catch {
        // Cleanup failure does not block application startup.
      }
    }),
  );
  try {
    const documentFiles = await RNFS.readDir(RNFS.DocumentDirectoryPath);
    await Promise.all(
      documentFiles
        .filter(file => /^(voice-note|voice-\d+)\.(m4a|mp3)$/i.test(file.name))
        .map(file => RNFS.unlink(file.path).catch(() => undefined)),
    );
  } catch {
    // These files existed only in retired voice-chat test builds.
  }
  await RNFS.unlink(`${RNFS.CachesDirectoryPath}/voice-note.mp3`).catch(
    () => undefined,
  );
  await AsyncStorage.removeItem(CACHE_METADATA_KEY).catch(() => undefined);
};

// Get cache size
export const getCacheSize = async (): Promise<number> => {
  try {
    const metadataStr = await AsyncStorage.getItem(CACHE_METADATA_KEY);
    if (!metadataStr) return 0;

    const metadata: CacheMetadata = JSON.parse(metadataStr);
    return Object.values(metadata).reduce(
      (total, file) => total + file.size,
      0,
    );
  } catch (error) {
    if (__DEV__) console.error('Error getting cache size:', error);
    return 0;
  }
};
