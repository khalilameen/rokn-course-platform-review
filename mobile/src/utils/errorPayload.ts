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

/**
 * The API interceptor returns an Axios response directly when the server
 * answered, while transport adapters and native mocks can keep it under
 * `response`. Every recovery decision must understand both shapes; otherwise
 * a real 401/404/409 is mistaken for an offline failure and retried forever.
 */
export const errorStatus = (error: unknown): number => {
  const root = asRecord(error);
  const response = asRecord(root?.response);
  const payload = errorPayload(error);
  const value = Number(response?.status ?? root?.status ?? payload.status ?? 0);
  return Number.isInteger(value) && value >= 100 && value <= 599 ? value : 0;
};

export const errorMessage = (error: unknown, fallback = ''): string => {
  const payloadMessage = errorPayload(error).message;
  if (typeof payloadMessage === 'string' && payloadMessage.trim()) {
    return payloadMessage;
  }
  return error instanceof Error && error.message ? error.message : fallback;
};

const hasArabic = (value: string) => /[\u0600-\u06ff]/.test(value);

const diagnosticPattern =
  /(?:https?:\/\/|www\.|sqlstate|exception|stack\s*trace|\bat\s+[^\s]+\.(?:php|tsx?|jsx?):\d+|[a-z]:\\|\/var\/|\/app\/|axios|openrouter|bunny(?:cdn)?|kashier|firebase|oauth|pkce|google\s*play|app\s*store|storekit|billingclient|authorization(?:signature|expire)?|access[_ -]?key|api[_ -]?key|الخادم|السيرفر|\b[A-Z][A-Z0-9_]{2,}\b)/i;

/**
 * Converts trusted Arabic API copy into the same small visual language used by
 * the app. Provider diagnostics and machine codes always stay behind the UI.
 */
export const learnerFacingText = (value: unknown, fallback = ''): string => {
  const clean = (candidate: unknown) =>
    String(candidate || '')
      .replace(/<[^>]*>/g, ' ')
      .replace(/\p{Cc}/gu, character =>
        ['\n', '\r', '\t'].includes(character) ? character : ' ',
      )
      .trim();
  const normalize = (candidate: string) =>
    candidate
      .replace(/\r\n?/g, '\n')
      .replace(/[,،;؛:]+/g, '\n')
      .replace(/([^\d])\.(?=\s|$)/g, '$1\n')
      .replace(/\?/g, '؟')
      .split('\n')
      .map(line => line.replace(/\s+/g, ' ').trim())
      .filter(Boolean)
      .slice(0, 3)
      .join('\n')
      .slice(0, 240)
      .trim();
  const safeFallback = clean(fallback);
  const normalizedFallback =
    safeFallback &&
    hasArabic(safeFallback) &&
    !diagnosticPattern.test(safeFallback)
      ? normalize(safeFallback)
      : '';
  const raw = clean(value);

  if (!raw || !hasArabic(raw) || diagnosticPattern.test(raw)) {
    return normalizedFallback;
  }

  return normalize(raw) || normalizedFallback;
};

/**
 * API diagnostics are not product copy. Only pass through an Arabic message
 * that is already suitable for the learner; otherwise keep the screen-owned
 * fallback so an English controller message never leaks into the interface.
 */
export const learnerErrorMessage = (
  error: unknown,
  fallback: string,
): string => {
  const payload = errorPayload(error);
  const errors = asRecord(payload.errors);
  const candidates = [
    ...(errors
      ? Object.values(errors).flatMap(value =>
          Array.isArray(value) ? value : [value],
        )
      : []),
    payload.message,
  ];
  const localized = candidates
    .map(value => learnerFacingText(value))
    .find(Boolean);
  return localized || learnerFacingText(fallback) || 'تعذّر إكمال الطلب';
};
