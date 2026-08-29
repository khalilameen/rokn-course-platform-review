import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../../../constants/api';
import {accountScopedStorageKey} from '../../../constants/helpers';
import {hasSession} from '../../../services/roknApi';
import {updatePlayerState} from './persistence';
import {asArray, valueAsString} from './shared';

type SavedFolderDto = {
  id?: unknown;
  name?: unknown;
  image?: unknown;
  lessons_count?: unknown;
};

const WATCH_LATER_FOLDER_KEY = '@rokn/watch-later-folder-id/v2';
const SAVED_FOLDERS_KEY = '@rokn/saved-folder-options/v1';

const ensureWatchLaterFolder = async (): Promise<string | null> => {
  const storageKey = await accountScopedStorageKey(WATCH_LATER_FOLDER_KEY);
  const cached = await AsyncStorage.getItem(storageKey);
  if (cached) {
    return cached;
  }
  if (!(await hasSession())) {
    return null;
  }

  try {
    const response = await publicRequest.get('saved-folders');
    const folderPayload = response?.data?.data;
    const folders = asArray<SavedFolderDto>(
      folderPayload?.data ?? folderPayload,
    );
    let folder = folders.find(item => {
      const name = valueAsString(item?.name).trim().toLowerCase();
      return name === 'watch later' || name === 'المشاهدة لاحقًا';
    });
    if (!folder) {
      const created = await publicRequest.post('saved-folders', {
        name: 'المشاهدة لاحقًا',
      });
      const createdPayload = created?.data?.data;
      folder = createdPayload?.data ?? createdPayload;
    }
    if (!folder?.id) {
      return null;
    }
    const id = valueAsString(folder.id);
    await AsyncStorage.setItem(storageKey, id);
    return id;
  } catch {
    return null;
  }
};

export type SavedFolderOption = {
  id: string;
  name: string;
  imageUrl?: string;
  lessonsCount?: number;
};

const mapSavedFolder = (folder: SavedFolderDto): SavedFolderOption => ({
  id: valueAsString(folder.id),
  name: valueAsString(folder.name),
  imageUrl: folder.image ? valueAsString(folder.image) : undefined,
  lessonsCount: Number.isFinite(Number(folder.lessons_count))
    ? Math.max(0, Number(folder.lessons_count))
    : undefined,
});

const localSavedFoldersKey = () => accountScopedStorageKey(SAVED_FOLDERS_KEY);

const readLocalSavedFolders = async (): Promise<SavedFolderOption[]> => {
  const raw = await AsyncStorage.getItem(await localSavedFoldersKey());
  try {
    const parsed = raw ? JSON.parse(raw) : [];
    if (Array.isArray(parsed) && parsed.length) {
      return parsed
        .filter(item => item?.id && item?.name)
        .map(mapSavedFolder);
    }
  } catch {
    // A damaged local folder index should never block saving a reel.
  }
  return [{id: 'local-watch-later', name: 'المشاهدة لاحقًا'}];
};

const writeLocalSavedFolders = async (folders: SavedFolderOption[]) =>
  AsyncStorage.setItem(await localSavedFoldersKey(), JSON.stringify(folders));

export const getSavedFolderOptions = async (): Promise<SavedFolderOption[]> => {
  if (!(await hasSession())) {
    return readLocalSavedFolders();
  }
  try {
    const response = await publicRequest.get('saved-folders');
    const folderPayload = response?.data?.data;
    const folders = asArray<SavedFolderDto>(
      folderPayload?.data ?? folderPayload,
    )
      .filter(item => item?.id && item?.name)
      .map(mapSavedFolder);
    await writeLocalSavedFolders(folders);
    return folders;
  } catch {
    // Offline accounts may use cached server folders, not local-only ids.
    const cached = (await readLocalSavedFolders()).filter(
      folder => !folder.id.startsWith('local-'),
    );
    if (cached.length) return cached;
    throw new Error('SAVED_FOLDERS_UNAVAILABLE');
  }
};

export const createSavedFolderOption = async (
  rawName: string,
): Promise<SavedFolderOption> => {
  const name = rawName.trim().slice(0, 60);
  if (!name) throw new Error('FOLDER_NAME_REQUIRED');
  if (!(await hasSession())) {
    const created = {id: `local-${Date.now()}`, name};
    const current = await readLocalSavedFolders();
    await writeLocalSavedFolders([...current, created]);
    return created;
  }
  const response = await publicRequest.post('saved-folders', {name});
  const payload = response?.data?.data;
  const folder = payload?.data ?? payload;
  if (!folder?.id) throw new Error('SAVED_FOLDER_CREATE_FAILED');
  const created = {
    id: valueAsString(folder.id),
    name: valueAsString(folder.name || name),
    imageUrl: folder.image ? valueAsString(folder.image) : undefined,
    lessonsCount: Number.isFinite(Number(folder.lessons_count))
      ? Math.max(0, Number(folder.lessons_count))
      : 0,
  };
  const current = await readLocalSavedFolders();
  await writeLocalSavedFolders([
    ...current.filter(
      item => !item.id.startsWith('local-') && item.id !== created.id,
    ),
    created,
  ]);
  return created;
};

