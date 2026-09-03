const mockFetch = jest.fn(
  async (_url: string, _request: {body?: string}) => ({ok: true}),
);
jest.mock('react-native', () => ({
  NativeModules: {},
  Platform: {OS: 'android', Version: 34},
}));

jest.mock('expo/virtual/env', () => ({env: {}}));

jest.mock('expo-crypto', () => ({
  randomUUID: () => '8d78f65e-8385-4b8b-8ea1-ccf985a4a191',
}));
jest.mock('../src/services/sentryTelemetry', () => ({
  captureSentryDiagnostic: jest.fn(),
  requestCorrelationFor: (_error: Error, supplied: Record<string, string>) => ({
    endpoint: supplied.endpoint ? `/${supplied.endpoint}` : undefined,
    requestId: supplied.requestId,
  }),
}));

describe('operational telemetry contract', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    (globalThis as any).fetch = mockFetch;
  });

  it('sends only the allowlisted backend schema without free-form diagnostics', async () => {
    (globalThis as any).__DEV__ = false;
    const {reportClientError} = require('../src/services/operationalTelemetry');

    await reportClientError(
      new Error('payment_status_timeout user@example.com token=secret'),
      {source: 'coin_checkout'},
    );

    expect(mockFetch).toHaveBeenCalledTimes(1);
    const [, request] = mockFetch.mock.calls[0];
    const payload = JSON.parse(String(request.body));

    expect(payload).toMatchObject({
      client_event_id: '8d78f65e-8385-4b8b-8ea1-ccf985a4a191',
      event_name: 'payment_flow_failure',
      severity: 'error',
      platform: 'android',
      os_major: 34,
      device_tier: 'unknown',
      screen_key: 'coin_checkout',
      error_code: 'PAYMENT_STATUS_TIMEOUT',
      error_fingerprint: expect.stringMatching(/^[a-f0-9]{64}$/),
    });
    expect(payload).not.toHaveProperty('message');
    expect(payload).not.toHaveProperty('stack');
    expect(payload).not.toHaveProperty('component_stack');
    expect(JSON.stringify(payload)).not.toContain('user@example.com');
    expect(JSON.stringify(payload)).not.toContain('secret');
    const diagnostics = await require('../src/services/operationalTelemetry')
      .getOperationalDiagnosticsSnapshot();
    expect(diagnostics).toEqual([
      expect.objectContaining({
        event: 'payment_flow_failure',
        severity: 'error',
        code: 'PAYMENT_STATUS_TIMEOUT',
        attempts: 0,
      }),
    ]);
    expect(JSON.stringify(diagnostics)).not.toContain('user@example.com');
    expect(JSON.stringify(diagnostics)).not.toContain('secret');
  });

  it('keeps a safe chat request correlation without recording prompt text', async () => {
    (globalThis as any).__DEV__ = false;
    const {reportClientError} = require('../src/services/operationalTelemetry');

    await reportClientError(new Error('ai_temporarily_unavailable'), {
      source: 'course_chat',
      endpoint: 'course-chat/turns',
      requestId: '1f87903b-6035-4d5d-bb12-c6f796a71f47',
    });

    expect(mockFetch).toHaveBeenCalledTimes(1);
    const [, request] = mockFetch.mock.calls[0];
    const payload = JSON.parse(String(request.body));
    expect(payload).toMatchObject({
      event_name: 'course_chat_failure',
      screen_key: 'course_chat',
      error_code: 'AI_TEMPORARILY_UNAVAILABLE',
      endpoint: '/course-chat/turns',
      request_id: '1f87903b-6035-4d5d-bb12-c6f796a71f47',
    });
    expect(payload).not.toHaveProperty('message');
  });
});
