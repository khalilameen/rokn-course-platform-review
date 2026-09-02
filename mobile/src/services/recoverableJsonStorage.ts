import AsyncStorage from '@react-native-async-storage/async-storage';

/**
 * Read a durable JSON value without turning damaged bytes into authoritative
 * empty state. The original is retired only after an exact recovery copy is
 * durable; a full device therefore leaves the source untouched and retries
 * safely on the next launch.
 */
export const readJsonOrQuarantine = async <T>(
  storageKey: string,
  fallback: () => T,
  decode: (value: unknown) => T | null,
): Promise<T> => {
  const raw = await AsyncStorage.getItem(storageKey);
  if (!raw) return fallback();

  try {
    const decoded = decode(JSON.parse(raw));
    if (decoded !== null) return decoded;
  } catch {
    // Quarantine below. Keeping this path together with structural failures
    // prevents future writers from silently replacing an unreadable queue.
  }

  await AsyncStorage.setItem(`${storageKey}:corrupt`, raw);
  await AsyncStorage.removeItem(storageKey);
  return fallback();
};
