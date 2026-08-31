const ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'] as const;

type VisibleValue = string | number | bigint | null | undefined;

const LEARNING_TERM_REPLACEMENTS: ReadonlyArray<readonly [RegExp, string]> = [
  [/(^|[^\u0621-\u064A])الريلز(?=$|[^\u0621-\u064A])/g, '$1المقاطع'],
  [/(^|[^\u0621-\u064A])ريلز(?=$|[^\u0621-\u064A])/g, '$1مقاطع'],
  [/(^|[^\u0621-\u064A])الريلات(?=$|[^\u0621-\u064A])/g, '$1المقاطع'],
  [/(^|[^\u0621-\u064A])ريلات(?=$|[^\u0621-\u064A])/g, '$1مقاطع'],
  [/(^|[^\u0621-\u064A])الريلين(?=$|[^\u0621-\u064A])/g, '$1المقطعين'],
  [/(^|[^\u0621-\u064A])ريلين(?=$|[^\u0621-\u064A])/g, '$1مقطعين'],
  [/(^|[^\u0621-\u064A])الريل(?=$|[^\u0621-\u064A])/g, '$1المقطع'],
  [/(^|[^\u0621-\u064A])ريلًا(?=$|[^\u0621-\u064A])/g, '$1مقطعًا'],
  [/(^|[^\u0621-\u064A])ريلاً(?=$|[^\u0621-\u064A])/g, '$1مقطعًا'],
  [/(^|[^\u0621-\u064A])ريل(?=$|[^\u0621-\u064A])/g, '$1مقطع'],
];

/** Converts only visible Latin digits; IDs, URLs and API payloads stay untouched. */
export const toArabicDigits = (value: VisibleValue): string => {
  if (value === null || value === undefined) return '';
  return String(value).replace(
    /[0-9]/g,
    digit => ARABIC_INDIC_DIGITS[Number(digit)] ?? digit,
  );
};

/** Localizes learner-facing copy without touching model, route or API names. */
export const formatArabicDisplayText = (value: VisibleValue): string =>
  LEARNING_TERM_REPLACEMENTS.reduce(
    (text, [pattern, replacement]) => text.replace(pattern, replacement),
    toArabicDigits(value),
  );

/** Formats a finite number with Arabic digits and Arabic decimal/group separators. */
export const formatArabicNumber = (
  value: number,
  options: Intl.NumberFormatOptions = {},
): string => {
  if (!Number.isFinite(value)) return '';

  try {
    return toArabicDigits(
      new Intl.NumberFormat('ar-EG-u-nu-arab', options).format(value),
    )
      .replace(/,/g, '٬')
      .replace(/\./g, '٫');
  } catch {
    return toArabicDigits(String(value)).replace('.', '٫');
  }
};
