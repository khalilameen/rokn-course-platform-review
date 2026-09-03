import AsyncStorage from '@react-native-async-storage/async-storage';
import {roknCalendarDay} from '../../../constants/roknCalendar';
import {deadlineFromServerTtl} from '../../../utils/serverClock';
import {AppState, Dimensions, Platform} from 'react-native';
import appConfig from '../../../../app.json';
import {isLocalDemoId} from '../../../config/runtime';
import {publicRequest} from '../../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {requireProductFeature} from '../../../services/productFeatures';
import {hasSession} from '../../../services/roknApi';
import type {VideoQuality, VideoQualitySources} from '../types';
import {
  type PlaybackDiagnostics,
  type PlaybackEndReason,
  type PlaybackLifecycleEventType,
  sanitizePlaybackDiagnostics,
  sanitizePlaybackErrorCode,
} from '../playbackTelemetry';
import {
  isWatchHistoryEnabled,
  updatePlayerStateForScope,
} from './persistence';
import {qualityOptions, qualitySources, valueAsString} from './shared';

const SECTION_COMPLETION_PREFIX = '@rokn/section-completion/v1';
const WATCH_EVIDENCE_PREFIX = '@rokn/watch-evidence/v1';
const WATCH_HISTORY_SYNC_INTERVAL_MS = 30_000;

type PendingWatchHistory = {
  lessonId: number;
  positionSeconds: number;
  durationSeconds?: number;
  completed: boolean;
  playbackSessionId?: string;
  sequence?: number;
  effectiveQuality?: VideoQuality;
  effectiveBitrateKbps?: number;
  playbackRate?: number;
  recoveryCount?: number;
  bufferCount?: number;
  bufferDurationMs?: number;
  startupLatencyMs?: number;
  eventType?: PlaybackLifecycleEventType;
  endReason?: PlaybackEndReason;
  errorCode?: string;
  diagnostics?: PlaybackDiagnostics;
};
const pendingWatchHistory = new Map<string, PendingWatchHistory>();
const watchHistoryTimers = new Map<string, ReturnType<typeof setTimeout>>();
const watchHistoryFlights = new Map<string, Promise<void>>();
const watchEvidenceWriteQueues = new Map<string, Promise<void>>();
const watchHistoryLastSyncedAt = new Map<string, number>();
const playbackSequences = new Map<string, number>();
const playbackRequestQueues = new Map<string, Promise<unknown>>();
const sectionCompletionFlights = new Map<string, Promise<boolean>>();
const MAX_RECENT_WATCH_SYNC_KEYS = 128;
let playbackRuntimeGeneration = 0;

export type CourseRevisionChange = {
  courseId: string;
  sourceLessonId?: string;
  currentLessonId?: string;
  currentSectionId?: string;
};

const courseRevisionListeners = new Set<
  (change: CourseRevisionChange) => void
>();

export const subscribeCourseRevisionChanges = (
  listener: (change: CourseRevisionChange) => void,
) => {
  courseRevisionListeners.add(listener);
  return () => {
    courseRevisionListeners.delete(listener);
  };
};

const publishCourseRevisionChange = (
  response: unknown,
  sourceLessonId?: string,
) => {
  const candidate = response as {
    data?: Record<string, unknown>;
    response?: {data?: Record<string, unknown>};
  };
  const envelope = candidate?.response?.data || candidate?.data;
  const raw = (envelope?.data || envelope) as
    | Record<string, unknown>
    | undefined;
  const revisionChanged =
    raw?.course_revision_changed === true ||
    String(envelope?.code || '').toLowerCase() === 'course_revision_changed';
  if (!revisionChanged || !raw) return;
  const courseId = String(raw.course_id || '').trim();
  if (!courseId) return;
  const change: CourseRevisionChange = {
    courseId,
    ...(sourceLessonId ? {sourceLessonId} : {}),
    ...(raw.current_lesson_id !== null &&
    raw.current_lesson_id !== undefined
      ? {currentLessonId: String(raw.current_lesson_id)}
      : {}),
    ...(raw.current_section_id !== null &&
    raw.current_section_id !== undefined
      ? {currentSectionId: String(raw.current_section_id)}
      : {}),
  };
  courseRevisionListeners.forEach(listener => {
    try {
      listener(change);
    } catch {
      // A screen observer must never turn a committed heartbeat into a retry.
    }
  });
};

