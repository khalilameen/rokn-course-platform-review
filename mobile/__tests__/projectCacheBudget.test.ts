jest.mock('react-native-fs', () => ({}));

import {
  assertPendingProjectCacheCapacity,
  PENDING_PROJECT_FILES_MAX_BYTES,
} from '../src/config/projects';

describe('pending project cache budget', () => {
  it('accepts a replacement that remains inside the 75 MiB queue budget', () => {
    expect(() =>
      assertPendingProjectCacheCapacity(
        PENDING_PROJECT_FILES_MAX_BYTES - 25 * 1024 * 1024,
        25 * 1024 * 1024,
      ),
    ).not.toThrow();
  });

  it('refuses a new file instead of deleting unsent submissions', () => {
    expect(() =>
      assertPendingProjectCacheCapacity(
        PENDING_PROJECT_FILES_MAX_BYTES - 10,
        11,
      ),
    ).toThrow('PROJECT_PENDING_CACHE_FULL');
  });
});
