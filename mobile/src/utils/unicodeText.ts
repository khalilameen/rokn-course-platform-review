const DIRECTIONAL_AND_INVISIBLE_CONTROLS =
  /[\u00AD\u034F\u061C\u180E\u200B\u200E\u200F\u202A-\u202E\u2060\u2066-\u2069\uFEFF]/g;
/* eslint-disable no-control-regex -- these are the exact unsafe C0 ranges */
const UNSAFE_CONTROLS = new RegExp(
  '[\\u0000-\\u0008\\u000B\\u000C\\u000E-\\u001F\\u007F]',
  'g',
);
/* eslint-enable no-control-regex */
const ARABIC_DIGITS: Record<string, string> = {
  '٠': '0',
  '١': '1',
  '٢': '2',
  '٣': '3',
  '٤': '4',
  '٥': '5',
  '٦': '6',
  '٧': '7',
  '٨': '8',
  '٩': '9',
  '۰': '0',
  '۱': '1',
  '۲': '2',
  '۳': '3',
  '۴': '4',
  '۵': '5',
  '۶': '6',
  '۷': '7',
  '۸': '8',
  '۹': '9',
};

const normalizeForm = (value: string, form: 'NFC' | 'NFKC') => {
  try {
    return value.normalize(form);
  } catch {
    return value;
  }
};

export const cleanUnicodeText = (
  value: unknown,
  multiline = true,
): string => {
  let text = normalizeForm(String(value ?? ''), 'NFC')
    .replace(DIRECTIONAL_AND_INVISIBLE_CONTROLS, '')
    .replace(UNSAFE_CONTROLS, '')
    .replace(/\r\n?/g, '\n')
    .replace(/[\u2028\u2029]/g, '\n')
    .replace(/[\u00A0\u2000-\u200A\u202F\u205F\u3000]/g, ' ');

  if (!multiline) return text.replace(/\s+/g, ' ').trim();
  return text.trim();
};

export const normalizeHumanIdentifier = (value: unknown): string =>
  normalizeForm(String(value ?? ''), 'NFKC')
    .replace(/[٠-٩۰-۹]/g, digit => ARABIC_DIGITS[digit] ?? digit)
    .replace(DIRECTIONAL_AND_INVISIBLE_CONTROLS, '')
    .replace(UNSAFE_CONTROLS, '')
    .replace(/\s+/g, '')
    .toUpperCase();

type SegmenterLike = {
  segment(input: string): Iterable<{segment: string}>;
};

export const truncateGraphemes = (value: string, maximum: number): string => {
  if (maximum <= 0 || !value) return '';
  const IntlWithSegmenter = Intl as typeof Intl & {
    Segmenter?: new (
      locale?: string | string[],
      options?: {granularity: 'grapheme'},
    ) => SegmenterLike;
  };
  if (IntlWithSegmenter.Segmenter) {
    try {
      const segments = new IntlWithSegmenter.Segmenter(undefined, {
        granularity: 'grapheme',
      });
      return Array.from(segments.segment(value), item => item.segment)
        .slice(0, maximum)
        .join('');
    } catch {
      // Older Hermes builds may expose Intl without Segmenter data.
    }
  }

  const clusters: string[] = [];
  let current = '';
  let previousWasJoiner = false;
  let regionalIndicators = 0;
  for (const symbol of Array.from(value)) {
    const codePoint = symbol.codePointAt(0) || 0;
    const isMark = /\p{M}/u.test(symbol);
    const isVariation = codePoint >= 0xfe00 && codePoint <= 0xfe0f;
    const isEmojiModifier = codePoint >= 0x1f3fb && codePoint <= 0x1f3ff;
    const isEmojiTag = codePoint >= 0xe0020 && codePoint <= 0xe007f;
    const isJoiner = codePoint === 0x200d;
    const isRegional = codePoint >= 0x1f1e6 && codePoint <= 0x1f1ff;
    const joinsCurrent =
      current !== '' &&
      (isMark ||
        isVariation ||
        isEmojiModifier ||
        isEmojiTag ||
        isJoiner ||
        previousWasJoiner ||
        (isRegional && regionalIndicators === 1));

    if (!joinsCurrent && current) {
      clusters.push(current);
      if (clusters.length >= maximum) return clusters.join('');
      current = '';
    }
    current += symbol;
    previousWasJoiner = isJoiner;
    regionalIndicators = isRegional
      ? (regionalIndicators + 1) % 2
      : 0;
  }
  if (current && clusters.length < maximum) clusters.push(current);
  return clusters.join('');
};

export const safeFilenameStem = (value: unknown, maximum = 80): string =>
  truncateGraphemes(
    cleanUnicodeText(value, false)
      .replace(/[\\/:*?"<>|.]+/g, ' ')
      .replace(/\s+/g, '-')
      .replace(/^-+|-+$/g, ''),
    maximum,
  );
