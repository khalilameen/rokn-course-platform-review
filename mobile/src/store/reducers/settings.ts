import {createSlice, PayloadAction} from '@reduxjs/toolkit';

export type AppLanguage = string | {code?: string};

export interface SettingsState {
  language: AppLanguage;
  city_name: string;
  onboarding: boolean;
  device_token: string;
  voip_token: string;
  appLoaded: boolean;
}

const initialState: SettingsState = {
  language: '',
  city_name: '',
  onboarding: false,
  device_token: '',
  voip_token: '',
  appLoaded: false,
};

const settingsSlice = createSlice({
  name: 'settings',
  initialState,
  reducers: {
    setLanguage: (state, action: PayloadAction<AppLanguage>) => {
      state.language = action.payload;
    },
    setCityName: (state, action: PayloadAction<string>) => {
      state.city_name = action.payload;
    },
    FinishOnBoarding: (state, action: PayloadAction<boolean>) => {
      state.onboarding = action.payload;
    },
    setDeviceToken: (state, action: PayloadAction<string>) => {
      state.device_token = action.payload;
    },
    setVoipToken: (state, action: PayloadAction<string>) => {
      state.voip_token = action.payload;
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
  setCityName,
  setDeviceToken,
  setVoipToken,
  FinishOnBoarding,
  EmptyAppLoaded,
  setAppLoaded,
} = settingsSlice.actions;

export default settingsSlice.reducer;
