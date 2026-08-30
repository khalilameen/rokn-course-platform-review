/**
 * Synthetic content is a local-development aid only. Distributed test and
 * store artifacts set the flag to zero so every reviewer exercises the same
 * Laravel, payment, media and AI contracts that production uses.
 */
const buildProfile = process.env.EXPO_PUBLIC_BUILD_PROFILE?.trim();

export const LOCAL_DEMO_ENABLED =
  process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO === '1' && buildProfile === 'test';
