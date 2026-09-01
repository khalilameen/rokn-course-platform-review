/**
 * Synthetic content is a local-development aid only. Distributed test and
 * store artifacts set the flag to zero so every reviewer exercises the same
 * Laravel, payment, media and AI contracts that production uses.
 */
const buildProfile = process.env.EXPO_PUBLIC_BUILD_PROFILE?.trim();

export const LOCAL_DEMO_ENABLED =
  __DEV__ &&
  process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO === '1' &&
  buildProfile === 'test';

/** A demo-shaped identifier is synthetic only in an explicit local test build. */
export const isLocalDemoId = (value: unknown) =>
  LOCAL_DEMO_ENABLED && String(value || '').startsWith('demo');
