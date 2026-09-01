import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../../../constants/api';
import {
  accountScopedStorageKey,
  getCurrentAccountStorageScope,
} from '../../../constants/helpers';
import {hasSession} from '../../../services/roknApi';
import {isLocalDemoId} from '../../../config/runtime';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {
  readPlayerState,
  updatePlayerStateForScope,
} from './persistence';
import {asArray, valueAsString} from './shared';

type SavedFolderDto = {
  id?: unknown;
  name?: unknown;
  image?: unknown;
  lessons_count?: unknown;
};

const WATCH_LATER_FOLDER_KEY = '@rokn/watch-later-folder-id/v2';
const SAVED_FOLDERS_KEY = '@rokn/saved-folder-options/v1';

const assertCurrentScope = async (expectedScope: string) => {
  if ((await getCurrentAccountStorageScope()) !== expectedScope) {
    throw new Error('ACCOUNT_CHANGED_DURING_SAVED_COLLECTION_OPERATION');
  }
};

const ensureWatchLaterFolder = async (
  expectedScope: string,
): Promise<string | null> => {
  const storageKey = `${WATCH_LATER_FOLDER_KEY}:${expectedScope}`;
  const cached = await AsyncStorage.getItem(storageKey);
  if (/^\d{1,18}$/.test(String(cached || '')) && Number(cached) > 0) {
    return String(cached);
  }
  if (cached !== null) {
    // Older builds occasionally persisted an object/stringified response here
    // instead of the folder id. It is not an entitlement; discard it and ask
    // the server for the authoritative folder on this launch.
    await AsyncStorage.removeItem(storageKey);
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
        client_request_id: secureRandomUuid(),
      });
      const createdPayload = created?.data?.data;
      folder = createdPayload?.data ?? createdPayload;
    }
    if (!folder?.id) {
      return null;
    }
    const id = valueAsString(folder.id);
    await assertCurrentScope(expectedScope);
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

const validSavedFolderOption = (value: unknown): value is SavedFolderDto => {
  if (!value || typeof value !== 'object') return false;
  const folder = value as SavedFolderDto;
  const id = valueAsString(folder.id);
  return (
    (/^\d{1,18}$/.test(id) || id === 'local-watch-later') &&
    valueAsString(folder.name).trim().length > 0
  );
};

const localSavedFoldersKey = (accountScope?: string) =>
  accountScope
    ? Promise.resolve(`${SAVED_FOLDERS_KEY}:${accountScope}`)
    : accountScopedStorageKey(SAVED_FOLDERS_KEY);

const readLocalSavedFolders = async (
  accountScope?: string,
): Promise<SavedFolderOption[]> => {
  const raw = await AsyncStorage.getItem(
    await localSavedFoldersKey(accountScope),
  );
  try {
    const parsed = raw ? JSON.parse(raw) : [];
    if (Array.isArray(parsed) && parsed.length) {
      return parsed
        .filter(validSavedFolderOption)
        .map(mapSavedFolder);
    }
  } catch {
    // A damaged local folder index should never block saving a reel.
  }
  return [{id: 'local-watch-later', name: 'المشاهدة لاحقًا'}];
};

const writeLocalSavedFolders = async (
  folders: SavedFolderOption[],
  storageKey?: string,
) =>
  AsyncStorage.setItem(
    storageKey || (await localSavedFoldersKey()),
    JSON.stringify(folders),
  );

const normalizedFolderName = (value: string) =>
  value.trim().replace(/\s+/g, ' ').toLocaleLowerCase('ar');

const guestFoldersKey = (guestScope: string) =>
  `${SAVED_FOLDERS_KEY}:${guestScope}`;

/**
 * Turns the lists created during public preview into real account lists after
 * sign-in. The operation is retry-safe: folder names are matched first and
 * the lesson endpoint is idempotent, while the guest copy is retained until
 * every server write succeeds.
 */
