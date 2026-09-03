import {
  courseChatFailureCanStartFreshTurn,
  courseChatFailureHasRetryAction,
} from '../src/components/VideoPlayer/courseChat/policy';

describe('course chat terminal retry policy', () => {
  it.each([
    'ai_temporarily_unavailable',
    'chat_queue_busy',
    'chat_request_interrupted',
    'chat_reservation_unavailable',
    'chat_turn_failed',
    'chat_turn_not_found',
    'client_timeout',
    'network_unavailable',
  ])('starts a fresh turn after the server proves %s is terminal', code => {
    expect(courseChatFailureCanStartFreshTurn(code)).toBe(true);
  });

  it.each([
    'chat_answer_in_progress',
    'chat_provider_outcome_unknown',
    'chat_plan_limit_reached',
    'chat_daily_limit_reached',
    'chat_attachment_unreadable',
    'course_access_required',
    '',
  ])('does not risk a duplicate provider call for %s', code => {
    expect(courseChatFailureCanStartFreshTurn(code)).toBe(false);
  });

  it('offers recovery for interrupted work but no dead button for unknown provider outcomes', () => {
    expect(courseChatFailureHasRetryAction('interrupted_turn')).toBe(true);
    expect(courseChatFailureHasRetryAction('chat_answer_in_progress')).toBe(true);
    expect(courseChatFailureHasRetryAction('chat_provider_outcome_unknown')).toBe(false);
    expect(courseChatFailureHasRetryAction('chat_attachment_unreadable')).toBe(false);
  });
});