export const deleteSavedFolderOption = async (folderId: string) => {
  const sessionAvailable = await hasSession();
  if (sessionAvailable && !folderId.startsWith('local-')) {
    await publicRequest.delete(`saved-folders/${folderId}`);
  }

  const current = await readLocalSavedFolders();
  await writeLocalSavedFolders(
    current.filter(folder => folder.id !== folderId),
  );
  await updatePlayerState(state => {
    const nextFolders = {...state.savedFolderLessons};
    delete nextFolders[folderId];
    const stillSaved = new Set(Object.values(nextFolders).flat());
    return {
      ...state,
      savedFolderLessons: nextFolders,
      savedLessons: state.savedLessons.filter(lessonId =>
        stillSaved.has(lessonId),
      ),
    };
  });
};

export const saveLessonToFolder = async (
  lessonId: string,
  folder: SavedFolderOption,
) => {
  const sessionAvailable = await hasSession();
  if (sessionAvailable && !lessonId.startsWith('demo')) {
    if (folder.id.startsWith('local-')) {
      throw new Error('UNSYNCED_SAVED_FOLDER');
    }
    // For real accounts the server is authoritative. Do not show a success
    // that disappears on another device or after the next refresh.
    await publicRequest.post(`saved-folders/${folder.id}/lessons`, {
      lesson_id: lessonId,
    });
  }
  await updatePlayerState(state => ({
    ...state,
    savedLessons: Array.from(new Set([...state.savedLessons, lessonId])),
    savedFolderLessons: {
      ...state.savedFolderLessons,
      [folder.id]: Array.from(
        new Set([...(state.savedFolderLessons[folder.id] || []), lessonId]),
      ),
    },
  }));
  return true;
};

export const toggleWatchLater = async (
  lessonId: string,
  currentlySaved: boolean,
) => {
  const nextSaved = !currentlySaved;
  const sessionAvailable = !lessonId.startsWith('demo') && (await hasSession());
  let targetFolderId = 'local-watch-later';

  if (sessionAvailable) {
    if (!nextSaved) {
      await publicRequest.delete(`saved-lessons/${lessonId}`);
    } else {
      const folderId = await ensureWatchLaterFolder();
      if (!folderId) throw new Error('WATCH_LATER_FOLDER_UNAVAILABLE');
      await publicRequest.post(`saved-folders/${folderId}/lessons`, {
        lesson_id: lessonId,
      });
      targetFolderId = folderId;
    }
  }

  await updatePlayerState(state => ({
    ...state,
    savedLessons: nextSaved
      ? Array.from(new Set([...state.savedLessons, lessonId]))
      : state.savedLessons.filter(id => id !== lessonId),
    savedFolderLessons: nextSaved
      ? {
          ...state.savedFolderLessons,
          [targetFolderId]: Array.from(
            new Set([
              ...(state.savedFolderLessons[targetFolderId] || []),
              lessonId,
            ]),
          ),
        }
      : Object.fromEntries(
          Object.entries(state.savedFolderLessons)
            .map(([folderId, lessons]) => [
              folderId,
              lessons.filter(id => id !== lessonId),
            ])
            .filter(([, lessons]) => lessons.length > 0),
        ),
  }));

  return nextSaved;
};

export const removeLessonFromSavedFolder = async (
  lessonId: string,
  folderId: string,
) =>
  updatePlayerState(state => {
    const nextFolders = {...state.savedFolderLessons};
    const remainingInFolder = (nextFolders[folderId] || []).filter(
      id => id !== lessonId,
    );
    if (remainingInFolder.length) {
      nextFolders[folderId] = remainingInFolder;
    } else {
      delete nextFolders[folderId];
    }
    const remainsSaved = Object.values(nextFolders).some(lessons =>
      lessons.includes(lessonId),
    );
    return {
      ...state,
      savedFolderLessons: nextFolders,
      savedLessons: remainsSaved
        ? state.savedLessons
        : state.savedLessons.filter(id => id !== lessonId),
    };
  });