export const migrateGuestSavedCollections = async (
  guestScope: string,
): Promise<boolean> => {
  if (!/^[a-z0-9_-]+$/i.test(guestScope) || !(await hasSession())) return false;
  const accountScope = await getCurrentAccountStorageScope();
  if (guestScope === accountScope) return false;

  const sourceFoldersRaw = await AsyncStorage.getItem(
    guestFoldersKey(guestScope),
  );
  let sourceFolders: SavedFolderOption[] = [];
  try {
    const parsed = sourceFoldersRaw ? JSON.parse(sourceFoldersRaw) : [];
    if (Array.isArray(parsed)) {
      sourceFolders = parsed
        .filter(item => item?.id && item?.name)
        .map(mapSavedFolder);
    }
  } catch {
    // The player state still contains the default watch-later membership.
  }

  const state = await readPlayerState();
  const localMembershipIds = Object.keys(state.savedFolderLessons).filter(id =>
    id.startsWith('local-'),
  );
  const localFolderIds = Array.from(
    new Set([
      ...sourceFolders
        .filter(folder => folder.id.startsWith('local-'))
        .map(folder => folder.id),
      ...localMembershipIds,
    ]),
  );
  if (!localFolderIds.length) {
    if (sourceFoldersRaw) {
      await AsyncStorage.removeItem(guestFoldersKey(guestScope));
    }
    return true;
  }

  const response = await publicRequest.get('saved-folders');
  const folderPayload = response?.data?.data;
  const remoteFolders = asArray<SavedFolderDto>(
    folderPayload?.data ?? folderPayload,
  )
    .filter(item => item?.id && item?.name)
    .map(mapSavedFolder);
  const idMap = new Map<string, string>();

  for (const localId of localFolderIds) {
    const source = sourceFolders.find(folder => folder.id === localId);
    const requestedName =
      localId === 'local-watch-later'
        ? 'المشاهدة لاحقًا'
        : source?.name?.trim() || 'قائمة محفوظة';
    const requestedNameKey = normalizedFolderName(requestedName);
    let remote = remoteFolders.find(
      folder => normalizedFolderName(folder.name) === requestedNameKey,
    );
    if (!remote && localId === 'local-watch-later') {
      remote = remoteFolders.find(folder => {
        const name = normalizedFolderName(folder.name);
        return name === 'watch later' || name === 'المشاهدة لاحقًا';
      });
    }
    if (!remote) {
      const created = await publicRequest.post('saved-folders', {
        name: requestedName,
        client_request_id: secureRandomUuid(),
      });
      const createdPayload = created?.data?.data;
      remote = mapSavedFolder(createdPayload?.data ?? createdPayload ?? {});
      if (!remote.id) throw new Error('SAVED_FOLDER_CREATE_FAILED');
      remoteFolders.push(remote);
    }
    idMap.set(localId, remote.id);
  }

  for (const [localId, lessonIds] of Object.entries(
    state.savedFolderLessons,
  )) {
    const remoteId = idMap.get(localId);
    if (!remoteId) continue;
    for (const lessonId of lessonIds) {
      if (!/^\d+$/.test(lessonId)) continue;
      await publicRequest.post(`saved-folders/${remoteId}/lessons`, {
        lesson_id: lessonId,
      });
    }
  }

  if (
    !(await hasSession()) ||
    (await getCurrentAccountStorageScope()) !== accountScope
  ) {
    throw new Error('ACCOUNT_CHANGED_DURING_GUEST_MIGRATION');
  }

  await updatePlayerStateForScope(accountScope, current => {
    const remapped: Record<string, string[]> = {};
    Object.entries(current.savedFolderLessons).forEach(
      ([folderId, lessonIds]) => {
        const targetId = idMap.get(folderId) || folderId;
        remapped[targetId] = Array.from(
          new Set([...(remapped[targetId] || []), ...lessonIds]),
        );
      },
    );
    return {...current, savedFolderLessons: remapped};
  });
  await writeLocalSavedFolders(
    remoteFolders,
    `${SAVED_FOLDERS_KEY}:${accountScope}`,
  );
  await AsyncStorage.removeItem(guestFoldersKey(guestScope));
  return true;
};

export const getSavedFolderOptions = async (): Promise<SavedFolderOption[]> => {
  const accountScope = await getCurrentAccountStorageScope();
  if (!(await hasSession())) {
    return readLocalSavedFolders(accountScope);
  }
  try {
    const response = await publicRequest.get('saved-folders');
    const folderPayload = response?.data?.data;
    const folders = asArray<SavedFolderDto>(
      folderPayload?.data ?? folderPayload,
    )
      .filter(item => item?.id && item?.name)
      .map(mapSavedFolder);
    await assertCurrentScope(accountScope);
    await writeLocalSavedFolders(
      folders,
      `${SAVED_FOLDERS_KEY}:${accountScope}`,
    );
    return folders;
  } catch {
    // Offline accounts may use cached server folders, not local-only ids.
    const cached = (await readLocalSavedFolders(accountScope)).filter(
      folder => !folder.id.startsWith('local-'),
    );
    if (cached.length) return cached;
    throw new Error('SAVED_FOLDERS_UNAVAILABLE');
  }
};

export const createSavedFolderOption = async (
  rawName: string,
): Promise<SavedFolderOption> => {
  const accountScope = await getCurrentAccountStorageScope();
  const name = rawName.trim().slice(0, 60);
  if (!name) throw new Error('FOLDER_NAME_REQUIRED');
  if (!(await hasSession())) {
    const created = {id: `local-${secureRandomUuid()}`, name};
    await assertCurrentScope(accountScope);
    const current = await readLocalSavedFolders(accountScope);
    await writeLocalSavedFolders(
      [...current, created],
      `${SAVED_FOLDERS_KEY}:${accountScope}`,
    );
    return created;
  }
  const response = await publicRequest.post('saved-folders', {
    name,
    client_request_id: secureRandomUuid(),
  });
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
  await assertCurrentScope(accountScope);
  const current = await readLocalSavedFolders(accountScope);
  await writeLocalSavedFolders([
    ...current.filter(
      item => !item.id.startsWith('local-') && item.id !== created.id,
    ),
    created,
  ], `${SAVED_FOLDERS_KEY}:${accountScope}`);
  return created;
};

