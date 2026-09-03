import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('daily read latency budget', () => {
  it('keeps automatic GET recovery below three seconds of backoff', () => {
    const api = source('src/constants/api.ts');
    expect(api).toContain(
      'READ_RECOVERY_DELAYS_MS = [300, 700, 1_500] as const',
    );
    expect(api).not.toContain('4_500, 5_000');
  });

  it('bounds the learning entitlement overlay instead of blocking home indefinitely', () => {
    const courses = source('src/services/api/courses.ts');
    expect(courses).toContain('HOME_ENTITLEMENT_READ_BUDGET_MS = 2_500');
    expect(courses).toContain('signal: controller.signal');
    expect(courses).toContain('retryDeadlineAt: deadlineAt');
  });
});
