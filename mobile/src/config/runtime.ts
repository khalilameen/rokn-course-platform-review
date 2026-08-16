/**
 * The direct test APK is allowed to carry the local, clearly synthetic course
 * so stakeholders can inspect the complete learning journey before the API is
 * deployed. Release and Play builds can never enable it, even if a stale
 * machine environment accidentally exports the opt-in flag.
 */
const buildProfile = process.env.EXPO_PUBLIC_BUILD_PROFILE?.trim();

export const LOCAL_DEMO_ENABLED =
  process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO === '1' && buildProfile === 'test';
