import {
  projectFeedbackFailureHasRetryAction,
  projectFeedbackFailureText,
} from '../src/components/VideoPlayer/projectFeedback/policy';

describe('project feedback retry policy', () => {
  it('never offers a second paid request while the provider outcome is unknown', () => {
    expect(
      projectFeedbackFailureHasRetryAction('provider_outcome_unknown'),
    ).toBe(false);
    expect(projectFeedbackFailureText('provider_outcome_unknown')).toBe(
      'تعذّر تأكيد الرد الآن',
    );
  });

  it('offers retry only after a proven terminal retryable failure', () => {
    expect(projectFeedbackFailureHasRetryAction('provider_unavailable')).toBe(
      true,
    );
    expect(projectFeedbackFailureHasRetryAction('request_interrupted')).toBe(
      true,
    );
    expect(projectFeedbackFailureHasRetryAction('plan_limit_reached')).toBe(
      false,
    );
  });
});