const isPendingWatchHistory = (
  value: unknown,
): value is PendingWatchHistory => {
  const pending = value as Partial<PendingWatchHistory> | null;
  return Boolean(
    pending &&
      Number.isFinite(pending.lessonId) &&
      Number.isFinite(pending.positionSeconds) &&
      typeof pending.completed === 'boolean',
  );
};

const mergePendingWatchHistory = (
  previous: PendingWatchHistory | undefined,
  next: PendingWatchHistory,
): PendingWatchHistory =>
  previous?.completed
    ? previous
    : {
        ...next,
        // Resume means the latest observed position, not the furthest point
        // reached. A learner can rewind and leave before the 30-second flush;
        // keeping max(position) would make the next launch jump forward again
        // and also disagrees with WatchHistoryController's ordered-session
        // contract.
        positionSeconds: next.positionSeconds,
        durationSeconds:
          Math.max(
            previous?.durationSeconds || 0,
            next.durationSeconds || 0,
          ) || undefined,
        completed: previous?.completed === true || next.completed,
        eventType:
          previous?.completed === true || next.completed
            ? 'complete'
            : next.eventType,
      };

export type PlaybackEvidenceContext = {
  playbackSessionId?: string;
  effectiveQuality?: VideoQuality;
  effectiveBitrateKbps?: number;
  playbackRate?: number;
  recoveryCount?: number;
  bufferCount?: number;
  bufferDurationMs?: number;
  startupLatencyMs?: number;
  diagnostics?: PlaybackDiagnostics;
};

export type PlaybackClientPreference = {
  dataSaver?: boolean;
  maxBitrateKbps?: number;
  playbackSessionId?: string;
};

export type PlaybackSessionEvent = PlaybackEvidenceContext & {
  lessonId: string;
  playbackSessionId: string;
  eventType: PlaybackLifecycleEventType;
  positionSeconds: number;
  durationSeconds?: number;
  completed?: boolean;
  endReason?: PlaybackEndReason;
  errorCode?: string;
};

export type PlaybackManifest = {
  playbackSessionId: string;
  sourceUrl: string;
  fallbackUrl?: string;
  posterUrl?: string;
  protocol: 'hls' | 'dash' | 'mp4' | 'unknown';
  expiresAt?: string;
  refreshAfter?: string;
  durationSeconds?: number;
  availableQualities: VideoQuality[];
  qualitySources: VideoQualitySources;
  mediaStatus: 'ready' | 'processing' | 'failed' | 'unknown';
};

const localDeadlineFromTtl = (value: unknown): string | undefined => {
  return deadlineFromServerTtl(value);
};

const watchEvidenceStorageKey = (key: string) =>
  `${WATCH_EVIDENCE_PREFIX}:${key}`;

const assertWatchHistoryOwner = (
  generation: number,
  boundary: AccountSessionBoundary,
) => {
  if (generation !== playbackRuntimeGeneration) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
  assertAccountSessionBoundary(boundary);
};

const hydratePendingWatchEvidence = async (
  boundary: AccountSessionBoundary,
  generation: number,
) => {
  assertWatchHistoryOwner(generation, boundary);
  const scopePrefix = `${WATCH_EVIDENCE_PREFIX}:${boundary.scope}:`;
  const keys = (await AsyncStorage.getAllKeys()).filter(key =>
    key.startsWith(scopePrefix),
  );
  assertWatchHistoryOwner(generation, boundary);
  if (!keys.length) return;
  const entries = await AsyncStorage.multiGet(keys);
  assertWatchHistoryOwner(generation, boundary);
  entries.forEach(([storageKey, raw]) => {
    assertWatchHistoryOwner(generation, boundary);
    if (!raw) return;
    try {
      const pending = JSON.parse(raw) as PendingWatchHistory;
      if (isPendingWatchHistory(pending)) {
        const key = storageKey.slice(`${WATCH_EVIDENCE_PREFIX}:`.length);
        if (!pendingWatchHistory.has(key)) {
          pendingWatchHistory.set(key, pending);
        }
        if (pending.playbackSessionId && pending.sequence) {
          playbackSequences.set(
            pending.playbackSessionId,
            Math.max(
              playbackSequences.get(pending.playbackSessionId) || 0,
              pending.sequence,
            ),
          );
        }
      }
    } catch {
      void AsyncStorage.removeItem(storageKey);
    }
  });
};

const scheduleWatchHistoryFlush = (key: string, delay: number) => {
  const existing = watchHistoryTimers.get(key);
  if (existing) clearTimeout(existing);
  const timer = setTimeout(() => {
    watchHistoryTimers.delete(key);
    if (AppState.currentState !== 'active') return;
    void flushWatchHistoryEntry(key);
  }, Math.max(0, delay));
  watchHistoryTimers.set(key, timer);
};

