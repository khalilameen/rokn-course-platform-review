import AsyncStorage from '@react-native-async-storage/async-storage';
import {secureRandomUuid} from '../utils/secureRandom';

const INSTALLATION_ID_KEY = '@rokn/installation-id/v1';
const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

let installationIdPromise: Promise<string | null> | null = null;

export const getInstallationId = () => {
  if (!installationIdPromise) {
    const flight = (async () => {
      try {
        const stored = String(
          (await AsyncStorage.getItem(INSTALLATION_ID_KEY)) || '',
        ).toLowerCase();
        if (UUID_PATTERN.test(stored)) return stored;

        const created = secureRandomUuid();
        await AsyncStorage.setItem(INSTALLATION_ID_KEY, created);
        return created;
      } catch {
        return null;
      }
    })();
    installationIdPromise = flight;
    void flight.then(value => {
      if (!value && installationIdPromise === flight) {
        installationIdPromise = null;
      }
    });
  }
  return installationIdPromise;
};
