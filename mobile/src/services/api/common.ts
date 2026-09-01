export type ApiRecord = Record<string, unknown>;

export const isApiRecord = (value: unknown): value is ApiRecord =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

const hasOwnData = (value: ApiRecord): boolean =>
  Object.prototype.hasOwnProperty.call(value, 'data');

/** Returns the HTTP body without guessing at the endpoint's resource shape. */
export const responseEnvelope = (response: unknown): ApiRecord => {
  if (!isApiRecord(response) || !hasOwnData(response)) return {};
  return isApiRecord(response.data) ? response.data : {};
};

export const payload = <T = ApiRecord>(response: unknown): T => {
  if (!isApiRecord(response) || !hasOwnData(response)) return {} as T;

  // Axios wraps the API body once and Rokn's body wraps endpoint data once.
  // Presence, not truthiness, matters: a successful 202/204-style response
  // deliberately carries `data: null`, while collections may be arrays.
  const responseData = response.data;
  if (isApiRecord(responseData) && hasOwnData(responseData)) {
    return responseData.data as T;
  }

  return responseData as T;
};

export const resourceList = <T = ApiRecord>(value: unknown): T[] => {
  if (Array.isArray(value)) return value as T[];
  if (isApiRecord(value) && Array.isArray(value.data)) {
    return value.data as T[];
  }
  return [];
};

export const isResourceListPayload = (value: unknown): boolean =>
  Array.isArray(value) ||
  (isApiRecord(value) && Array.isArray(value.data));

export const valueAsBoolean = (...values: unknown[]): boolean =>
  values.some(
    value =>
      value === true ||
      value === 1 ||
      value === '1' ||
      String(value).toLowerCase() === 'true',
  );

/** Reads aliases in priority order. Unlike `valueAsBoolean`, a newer false
 * cannot be overridden by a stale legacy true field later in the payload. */
export const firstBoolean = (...values: unknown[]): boolean | undefined => {
  for (const value of values) {
    if (value === true || value === 1 || value === '1') return true;
    if (
      value === false ||
      value === 0 ||
      value === '0' ||
      String(value).toLowerCase() === 'false'
    ) {
      return false;
    }
  }
  return undefined;
};

export const nonNegativeNumber = (value: unknown): number | null => {
  if (value === null || value === undefined || value === '') return null;
  const parsed = Number(value);
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : null;
};

export const requireNonNegativeNumber = (
  value: unknown,
  field: string,
): number => {
  const parsed = nonNegativeNumber(value);
  if (parsed === null) throw new Error(`API_CONTRACT_INVALID_${field}`);
  return parsed;
};