const nextPlaybackSequence = (playbackSessionId: string) => {
  const sequence = (playbackSequences.get(playbackSessionId) || 0) + 1;
  playbackSequences.set(playbackSessionId, sequence);
  return sequence;
};

/** Keep requests for a session in wire order so a late heartbeat cannot win. */
const postPlaybackSample = (
  playbackSessionId: string | undefined,
  payload: Record<string, unknown>,
  boundary?: AccountSessionBoundary,
) => {
  const generation = playbackRuntimeGeneration;
  const sourceLessonId = String(payload.lesson_id || '').trim() || undefined;
  const send = async () => {
    if (generation !== playbackRuntimeGeneration) {
      throw new Error('ACCOUNT_SESSION_CHANGED');
    }
    if (boundary) assertAccountSessionBoundary(boundary);
    try {
      const response = await publicRequest.post('user/watch-history', payload);
      if (generation !== playbackRuntimeGeneration) {
        throw new Error('ACCOUNT_SESSION_CHANGED');
      }
      if (boundary) assertAccountSessionBoundary(boundary);
      publishCourseRevisionChange(response, sourceLessonId);
      return response;
    } catch (error) {
      const candidate = error as {
        response?: {data?: {code?: unknown}};
      };
      if (
        String(candidate.response?.data?.code || '').toLowerCase() !==
        'course_revision_changed'
      ) {
        throw error;
      }
      if (generation !== playbackRuntimeGeneration) {
        throw new Error('ACCOUNT_SESSION_CHANGED');
      }
      if (boundary) assertAccountSessionBoundary(boundary);
      publishCourseRevisionChange(error, sourceLessonId);
      // This sample belongs to an archived revision. Retrying the same DTO
      // can never succeed; the observer reloads the authoritative curriculum.
      return candidate.response;
    }
  };
  if (!playbackSessionId) {
    return send();
  }
  const previous =
    playbackRequestQueues.get(playbackSessionId) || Promise.resolve();
  const request = previous.catch(() => undefined).then(send);
  const settled = request.finally(() => {
    if (playbackRequestQueues.get(playbackSessionId) === settled) {
      playbackRequestQueues.delete(playbackSessionId);
    }
  });
  playbackRequestQueues.set(playbackSessionId, settled);
  return request;
};

const flushWatchHistoryEntry = async (key: string): Promise<void> => {
  const activeFlight = watchHistoryFlights.get(key);
  if (activeFlight) return activeFlight;
  const pending = pendingWatchHistory.get(key);
  if (!pending) return Promise.resolve();
  const generation = playbackRuntimeGeneration;
  let boundary: AccountSessionBoundary;
  try {
    boundary = await captureAccountSessionBoundary();
    assertWatchHistoryOwner(generation, boundary);
  } catch {
    return;
  }
  if (!key.startsWith(`${boundary.scope}:`)) {
    return;
  }

  const stillOwned = () => {
    try {
      assertWatchHistoryOwner(generation, boundary);
      return true;
    } catch {
      return false;
    }
  };

  const timer = watchHistoryTimers.get(key);
  if (timer) clearTimeout(timer);
  watchHistoryTimers.delete(key);

  const flight = postPlaybackSample(pending.playbackSessionId, {
    lesson_id: pending.lessonId,
    position_seconds: pending.positionSeconds,
    ...(pending.durationSeconds && pending.durationSeconds > 0
      ? {duration_seconds: pending.durationSeconds}
      : {}),
    is_completed: pending.completed,
    ...(pending.playbackSessionId
      ? {playback_session_id: pending.playbackSessionId}
      : {}),
    ...(pending.sequence ? {sequence: pending.sequence} : {}),
    ...(pending.effectiveQuality
      ? {effective_quality: pending.effectiveQuality}
      : {}),
    ...(pending.effectiveBitrateKbps
      ? {effective_bitrate_kbps: pending.effectiveBitrateKbps}
      : {}),
    ...(pending.playbackRate ? {playback_rate: pending.playbackRate} : {}),
    ...(Number.isFinite(pending.recoveryCount)
      ? {recovery_count: pending.recoveryCount}
      : {}),
    ...(Number.isFinite(pending.bufferCount)
      ? {buffer_count: pending.bufferCount}
      : {}),
    ...(Number.isFinite(pending.bufferDurationMs)
      ? {buffer_duration_ms: pending.bufferDurationMs}
      : {}),
    ...(Number.isFinite(pending.startupLatencyMs)
      ? {startup_latency_ms: pending.startupLatencyMs}
      : {}),
    event_type:
      pending.eventType || (pending.completed ? 'complete' : 'heartbeat'),
    ...(pending.endReason ? {end_reason: pending.endReason} : {}),
    ...(pending.errorCode ? {error_code: pending.errorCode} : {}),
    ...(pending.diagnostics ? {diagnostics: pending.diagnostics} : {}),
  }, boundary)
    .then(() => {
      watchHistoryLastSyncedAt.set(key, Date.now());
      while (watchHistoryLastSyncedAt.size > MAX_RECENT_WATCH_SYNC_KEYS) {
        const oldest = watchHistoryLastSyncedAt.keys().next().value;
        if (typeof oldest !== 'string') break;
        watchHistoryLastSyncedAt.delete(oldest);
      }
      if (pendingWatchHistory.get(key) === pending) {
        pendingWatchHistory.delete(key);
        // The sample is already committed remotely. Native storage cleanup
        // must not route this success into the network catch and repeatedly
        // POST the same completion/heartbeat.
        void AsyncStorage.removeItem(watchEvidenceStorageKey(key)).catch(
          () => undefined,
        );
      }
      return undefined;
    })
    .catch(() => {
      // Keep only the newest sample in memory and retry later. Playback and
      // the local resume point never wait for this network request.
      if (stillOwned()) {
        scheduleWatchHistoryFlush(key, WATCH_HISTORY_SYNC_INTERVAL_MS);
      }
    })
    .finally(() => {
      watchHistoryFlights.delete(key);
      if (
        stillOwned() &&
        pendingWatchHistory.has(key) &&
        !watchHistoryTimers.has(key)
      ) {
        scheduleWatchHistoryFlush(key, WATCH_HISTORY_SYNC_INTERVAL_MS);
      }
    });
  watchHistoryFlights.set(key, flight);
  return flight;
};

