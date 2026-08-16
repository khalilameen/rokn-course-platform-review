export type ApiRecord = Record<string, unknown>;

export const isApiRecord = (value: unknown): value is ApiRecord =>
  typeof value === 'object' && value !== null && !Array.isArray(value);

export const payload = <T = ApiRecord>(response: unknown): T => {
  const responseData = isApiRecord(response) ? response.data : undefined;
  const nestedData = isApiRecord(responseData) ? responseData.data : undefined;
  return (nestedData ?? responseData ?? {}) as T;
};

export const resourceList = <T = ApiRecord>(value: unknown): T[] => {
  if (Array.isArray(value)) return value as T[];
  if (isApiRecord(value) && Array.isArray(value.data)) {
    return value.data as T[];
  }
  return [];
};

export const valueAsBoolean = (...values: unknown[]): boolean =>
  values.some(
    value =>
      value === true ||
      value === 1 ||
      value === '1' ||
      String(value).toLowerCase() === 'true',
  );
