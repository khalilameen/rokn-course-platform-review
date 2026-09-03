import {asRecord} from '../courseLearning/shared';

export const courseChatErrorCode = (error: unknown): string => {
  const failure = asRecord(error);
  const response = asRecord(failure.response);
  return String(
    asRecord(failure.data).code || asRecord(response.data).code || '',
  );
};

// These outcomes prove that the previous logical turn cannot produce an
// answer and did not leave a provider call with an unknown result. Only then
// may the same visible question move to a fresh id. Reusing a terminal id can
// never enqueue work; blindly replacing an unknown id can charge twice.
const FRESH_TURN_SAFE_FAILURES = new Set([
  'ai_temporarily_unavailable',
  'chat_queue_busy',
  'chat_request_interrupted',
  'chat_reservation_unavailable',
  'chat_turn_failed',
  'chat_turn_not_found',
  'client_timeout',
  'network_unavailable',
]);

export const courseChatFailureCanStartFreshTurn = (code?: string): boolean =>
  FRESH_TURN_SAFE_FAILURES.has(String(code || '').trim().toLowerCase());

export const courseChatFailureHasRetryAction = (code?: string): boolean => {
  const normalized = String(code || '').trim().toLowerCase();
  return (
    courseChatFailureCanStartFreshTurn(normalized) ||
    ['chat_answer_in_progress', 'interrupted_turn'].includes(normalized)
  );
};