const queueWatchHistorySync = async (
  lessonId: number,
  seconds: number,
  durationSeconds?: number,
  completed = false,
  context?: PlaybackEvidenceContext,
  boundary?: AccountSessionBoundary,
  generation = playbackRuntimeGeneration,
) => {
  const owner = boundary || (await captureAccountSessionBoundary());
  assertWatchHistoryOwner(generation, owner);
  const key = `${owner.scope}:${lessonId}`;
  const next: PendingWatchHistory = {
    lessonId,
    positionSeconds: Math.max(0, Math.floor(seconds)),
    ...(durationSeconds && durationSeconds > 0
      ? {durationSeconds: Math.floor(durationSeconds)}
      : {}),
    completed,
    ...(context?.playbackSessionId
      ? {
          playbackSessionId: context.playbackSessionId,
          sequence: nextPlaybackSequence(context.playbackSessionId),
        }
      : {}),
    ...(context?.effectiveQuality
      ? {effectiveQuality: context.effectiveQuality}
      : {}),
    ...(context?.effectiveBitrateKbps
      ? {
          effectiveBitrateKbps: Math.max(
            1,
            Math.round(context.effectiveBitrateKbps),
          ),
        }
      : {}),
    ...(context?.playbackRate ? {playbackRate: context.playbackRate} : {}),
    ...(Number.isFinite(context?.recoveryCount)
      ? {
          recoveryCount: Math.max(
            0,
            Math.min(20, Number(context?.recoveryCount)),
          ),
        }
      : {}),
    ...(Number.isFinite(context?.bufferCount)
      ? {bufferCount: Math.max(0, Math.min(500, Number(context?.bufferCount)))}
      : {}),
    ...(Number.isFinite(context?.bufferDurationMs)
      ? {
          bufferDurationMs: Math.max(
            0,
            Math.min(3_600_000, Math.round(Number(context?.bufferDurationMs))),
          ),
        }
      : {}),
    ...(Number.isFinite(context?.startupLatencyMs)
      ? {
          startupLatencyMs: Math.max(
            0,
            Math.min(120_000, Math.round(Number(context?.startupLatencyMs))),
          ),
        }
      : {}),
    eventType: completed ? 'complete' : 'heartbeat',
    ...(sanitizePlaybackDiagnostics(context?.diagnostics)
      ? {diagnostics: sanitizePlaybackDiagnostics(context?.diagnostics)}
      : {}),
  };
  const previousWrite = watchEvidenceWriteQueues.get(key) || Promise.resolve();
  const write = previousWrite.catch(() => undefined).then(async () => {
    assertWatchHistoryOwner(generation, owner);
    const storageKey = watchEvidenceStorageKey(key);
    const raw = await AsyncStorage.getItem(storageKey);
    assertWatchHistoryOwner(generation, owner);
    let durable: PendingWatchHistory | undefined;
    if (raw) {
      try {
        const parsed = JSON.parse(raw) as unknown;
        if (isPendingWatchHistory(parsed)) durable = parsed;
      } catch {
        // The next valid sample repairs this account-scoped record.
      }
    }
    const previous = mergePendingWatchHistory(
      durable,
      pendingWatchHistory.get(key) || next,
    );
    const pending = mergePendingWatchHistory(previous, next);
    await AsyncStorage.setItem(storageKey, JSON.stringify(pending));
    assertWatchHistoryOwner(generation, owner);
    pendingWatchHistory.set(key, pending);
    const elapsed = Date.now() - (watchHistoryLastSyncedAt.get(key) || 0);
    if (pending.completed || elapsed >= WATCH_HISTORY_SYNC_INTERVAL_MS) {
      await flushWatchHistoryEntry(key);
    } else {
      scheduleWatchHistoryFlush(key, WATCH_HISTORY_SYNC_INTERVAL_MS - elapsed);
    }
  });
  const settled = write.catch(() => undefined).finally(() => {
    if (watchEvidenceWriteQueues.get(key) === settled) {
      watchEvidenceWriteQueues.delete(key);
    }
  });
  watchEvidenceWriteQueues.set(key, settled);
  await write;
};

