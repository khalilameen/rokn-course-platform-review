const normalizedVideoUri = (value?: string) =>
  String(value || '')
    .trim()
    .replace(/&amp;/gi, '&');

export type VideoSourceReachability = 'reachable' | 'offline' | 'timeout';

/** HTML page URLs are invalid media inputs for ExoPlayer and AVPlayer. */
export const isUnsupportedVideoPageUri = (value?: string) => {
  const uri = normalizedVideoUri(value).replace(/^\/\//, 'https://');
  const host = uri.match(/^https?:\/\/([^/:?#]+)/i)?.[1]?.toLowerCase();
  if (!host) return false;
  return (
    host === 'youtu.be' ||
    host === 'youtube.com' ||
    host.endsWith('.youtube.com') ||
    host === 'youtube-nocookie.com' ||
    host.endsWith('.youtube-nocookie.com')
  );
};

/**
 * A very small diagnostic request used only after playback has already failed.
 * Any HTTP response proves that the device can reach the source; the response
 * does not need to be successful because signed CDNs commonly reject HEAD.
 */
export const probeVideoSource = async (
  value?: string,
  timeoutMs = 4500,
): Promise<VideoSourceReachability> => {
  const uri = normalizedVideoUri(value).replace(/^\/\//, 'https://');
  if (!/^https?:\/\//i.test(uri)) {
    return 'offline';
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    await fetch(uri, {
      method: 'HEAD',
      headers: {Range: 'bytes=0-1'},
      signal: controller.signal,
    });
    return 'reachable';
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return controller.signal.aborted || /abort|timeout/i.test(message)
      ? 'timeout'
      : 'offline';
  } finally {
    clearTimeout(timer);
  }
};
