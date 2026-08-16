import {createAsyncThunk} from '@reduxjs/toolkit';

import {setAppLoaded} from '../reducers/settings';

/**
 * Redux Persist hydrates before this thunk is dispatched.  The action exists
 * only to make the bootstrap transition explicit and observable in devtools.
 */
export const initializApp = createAsyncThunk<boolean, void>(
  'settings/initializeApp',
  async (_, {dispatch}) => {
    dispatch(setAppLoaded(true));
    return true;
  },
);