/** Flush the latest sample per lesson when the app backgrounds or changes reel. */
export const flushPendingPlaybackPositions = async () => {
  const generation = playbackRuntimeGeneration;
  let boundary: AccountSessionBoundary;
  try {
    boundary = await captureAccountSessionBoundary();
    await hydratePendingWatchEvidence(boundary, generation);
    assertWatchHistoryOwner(generation, boundary);
  } catch {
    return;
  }
  await Promise.allSettled(
    Array.from(pendingWatchHistory.keys())
      .filter(key => key.startsWith(`${boundary.scope}:`))
      .map(flushWatchHistoryEntry),
  );
};

export const retryPendingPlaybackPositions = async () => {
  if (!(await hasSession())) return;
  await flushPendingPlaybackPositions();
};

/** Persist the last rendered frame without emitting a second server sample. */
export const persistLocalPlaybackPosition = async (
  courseId: string,
  reelId: string,
  seconds: number,
  boundary?: AccountSessionBoundary,
) => {
  const owner = boundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(owner);
  if (!(await isWatchHistoryEnabled())) return;
  assertAccountSessionBoundary(owner);
  const historyKey = `${courseId}:${reelId}`;
  await updatePlayerStateForScope(
    owner.scope,
    state => ({
      ...state,
      positions: {
        ...state.positions,
        [historyKey]: Math.max(0, Math.floor(seconds)),
      },
      lastWatchedAt: {
        ...state.lastWatchedAt,
        [historyKey]: new Date().toISOString(),
      },
    }),
    owner,
  );
};

export const savePlaybackPosition = async (
  courseId: string,
  reelId: string,
  seconds: number,
  lessonId?: string,
  durationSeconds?: number,
  completed = false,
  context?: PlaybackEvidenceContext,
) => {
  const generation = playbackRuntimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  assertWatchHistoryOwner(generation, boundary);
  await persistLocalPlaybackPosition(courseId, reelId, seconds, boundary);
  assertWatchHistoryOwner(generation, boundary);

  const remoteLessonId = Number(lessonId);
  if (
    !isLocalDemoId(courseId) &&
    lessonId &&
    !isLocalDemoId(lessonId) &&
    Number.isFinite(remoteLessonId) &&
    (await hasSession())
  ) {
    assertWatchHistoryOwner(generation, boundary);
    await queueWatchHistorySync(
      remoteLessonId,
      seconds,
      durationSeconds,
      completed,
      context,
      boundary,
      generation,
    );
  }
};

/**
 * Sends small, PII-free lifecycle samples without blocking playback.
 */
