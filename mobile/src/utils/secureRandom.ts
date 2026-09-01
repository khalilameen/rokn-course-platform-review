type ExpoCryptoModule = {
  randomUUID: () => string;
};

/**
 * Resolve the native generator only when an operation actually needs a new
 * identifier. Pure API-contract modules can then be imported by tests and
 * tooling without booting an Expo native module.
 */
export const secureRandomUuid = (): string => {
  const crypto = require('expo-crypto') as ExpoCryptoModule;
  const value = crypto.randomUUID().toLowerCase();
  if (
    !/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(
      value,
    )
  ) {
    throw new Error('SECURE_RANDOM_UUID_UNAVAILABLE');
  }

  return value;
};
