import {configureStore} from '@reduxjs/toolkit';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {combineReducers} from 'redux';
import {
  FLUSH,
  PAUSE,
  PERSIST,
  PURGE,
  REGISTER,
  REHYDRATE,
  type PersistorOptions,
  persistReducer,
  persistStore,
} from 'redux-persist';

import auth from './reducers/auth';
import settings from './reducers/settings';
import {migrateLegacySettings} from '../services/storageUpgrade';

const settingsStorage = {
  getItem: async (key: string) => {
    // Redux Persist hydrates before AppInitializer mounts. Run the tiny,
    // device-only settings repair here so an upgrade does not need a second
    // launch to adopt the previous language or escape a partial JSON write.
    await migrateLegacySettings().catch(() => false);
    const raw = await AsyncStorage.getItem(key);
    if (!raw || key !== 'persist:settings-v2') return raw;
    try {
      const parsed = JSON.parse(raw) as Record<string, unknown>;
      if (
        typeof parsed.language !== 'string' ||
        typeof parsed._persist !== 'string'
      ) {
        return null;
      }
      // Old reducers persisted runtime-only fields such as appLoaded. Never
      // hydrate those into a newer process; reducers own their launch defaults.
      return JSON.stringify({
        language: parsed.language,
        _persist: parsed._persist,
      });
    } catch {
      return null;
    }
  },
  setItem: (key: string, value: string) => AsyncStorage.setItem(key, value),
  removeItem: (key: string) => AsyncStorage.removeItem(key),
};

const settingsConfig = {
  key: 'settings-v2',
  storage: settingsStorage,
  version: 2,
  // Locale is device preference. The retired city field was user-derived and
  // must not rehydrate for the next account on a shared phone.
  whitelist: ['language'],
};
const rootReducer = combineReducers({
  // The session is restored from SecureStore; auth stays out of AsyncStorage.
  auth,
  settings: persistReducer(settingsConfig, settings),
});

export const store = configureStore({
  reducer: rootReducer,
  middleware: getDefaultMiddleware =>
    getDefaultMiddleware({
      serializableCheck: {
        ignoredActions: [FLUSH, REHYDRATE, PAUSE, PERSIST, PURGE, REGISTER],
      },
    }),
});
const testPersistorOptions: PersistorOptions & {manualPersist: boolean} = {
  manualPersist: true,
};
export const persistor =
  process.env.NODE_ENV === 'test'
    ? persistStore(store, testPersistorOptions)
    : persistStore(store);
export type RootState = ReturnType<typeof rootReducer>;
export type AppDispatch = typeof store.dispatch;