export const reportPlaybackSessionEvent = async (
  event: PlaybackSessionEvent,
): Promise<boolean> => {
  const generation = playbackRuntimeGeneration;
  let boundary: AccountSessionBoundary;
  try {
    boundary = await captureAccountSessionBoundary();
    assertWatchHistoryOwner(generation, boundary);
  } catch {
    return false;
  }
  const lessonId = Number(event.lessonId);
  if (
    !event.playbackSessionId ||
    isLocalDemoId(event.lessonId) ||
    !Number.isFinite(lessonId) ||
    !(await hasSession())
  ) {
    return false;
  }
  const duration = Number(event.durationSeconds);
  const position = Math.max(0, Math.floor(Number(event.positionSeconds) || 0));
  const diagnostics = sanitizePlaybackDiagnostics(event.diagnostics);
  const errorCode = sanitizePlaybackErrorCode(event.errorCode);
  try {
    assertWatchHistoryOwner(generation, boundary);
    await postPlaybackSample(event.playbackSessionId, {
      lesson_id: lessonId,
      position_seconds:
        Number.isFinite(duration) && duration > 0
          ? Math.min(position, Math.floor(duration))
          : position,
      ...(Number.isFinite(duration) && duration > 0
        ? {duration_seconds: Math.floor(duration)}
        : {}),
      is_completed: event.completed === true,
      playback_session_id: event.playbackSessionId,
      sequence: nextPlaybackSequence(event.playbackSessionId),
      event_type: event.eventType,
      ...(event.endReason ? {end_reason: event.endReason} : {}),
      ...(event.effectiveQuality
        ? {effective_quality: event.effectiveQuality}
        : {}),
      ...(event.effectiveBitrateKbps
        ? {
            effective_bitrate_kbps: Math.max(
              1,
              Math.round(event.effectiveBitrateKbps),
            ),
          }
        : {}),
      ...(event.playbackRate ? {playback_rate: event.playbackRate} : {}),
      ...(Number.isFinite(event.recoveryCount)
        ? {
            recovery_count: Math.max(
              0,
              Math.min(20, Number(event.recoveryCount)),
            ),
          }
        : {}),
      ...(Number.isFinite(event.bufferCount)
        ? {buffer_count: Math.max(0, Math.min(500, Number(event.bufferCount)))}
        : {}),
      ...(Number.isFinite(event.bufferDurationMs)
        ? {
            buffer_duration_ms: Math.max(
              0,
              Math.min(3_600_000, Math.round(Number(event.bufferDurationMs))),
            ),
          }
        : {}),
      ...(Number.isFinite(event.startupLatencyMs)
        ? {
            startup_latency_ms: Math.max(
              0,
              Math.min(120_000, Math.round(Number(event.startupLatencyMs))),
            ),
          }
        : {}),
      ...(event.eventType === 'error' && event.endReason
        ? {is_terminal: true}
        : {}),
      ...(errorCode ? {error_code: errorCode} : {}),
      ...(diagnostics ? {diagnostics} : {}),
    }, boundary);
    return true;
  } catch {
    return false;
  } finally {
    if (
      event.eventType === 'stop' ||
      (event.eventType === 'error' && event.endReason)
    ) {
      playbackSequences.delete(event.playbackSessionId);
    }
  }
};

const playbackClientCapabilities = (preference: PlaybackClientPreference) => {
  const screen = Dimensions.get('screen');
  const osMajor = String(Platform.Version || '0').split(/[._-]/, 1)[0];
  const maxBitrate = Number(preference.maxBitrateKbps);
  const os =
    Platform.OS === 'android' || Platform.OS === 'ios' ? Platform.OS : 'other';
  const longestSide = Math.max(screen.width, screen.height);
  const maxHeight =
    [1080, 720, 480, 360].find(value => longestSide >= value) || 360;
  return {
    app_version: appConfig.expo.version,
    os,
    os_version: osMajor,
    supports_hls: Platform.OS === 'android' || Platform.OS === 'ios',
    supports_dash: Platform.OS === 'android',
    supports_mp4: true,
    max_height: maxHeight,
    data_saver: preference.dataSaver === true,
    connection: 'unknown',
    ...(Number.isFinite(maxBitrate) && maxBitrate > 0
      ? {
          max_bitrate_kbps: Math.max(
            200,
            Math.min(25_000, Math.round(maxBitrate)),
          ),
        }
      : {}),
    screen_width: Math.max(1, Math.round(screen.width)),
    screen_height: Math.max(1, Math.round(screen.height)),
  };
};

/**
 * Ask the backend for the one truthful playback decision for this lesson.
 * The existing course payload remains a safe fallback when the endpoint is
 * unavailable, so a transient control-plane failure never strands a learner.
 */
