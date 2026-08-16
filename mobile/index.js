/**
 * @format
 */

import {registerRootComponent} from 'expo';
import {I18nManager} from 'react-native';
import {Provider} from 'react-redux';
import {PersistGate} from 'redux-persist/integration/react';
import {store, persistor} from './src/store/store';
import {GestureHandlerRootView} from 'react-native-gesture-handler';
import {SafeAreaProvider} from 'react-native-safe-area-context';
import {BottomSheetModalProvider} from '@gorhom/bottom-sheet';
import {StackedToastProvider} from './src/components/clerk-toast/stacked-toast-manager';
import App from './App';
import AppErrorBoundary from './src/components/ui/AppErrorBoundary';
import Splash from './src/screens/Splash';
import {installGlobalErrorReporting} from './src/services/operationalTelemetry';

// Rokn's shipping interface is Arabic-first. Apply RTL before the first React
// tree is mounted so navigation, lists and touch targets all agree on direction.
I18nManager.allowRTL(true);
I18nManager.forceRTL(true);
installGlobalErrorReporting();
const RNapp = () => {
  return (
    <Provider store={store}>
      <AppErrorBoundary>
        <PersistGate persistor={persistor} loading={<Splash />}>
          <GestureHandlerRootView style={{flex: 1}}>
            <SafeAreaProvider>
              <BottomSheetModalProvider>
                <StackedToastProvider>
                  <App />
                </StackedToastProvider>
              </BottomSheetModalProvider>
            </SafeAreaProvider>
          </GestureHandlerRootView>
        </PersistGate>
      </AppErrorBoundary>
    </Provider>
  );
};
registerRootComponent(RNapp);
