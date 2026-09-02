import {validatedTusOffset} from '../src/services/portfolioVideoUpload';

describe('portfolio TUS offset contract', () => {
  it('accepts a resumable HEAD offset inside the declared file', () => {
    expect(validatedTusOffset('4194304', 8388608)).toBe(4194304);
  });

  it('requires PATCH acknowledgement to match the bytes just sent', () => {
    expect(() => validatedTusOffset('900', 1000, 800)).toThrow(
      'PORTFOLIO_VIDEO_UPLOAD_STATE_INVALID',
    );
    expect(() => validatedTusOffset(null, 1000, 800)).toThrow(
      'PORTFOLIO_VIDEO_UPLOAD_STATE_INVALID',
    );
    expect(validatedTusOffset('800', 1000, 800)).toBe(800);
  });

  it('rejects offsets outside the declared upload length', () => {
    expect(() => validatedTusOffset('-1', 1000)).toThrow(
      'PORTFOLIO_VIDEO_UPLOAD_STATE_INVALID',
    );
    expect(() => validatedTusOffset('1001', 1000)).toThrow(
      'PORTFOLIO_VIDEO_UPLOAD_STATE_INVALID',
    );
    expect(() => validatedTusOffset('0.5', 1000)).toThrow(
      'PORTFOLIO_VIDEO_UPLOAD_STATE_INVALID',
    );
  });
});