export const openPlaybackSession = async (
  lessonId: string,
  preference: PlaybackClientPreference = {},
): Promise<PlaybackManifest | null> => {
  const numericLessonId = Number(lessonId);
  if (!Number.isFinite(numericLessonId) || isLocalDemoId(lessonId)) {
    return null;
  }
  await requireProductFeature('playback');
  const response = await publicRequest.post(
    `lessons/${numericLessonId}/playback-manifest`,
    {
      client: Platform.OS,
      capability_version: 2,
      ...(preference.playbackSessionId
        ? {playback_session_id: preference.playbackSessionId}
        : {}),
      client_capabilities: playbackClientCapabilities(preference),
    },
    {timeout: 8000},
  );
  const raw = response?.data?.data || response?.data;
  const sourceUrl = valueAsString(raw?.source_url || raw?.url);
  const playbackSessionId = valueAsString(raw?.playback_session_id);
  if (!sourceUrl || !playbackSessionId) return null;
  const sources = qualitySources(raw);
  // Relative lifetimes are anchored to the response arrival time. Device
  // clocks are often minutes off, while Bunny signatures follow server time.
  const localExpiresAt = localDeadlineFromTtl(raw?.expires_in_seconds);
  const localRefreshAfter = localDeadlineFromTtl(raw?.refresh_in_seconds);
  return {
    playbackSessionId,
    sourceUrl,
    fallbackUrl: valueAsString(raw?.fallback_url) || undefined,
    posterUrl: valueAsString(raw?.poster_url) || undefined,
    protocol: ['hls', 'dash', 'mp4'].includes(raw?.protocol)
      ? raw.protocol
      : 'unknown',
    expiresAt: localExpiresAt || valueAsString(raw?.expires_at) || undefined,
    refreshAfter:
      localRefreshAfter || valueAsString(raw?.refresh_after) || undefined,
    durationSeconds: Number(raw?.duration_seconds) || undefined,
    availableQualities: qualityOptions(raw, sourceUrl, sources),
    qualitySources: sources,
    mediaStatus: ['ready', 'processing', 'failed'].includes(raw?.media_status)
      ? raw.media_status
      : 'unknown',
  };
};

const assertPlaybackRuntime = (generation: number) => {
  if (generation !== playbackRuntimeGeneration) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
};

const assertCompletionOwner = (
  generation: number,
  boundary: AccountSessionBoundary,
) => {
  assertPlaybackRuntime(generation);
  assertAccountSessionBoundary(boundary);
};

const performSectionCompletion = async (
  courseId: string,
  sectionId: string,
  boundary: AccountSessionBoundary,
  generation: number,
) => {
  const accountScope = boundary.scope;
  const recordLocalCompletion = () =>
    updatePlayerStateForScope(
      accountScope,
      state => ({
        ...state,
        completedSections: Array.from(
          new Set([...state.completedSections, sectionId]),
        ),
        activityDays: Array.from(
          new Set([...state.activityDays, roknCalendarDay()]),
        ).slice(-60),
      }),
      boundary,
    );

  if (isLocalDemoId(courseId) || isLocalDemoId(sectionId)) {
    assertCompletionOwner(generation, boundary);
    await recordLocalCompletion();
    assertCompletionOwner(generation, boundary);
    return true;
  }
  if (!(await hasSession())) {
    return false;
  }
  assertCompletionOwner(generation, boundary);

  const storageKey = sectionCompletionKey(courseId, sectionId, accountScope);
  try {
    await publicRequest.post(
      `courses/${courseId}/sections/${sectionId}/complete`,
    );
  } catch (error) {
    try {
      assertCompletionOwner(generation, boundary);
    } catch {
      // Logout/account replacement may already have erased the old scope.
      // Never resurrect a retry marker after that privacy boundary.
      return false;
    }
    if (isRetryableCompletionError(error)) {
      await AsyncStorage.setItem(
        storageKey,
        JSON.stringify({courseId, sectionId}),
      );
      try {
        assertCompletionOwner(generation, boundary);
      } catch {
        await AsyncStorage.removeItem(storageKey);
        return false;
      }
    } else {
      await AsyncStorage.removeItem(storageKey);
    }
    return false;
  }

  // The server completion is authoritative. Local marker removal and player
  // cache repair are maintenance only; a storage failure must not block the
  // next section or turn an idempotent completed request into a retry error.
  try {
    assertCompletionOwner(generation, boundary);
  } catch {
    return false;
  }
  try {
    assertCompletionOwner(generation, boundary);
    await recordLocalCompletion();
    assertCompletionOwner(generation, boundary);
    await AsyncStorage.removeItem(storageKey);
  } catch {
    // Server success remains authoritative. Keep a stale retry marker if
    // present; its POST is idempotent and later reconciliation repairs the
    // local projection. Awaiting this best-effort tail also keeps one logical
    // completion inside one flight instead of leaking maintenance into the
    // next retry or account lifecycle.
  }
  return true;
};