export const deleteSavedFolderOption = async (folderId: string) => {
  const accountScope = await getCurrentAccountStorageScope();
  const sessionAvailable = await hasSession();
  if (sessionAvailable && !folderId.startsWith('local-')) {
    await publicRequest.delete(`saved-folders/${folderId}`);
  }

  await assertCurrentScope(accountScope);
  const current = await readLocalSavedFolders(accountScope);
  await writeLocalSavedFolders(
    current.filter(folder => folder.id !== folderId),
    `${SAVED_FOLDERS_KEY}:${accountScope}`,
  );
  await updatePlayerStateForScope(accountScope, state => {
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
  const accountScope = await getCurrentAccountStorageScope();
  const sessionAvailable = await hasSession();
  if (sessionAvailable && !isLocalDemoId(lessonId)) {
    if (folder.id.startsWith('local-')) {
      throw new Error('UNSYNCED_SAVED_FOLDER');
    }
    // For real accounts the server is authoritative. Do not show a success
    // that disappears on another device or after the next refresh.
    await publicRequest.post(`saved-folders/${folder.id}/lessons`, {
      lesson_id: lessonId,
    });
  }
  await assertCurrentScope(accountScope);
  await updatePlayerStateForScope(accountScope, state => ({
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
  const accountScope = await getCurrentAccountStorageScope();
  const nextSaved = !currentlySaved;
  const sessionAvailable = !isLocalDemoId(lessonId) && (await hasSession());
  let targetFolderId = 'local-watch-later';

  if (sessionAvailable) {
    if (!nextSaved) {
      await publicRequest.delete(`saved-lessons/${lessonId}`);
    } else {
      const folderId = await ensureWatchLaterFolder(accountScope);
      if (!folderId) throw new Error('WATCH_LATER_FOLDER_UNAVAILABLE');
      await publicRequest.post(`saved-folders/${folderId}/lessons`, {
        lesson_id: lessonId,
      });
      targetFolderId = folderId;
    }
  }

  await assertCurrentScope(accountScope);
  await updatePlayerStateForScope(accountScope, state => ({
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

/**
 * Reconciles bookmark icons with the server in one bounded request per feed.
 * This removes stale device-local state after a save or delete on another device.
 */
export const reconcileServerSavedLessons = async (
  rawLessonIds: string[],
): Promise<string[]> => {
  const accountScope = await getCurrentAccountStorageScope();
  if (!(await hasSession())) {
    return (await readPlayerState()).savedLessons;
  }
  const lessonIds = Array.from(
    new Set(rawLessonIds.filter(id => /^\d+$/.test(id))),
  );
  if (!lessonIds.length) return [];

  const saved = new Set<string>();
  for (let offset = 0; offset < lessonIds.length; offset += 200) {
    const chunk = lessonIds.slice(offset, offset + 200);
    const response = await publicRequest.get('saved-lessons/state', {
      params: {lesson_ids: chunk},
    });
    const ids = asArray<unknown>(response?.data?.data?.saved_lesson_ids);
    ids.forEach(id => {
      const value = valueAsString(id);
      if (/^\d+$/.test(value)) saved.add(value);
    });
  }

  await assertCurrentScope(accountScope);
  const queried = new Set(lessonIds);
  await updatePlayerStateForScope(accountScope, state => ({
    ...state,
    savedLessons: Array.from(
      new Set([
        ...state.savedLessons.filter(id => !queried.has(id)),
        ...saved,
      ]),
    ),
    savedFolderLessons: Object.fromEntries(
      Object.entries(state.savedFolderLessons)
        .map(([folderId, ids]) => [
          folderId,
          ids.filter(id => !queried.has(id) || saved.has(id)),
        ] as [string, string[]])
        .filter(([, ids]) => ids.length > 0),
    ),
  }));

  return Array.from(saved);
};

export const removeLessonFromSavedFolder = async (
  lessonId: string,
  folderId: string,
) => {
  const accountScope = await getCurrentAccountStorageScope();
  const sessionAvailable = await hasSession();
  if (sessionAvailable && !isLocalDemoId(lessonId)) {
    if (!/^\d+$/.test(folderId) || !/^\d+$/.test(lessonId)) {
      throw new Error('INVALID_SAVED_LESSON_ROUTE');
    }
    // The server commits first. A failed delete must not disappear locally and
    // then reappear on the next refresh or another device.
    await publicRequest.delete(
      `saved-folders/${folderId}/lessons/${lessonId}`,
    );
  }
  await assertCurrentScope(accountScope);
  return updatePlayerStateForScope(accountScope, state => {
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
};
