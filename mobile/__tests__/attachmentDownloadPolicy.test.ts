import {nativeAttachmentRecovery} from '../src/components/VideoPlayer/attachmentDownloadPolicy';

describe('attachment download recovery', () => {
  it('refreshes an expired signed URL only once', () => {
    expect(
      nativeAttachmentRecovery('DOWNLOAD_RETRY_REQUIRES_REFRESH', false),
    ).toBe('refresh');
    expect(
      nativeAttachmentRecovery('DOWNLOAD_RETRY_REQUIRES_REFRESH', true),
    ).toBe('fail');
  });

  it('keeps storage failure terminal and distinguishable', () => {
    expect(nativeAttachmentRecovery('INSUFFICIENT_STORAGE', false)).toBe(
      'storage',
    );
  });

  it('does not retry unknown native failures', () => {
    expect(nativeAttachmentRecovery('NATIVE_FAILURE', false)).toBe('fail');
  });
});
