export type NativeAttachmentRecovery = 'refresh' | 'storage' | 'fail';

/**
 * A signed URL may be refreshed once. Repeating that recovery forever turns
 * a provider/configuration failure into a tap that never settles.
 */
export const nativeAttachmentRecovery = (
  errorCode: string,
  signedUrlRefreshAttempted: boolean,
): NativeAttachmentRecovery => {
  if (errorCode === 'INSUFFICIENT_STORAGE') return 'storage';
  if (
    errorCode === 'DOWNLOAD_RETRY_REQUIRES_REFRESH' &&
    !signedUrlRefreshAttempted
  ) {
    return 'refresh';
  }
  return 'fail';
};
