let serverAnchorMs: number | null = null;
let monotonicAnchorMs: number | null = null;

const monotonicNow = (): number => {
  const value = globalThis.performance?.now?.();
  return Number.isFinite(value) ? Number(value) : Date.now();
};

/**
 * Calibrate from a trusted response instant. Once calibrated, elapsed time is
 * measured with the process monotonic clock so device clock edits and rollback
 * cannot extend a cooldown or a signed URL inside the running process.
 */
export const observeServerTime = (value: unknown): void => {
  const parsed = Date.parse(String(value || ''));
  if (!Number.isFinite(parsed)) return;
  const observedAt = monotonicNow();
  const currentEstimate =
    serverAnchorMs === null || monotonicAnchorMs === null
      ? null
      : serverAnchorMs + Math.max(0, observedAt - monotonicAnchorMs);
  // Responses can finish out of order. Never let an older Date header move
  // the authority clock backwards and silently extend an expiry or cooldown.
  serverAnchorMs =
    currentEstimate === null ? parsed : Math.max(parsed, currentEstimate);
  monotonicAnchorMs = observedAt;
};

export const serverNowMs = (): number => {
  if (serverAnchorMs === null || monotonicAnchorMs === null) return Date.now();
  return serverAnchorMs + Math.max(0, monotonicNow() - monotonicAnchorMs);
};

export const serverNow = (): Date => new Date(serverNowMs());

export const deadlineFromServerTtl = (seconds: unknown): string | undefined => {
  const value = Number(seconds);
  if (!Number.isFinite(value) || value < 0) return undefined;
  return new Date(serverNowMs() + value * 1000).toISOString();
};

export const remainingServerMilliseconds = (value?: string): number | null => {
  const parsed = Date.parse(String(value || ''));
  return Number.isFinite(parsed) ? parsed - serverNowMs() : null;
};

export const isServerTimestampFresh = (
  value: unknown,
  maxAgeMs: number,
): boolean => {
  const timestamp = Number(value);
  if (!Number.isFinite(timestamp) || timestamp <= 0) return false;
  const age = serverNowMs() - timestamp;
  return age >= 0 && age <= Math.max(0, maxAgeMs);
};
