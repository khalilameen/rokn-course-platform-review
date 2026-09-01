import {useSyncExternalStore} from 'react';
import {
  AppState,
  Platform,
  type AppStateStatus,
  type NativeEventSubscription,
} from 'react-native';

let currentState: AppStateStatus = AppState.currentState;
let androidWindowFocused = true;
let nativeSubscriptions: NativeEventSubscription[] = [];
const listeners = new Set<() => void>();

const isInteractive = () =>
  currentState === 'active' &&
  (Platform.OS !== 'android' || androidWindowFocused);

const notifyIfChanged = (previous: boolean) => {
  if (previous !== isInteractive()) listeners.forEach(notify => notify());
};

const subscribe = (listener: () => void) => {
  listeners.add(listener);
  if (nativeSubscriptions.length === 0) {
    currentState = AppState.currentState;
    androidWindowFocused = currentState === 'active';
    nativeSubscriptions.push(
      AppState.addEventListener('change', next => {
        if (currentState === next) return;
        const previous = isInteractive();
        currentState = next;
        if (Platform.OS === 'android') {
          // Some Android versions do not emit a separate focus event after a
          // full background transition. The AppState transition is still an
          // authoritative reset; blur/focus then refine active-only overlays.
          androidWindowFocused = next === 'active';
        }
        notifyIfChanged(previous);
      }),
    );
    if (Platform.OS === 'android') {
      // Android keeps AppState as `active` while the notification shade,
      // permission UI or another non-Activity system surface obscures the
      // app. The blur/focus pair is therefore part of playback eligibility,
      // otherwise video and polling continue behind that surface.
      nativeSubscriptions.push(
        AppState.addEventListener('blur', () => {
          const previous = isInteractive();
          androidWindowFocused = false;
          notifyIfChanged(previous);
        }),
        AppState.addEventListener('focus', () => {
          const previous = isInteractive();
          androidWindowFocused = true;
          notifyIfChanged(previous);
        }),
      );
    }
  }
  return () => {
    listeners.delete(listener);
    if (listeners.size === 0 && nativeSubscriptions.length > 0) {
      nativeSubscriptions.forEach(subscription => subscription.remove());
      nativeSubscriptions = [];
    }
  };
};

export const useAppActiveState = () =>
  useSyncExternalStore(
    subscribe,
    isInteractive,
    () => true,
  );
