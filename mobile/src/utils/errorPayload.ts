export type ErrorPayload = Record<string, unknown>;

export const asRecord = (value: unknown): ErrorPayload | undefined =>
  typeof value === 'object' && value !== null
    ? (value as ErrorPayload)
    : undefined;

export const errorPayload = (error: unknown): ErrorPayload => {
  const root = asRecord(error);
  const response = asRecord(root?.response);
  return asRecord(response?.data) ?? asRecord(root?.data) ?? {};
};

export const errorCode = (error: unknown): string => {
  const root = asRecord(error);
  return String(errorPayload(error).code ?? root?.code ?? '');
};

export const errorMessage = (error: unknown, fallback = ''): string => {
  const payloadMessage = errorPayload(error).message;
  if (typeof payloadMessage === 'string' && payloadMessage.trim()) {
    return payloadMessage;
  }
  return error instanceof Error && error.message ? error.message : fallback;
};
