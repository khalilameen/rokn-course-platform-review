import type {VideoQuality, VideoQualitySources} from '../types';

export type DataRecord = Record<string, unknown>;

export const asArray = <T>(value: unknown): T[] =>
  Array.isArray(value) ? (value as T[]) : [];

export const asRecord = (value: unknown): DataRecord =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as DataRecord)
    : {};

export const valueAsString = (value: unknown, fallback = ''): string =>
  value === null || value === undefined ? fallback : String(value);

export const valueAsBoolean = (...values: unknown[]): boolean =>
  values.some(
    value =>
      value === true ||
      value === 1 ||
      value === '1' ||
      String(value).toLowerCase() === 'true',
  );

export const explicitBoolean = (...values: unknown[]): boolean | undefined => {
  for (const value of values) {
    if (value === true || value === 1 || value === '1') return true;
    if (
      value === false ||
      value === 0 ||
      value === '0' ||
      String(value).toLowerCase() === 'false'
    ) {
      return false;
    }
  }
  return undefined;
};

export const qualitySources = (raw: unknown): VideoQualitySources => {
  const supported = ['1080p', '720p', '480p', '360p'] as const;
  const result: VideoQualitySources = {};
  const source = asRecord(raw);
  const variants =
    source.quality_sources ||
    source.quality_urls ||
    source.video_variants ||
    source.renditions ||
    source.sources;

  if (Array.isArray(variants)) {
    variants.forEach(rawItem => {
      const item = asRecord(rawItem);
      const quality = valueAsString(
        item.quality || item.resolution || item.label,
      ).toLowerCase();
      const url = valueAsString(item.url || item.video_url || item.src);
      const supportedQuality = supported.find(
        candidate => candidate === quality,
      );
      if (supportedQuality && url) {
        result[supportedQuality] = url;
      }
    });
  } else if (variants && typeof variants === 'object') {
    const variantMap = asRecord(variants);
    supported.forEach(quality => {
      const url = valueAsString(
        variantMap[quality] || variantMap[quality.replace('p', '')],
      );
      if (url) result[quality] = url;
    });
  }

  supported.forEach(quality => {
    const numeric = quality.replace('p', '');
    const direct = valueAsString(
      source[`video_url_${quality}`] ||
        source[`video_url_${numeric}`] ||
        source[`url_${quality}`],
    );
    if (direct) result[quality] = direct;
  });
  return result;
};

export const qualityOptions = (
  raw: unknown,
  videoUrl: string,
  sources: VideoQualitySources,
): VideoQuality[] => {
  const source = asRecord(raw);
  const values = asArray<unknown>(
    source.available_qualities || source.qualities,
  )
    .map(item => valueAsString(item).toLowerCase())
    .filter(item =>
      ['auto', '1080p', '720p', '480p', '360p'].includes(item),
    ) as VideoQuality[];

  const directQualities = Object.keys(sources) as VideoQuality[];
  const adaptive = /\.(m3u8|mpd)(?:[?#]|$)/i.test(videoUrl);
  if (!adaptive) {
    return Array.from(new Set<VideoQuality>(['auto', ...directQualities]));
  }
  return values.length
    ? Array.from(new Set<VideoQuality>(['auto', ...values, ...directQualities]))
    : Array.from(new Set<VideoQuality>(['auto', ...directQualities]));
};
