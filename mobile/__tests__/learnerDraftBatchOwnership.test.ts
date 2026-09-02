jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'account-1',
  })),
  getCurrentAccountStorageScope: jest.fn(async () => 'account-1'),
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';
import RNFS from 'react-native-fs';

import {
  cacheLearnerDraftFile,
  learnerDraftFileIsReadable,
} from '../src/services/learnerDraftFiles';

const MEBIBYTE = 1024 * 1024;
const accountDirectory = '/tmp/rokn-cache/rokn_learner_drafts/account-1';
const portfolioDirectory = `${accountDirectory}/portfolio`;

describe('learner draft batch ownership', () => {
  const files = new Map<string, {modifiedAt: Date; size: number}>();
  const sourceSizes = new Map<string, number>();
  let sequence = 0;

  beforeEach(async () => {
    await AsyncStorage.clear();
    jest.clearAllMocks();
    files.clear();
    sourceSizes.clear();
    sequence = 0;

    jest.mocked(Crypto.randomUUID).mockImplementation(() => {
      sequence += 1;
      return `00000000-0000-4000-8000-${String(sequence).padStart(12, '0')}`;
    });
    jest.mocked(RNFS.mkdir).mockResolvedValue(undefined);
    jest.mocked(RNFS.copyFile).mockImplementation(async (source, target) => {
      const size = sourceSizes.get(source);
      if (!size) throw new Error('ENOENT');
      files.set(target, {modifiedAt: new Date(sequence * 1000), size});
    });
    jest
      .mocked(RNFS.exists)
      .mockImplementation(
        async path =>
          path === accountDirectory ||
          path === portfolioDirectory ||
          files.has(path),
      );
    jest.mocked(RNFS.readDir).mockImplementation(async path => {
      if (path === accountDirectory) {
        return [
          {
            ctime: undefined,
            isDirectory: () => true,
            isFile: () => false,
            mtime: undefined,
            name: 'portfolio',
            path: portfolioDirectory,
            size: 0,
          },
        ];
      }
      if (path === portfolioDirectory) {
        return [...files.entries()].map(([filePath, file]) => ({
          ctime: file.modifiedAt,
          isDirectory: () => false,
          isFile: () => true,
          mtime: file.modifiedAt,
          name: filePath.split('/').pop() || '',
          path: filePath,
          size: file.size,
        }));
      }
      return [];
    });
    jest.mocked(RNFS.readFile).mockRejectedValue(new Error('ENOENT'));
    jest.mocked(RNFS.stat).mockImplementation(async path => {
      const file = files.get(path);
      if (!file) throw new Error('ENOENT');
      return {
        ctime: file.modifiedAt.getTime(),
        isDirectory: () => false,
        isFile: () => true,
        mtime: file.modifiedAt.getTime(),
        mode: 0,
        name: path.split('/').pop() || '',
        originalFilepath: path,
        path,
        size: file.size,
      };
    });
    jest.mocked(RNFS.unlink).mockImplementation(async path => {
      files.delete(path);
      for (const filePath of [...files.keys()]) {
        if (filePath.startsWith(`${path}/`)) files.delete(filePath);
      }
    });
  });

  it('refuses an oversized multi-select batch without evicting earlier picks', async () => {
    const copied = [];
    for (let index = 0; index < 3; index += 1) {
      const source = `/picker/video-${index}.mp4`;
      sourceSizes.set(source, 50 * MEBIBYTE);
      copied.push(
        await cacheLearnerDraftFile(
          'portfolio',
          {
            fileName: `video-${index}.mp4`,
            size: 50 * MEBIBYTE,
            type: 'video/mp4',
            uri: source,
          },
          50 * MEBIBYTE,
        ),
      );
    }

    const fourthSource = '/picker/video-3.mp4';
    sourceSizes.set(fourthSource, 50 * MEBIBYTE);
    await expect(
      cacheLearnerDraftFile(
        'portfolio',
        {
          fileName: 'video-3.mp4',
          size: 50 * MEBIBYTE,
          type: 'video/mp4',
          uri: fourthSource,
        },
        50 * MEBIBYTE,
      ),
    ).rejects.toThrow('LEARNER_DRAFT_STORAGE_FULL');

    await expect(
      Promise.all(copied.map(learnerDraftFileIsReadable)),
    ).resolves.toEqual([true, true, true]);
    expect(files.size).toBe(3);
  });
});
