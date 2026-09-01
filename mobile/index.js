/**
 * @format
 */

import {registerRootComponent} from 'expo';
import React, {useEffect, useState} from 'react';
import {I18nManager} from 'react-native';
import {Provider} from 'react-redux';
import {store, persistor} from './src/store/store';
import {GestureHandlerRootView} from 'react-native-gesture-handler';
import {SafeAreaProvider} from 'react-native-safe-area-context';
import {BottomSheetModalProvider} from '@gorhom/bottom-sheet';
import App from './App';
import AppErrorBoundary from './src/components/ui/AppErrorBoundary';
import Splash from './src/screens/Splash';
import {installGlobalErrorReporting} from './src/services/operationalTelemetry';

// Rokn's shipping interface is Arabic-first. Apply RTL before the first React
// tree is mounted so navigation, lists and touch targets all agree on direction.
I18nManager.allowRTL(true);
I18nManager.forceRTL(true);
installGlobalErrorReporting();

// AsyncStorage can be temporarily unavailable after an OS restore or while a
// low-storage cleanup is running. Redux Persist has no timeout, so its stock
// gate can otherwise leave a valid installation on the native splash forever.
// Late rehydration is still accepted after the app continues with reducer
// defaults; account credentials are restored independently from SecureStore.
const PersistBootstrapGate = ({children}) => {
  const [ready, setReady] = useState(() => persistor.getState().bootstrapped);
  useEffect(() => {
    if (ready) return undefined;
    if (persistor.getState().bootstrapped) {
      setReady(true);
      return undefined;
    }
    const unsubscribe = persistor.subscribe(() => {
      if (persistor.getState().bootstrapped) setReady(true);
    });
    const watchdog = setTimeout(() => setReady(true), 2500);
    return () => {
      clearTimeout(watchdog);
      unsubscribe();
    };
  }, [ready]);
  return ready ? children : <Splash />;
};

const RNapp = () => {
  return (
    <Provider store={store}>
      <AppErrorBoundary>
        <PersistBootstrapGate>
          <GestureHandlerRootView style={{flex: 1}}>
            <SafeAreaProvider>
              <BottomSheetModalProvider>
                <App />
              </BottomSheetModalProvider>
            </SafeAreaProvider>
          </GestureHandlerRootView>
        </PersistBootstrapGate>
      </AppErrorBoundary>
    </Provider>
  );
};
registerRootComponent(RNapp);
