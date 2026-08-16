export function isFollowCoinMethod(actionKey?: string) {
  return (
    actionKey === 'follow_instagram' ||
    actionKey === 'follow_facebook' ||
    actionKey?.startsWith('follow_') === true
  );
}

type CoinMethodRecord = Record<string, unknown>;

const asRecord = (value: unknown): CoinMethodRecord | undefined =>
  typeof value === 'object' && value !== null
    ? (value as CoinMethodRecord)
    : undefined;

export function isCoinMethodClaimed(method: unknown) {
  const record = asRecord(method);
  return Boolean(
    record?.is_consumed ??
      record?.is_claimed ??
      record?.claimed ??
      record?.is_completed,
  );
}

export function getFollowUrl(method: unknown, settings: unknown) {
  const methodRecord = asRecord(method);
  const fromMethod =
    methodRecord?.action_url ?? methodRecord?.url ?? methodRecord?.link;
  if (typeof fromMethod === 'string' && fromMethod) {
    return fromMethod;
  }

  const settingsRecord = asRecord(settings);
  const outerData = asRecord(settingsRecord?.data);
  const settingsData = asRecord(outerData?.data) ?? outerData ?? settingsRecord;

  if (methodRecord?.action_key === 'follow_facebook') {
    const url = settingsData?.facebook ?? settingsData?.facebook_url;
    return typeof url === 'string' ? url : null;
  }

  if (methodRecord?.action_key === 'follow_instagram') {
    const url = settingsData?.instagram ?? settingsData?.instagram_url;
    return typeof url === 'string' ? url : null;
  }

  return null;
}
