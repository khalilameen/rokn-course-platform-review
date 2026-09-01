import {createSlice, PayloadAction} from '@reduxjs/toolkit';

export type AppLanguage = string | {code?: string};

export interface SettingsState {
  language: AppLanguage;
  appLoaded: boolean;
}

const initialState: SettingsState = {
  language: '',
  appLoaded: false,
};

const settingsSlice = createSlice({
  name: 'settings',
  initialState,
  reducers: {
    setLanguage: (state, action: PayloadAction<AppLanguage>) => {
      state.language = action.payload;
    },
    setAppLoaded: (state, action: PayloadAction<boolean>) => {
      state.appLoaded = action.payload;
    },
    EmptyAppLoaded: state => {
      state.appLoaded = false;
    },
  },
});

export const {
  setLanguage,
  EmptyAppLoaded,
  setAppLoaded,
} = settingsSlice.actions;

export default settingsSlice.reducer;
