const originalDemoFlag = process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO;
const originalBuildProfile = process.env.EXPO_PUBLIC_BUILD_PROFILE;

const loadDemoFlag = () => {
  jest.resetModules();
  return require('../src/config/runtime').LOCAL_DEMO_ENABLED as boolean;
};

describe('runtime profile safeguards', () => {
  afterEach(() => {
    process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = originalDemoFlag;
    process.env.EXPO_PUBLIC_BUILD_PROFILE = originalBuildProfile;
  });

  it('enables the synthetic course only for an explicit test build', () => {
    process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '1';
    process.env.EXPO_PUBLIC_BUILD_PROFILE = 'test';

    expect(loadDemoFlag()).toBe(true);
  });

  it.each([undefined, 'development', 'preview', 'production'])(
    'fails closed for the %s build profile',
    profile => {
      process.env.EXPO_PUBLIC_ENABLE_LOCAL_DEMO = '1';
      if (profile === undefined) {
        delete process.env.EXPO_PUBLIC_BUILD_PROFILE;
      } else {
        process.env.EXPO_PUBLIC_BUILD_PROFILE = profile;
      }

      expect(loadDemoFlag()).toBe(false);
    },
  );
});
