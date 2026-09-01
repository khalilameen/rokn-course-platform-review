import {cleanUnicodeText} from './unicodeText';

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

/** The same visible Arabic equivalence contract used by the backend index. */
export const normalizeText = (text: string): string =>
  cleanUnicodeText(text, false)
    .normalize('NFKC')
    .toLowerCase()
    .replace(/[\u064B-\u065F\u0670\u06D6-\u06ED]/g, '')
    .replace(/[أإآٱٲٳٵ]/g, 'ا')
    .replace(/[ىیېۍے]/g, 'ي')
    .replace(/ؤ/g, 'و')
    .replace(/ئ/g, 'ي')
    .replace(/[ةۀہھ]/g, 'ه')
    .replace(/ک/g, 'ك')
    .replace(/ـ/g, '')
    .replace(/[٠-٩۰-۹]/g, digit => ARABIC_DIGITS[digit] || digit)
    .replace(/[^\p{Script=Arabic}\p{L}\p{N}]+/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim();