export const markSectionComplete = async (
  courseId: string,
  sectionId: string,
) => {
  const generation = playbackRuntimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  assertCompletionOwner(generation, boundary);
  const accountScope = boundary.scope;
  const flightKey = `${accountScope}:${boundary.epoch}:${courseId}:${sectionId}`;
  const existing = sectionCompletionFlights.get(flightKey);
  if (existing) return existing;

  const flight = performSectionCompletion(
    courseId,
    sectionId,
    boundary,
    generation,
  ).finally(() => {
    if (sectionCompletionFlights.get(flightKey) === flight) {
      sectionCompletionFlights.delete(flightKey);
    }
  });
  sectionCompletionFlights.set(flightKey, flight);
  return flight;
};

const responseStatus = (error: unknown): number | null => {
  if (!error || typeof error !== 'object') return null;
  const candidate = error as {
    status?: unknown;
    response?: {status?: unknown};
  };
  const value = Number(candidate.status ?? candidate.response?.status);
  return Number.isFinite(value) && value > 0 ? value : null;
};

const completionErrorCode = (error: unknown): string => {
  if (!error || typeof error !== 'object') return '';
  const candidate = error as {
    code?: unknown;
    data?: {code?: unknown};
    response?: {data?: {code?: unknown}};
  };
  return String(
    candidate.response?.data?.code ??
      candidate.data?.code ??
      candidate.code ??
      '',
  )
    .trim()
    .toLowerCase();
};

const isRetryableCompletionError = (error: unknown) => {
  const status = responseStatus(error);
  if (status === null || status === 408 || status === 429 || status >= 500) {
    return true;
  }

  return [
    'verified_watch_required',
    'passed_quiz_required',
    'previous_section_incomplete',
    'module_project_not_passed',
    'section_locked',
  ].includes(completionErrorCode(error));
};

const sectionCompletionPrefix = (accountScope: string) =>
  `${SECTION_COMPLETION_PREFIX}:${accountScope}:`;

const sectionCompletionKey = (
  courseId: string,
  sectionId: string,
  accountScope: string,
) => `${sectionCompletionPrefix(accountScope)}${courseId}:${sectionId}`;

export const retryPendingSectionCompletions = async () => {
  const generation = playbackRuntimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  assertCompletionOwner(generation, boundary);
  const accountScope = boundary.scope;
  if (!(await hasSession())) {
    return;
  }
  assertCompletionOwner(generation, boundary);
  const prefix = sectionCompletionPrefix(accountScope);
  const keys = (await AsyncStorage.getAllKeys()).filter(key =>
    key.startsWith(prefix),
  );
  if (!keys.length) {
    return;
  }
  const entries = await AsyncStorage.multiGet(keys);
  assertCompletionOwner(generation, boundary);
  for (const [key, value] of entries) {
    assertCompletionOwner(generation, boundary);
    if (!value) {
      continue;
    }
    let pending: {courseId: string; sectionId: string};
    try {
      pending = JSON.parse(value) as typeof pending;
      if (!pending.courseId || !pending.sectionId) {
        throw new Error('INVALID_SECTION_COMPLETION');
      }
    } catch {
      await AsyncStorage.removeItem(key);
      continue;
    }
    try {
      await markSectionComplete(pending.courseId, pending.sectionId);
      assertCompletionOwner(generation, boundary);
    } catch {
      try {
        assertCompletionOwner(generation, boundary);
      } catch {
        return;
      }
      // markSectionComplete owns retry classification and durable cleanup.
      // A boundary change leaves the old account's evidence untouched.
    }
  }
};

export const resetPlaybackRuntimeState = () => {
  playbackRuntimeGeneration += 1;
  watchHistoryTimers.forEach(timer => clearTimeout(timer));
  watchHistoryTimers.clear();
  pendingWatchHistory.clear();
  watchHistoryFlights.clear();
  watchEvidenceWriteQueues.clear();
  watchHistoryLastSyncedAt.clear();
  playbackSequences.clear();
  playbackRequestQueues.clear();
  sectionCompletionFlights.clear();
};
