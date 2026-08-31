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

const settingsConfig = {
  key: 'settings',
  storage: AsyncStorage,
  whitelist: ['language', 'city_name'],
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
