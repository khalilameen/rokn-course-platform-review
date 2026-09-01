import {useSyncExternalStore} from 'react';
import {AccessibilityInfo} from 'react-native';

let reducedMotion = false;
let observerGeneration = 0;
let nativeSubscription: {remove: () => void} | null = null;
const listeners = new Set<() => void>();

const publish = (next: boolean) => {
  if (reducedMotion === next) return;
  reducedMotion = next;
  listeners.forEach(listener => listener());
};

const startNativeObserver = () => {
  if (nativeSubscription) return;
  const generation = ++observerGeneration;
  nativeSubscription = AccessibilityInfo.addEventListener(
    'reduceMotionChanged',
    publish,
  );
  void AccessibilityInfo.isReduceMotionEnabled()
    .then(value => {
      if (generation === observerGeneration && nativeSubscription) {
        publish(value);
      }
    })
    .catch(() => undefined);
};

const subscribe = (listener: () => void) => {
  listeners.add(listener);
  startNativeObserver();
  return () => {
    listeners.delete(listener);
    if (listeners.size === 0 && nativeSubscription) {
      observerGeneration += 1;
      nativeSubscription.remove();
      nativeSubscription = null;
    }
  };
};

/** Mirrors the OS preference and updates without requiring an app restart. */
export const useReducedMotion = () =>
  useSyncExternalStore(
    subscribe,
    () => reducedMotion,
    () => false,
  );
