import {roknCalendarDay} from '../constants/roknCalendar';
import {
  formatArabicCount,
  toArabicDigits,
} from '../constants/arabicFormatting';
import {serverNow} from './serverClock';

export const ROKN_TIME_ZONE = 'Africa/Cairo';

const validDate = (value: unknown): Date | null => {
  if (value instanceof Date) {
    return Number.isFinite(value.getTime()) ? value : null;
  }
  if (typeof value === 'number' && Number.isFinite(value)) {
    const date = new Date(value);
    return Number.isFinite(date.getTime()) ? date : null;
  }
  const input = String(value || '').trim();
  if (!input) return null;
  // Legacy endpoints occasionally return a database UTC timestamp without an
  // offset. Parsing that as the phone timezone shifts the same event per user.
  const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(input)
    ? `${input.replace(' ', 'T')}Z`
    : /^\d{4}-\d{2}-\d{2}$/.test(input)
      ? `${input}T12:00:00Z`
      : input;
  const date = new Date(normalized);
  return Number.isFinite(date.getTime()) ? date : null;
};

export const formatRoknDate = (
  value: unknown,
  options: Intl.DateTimeFormatOptions = {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  },
): string => {
  const date = validDate(value);
  if (!date) return '';
  try {
    return new Intl.DateTimeFormat('ar-EG-u-ca-gregory-nu-arab', {
      ...options,
      timeZone: ROKN_TIME_ZONE,
    }).format(date);
  } catch {
    return toArabicDigits(roknCalendarDay(date));
  }
};

export const formatRoknRelativeDate = (value: unknown): string => {
  const date = validDate(value);
  if (!date) return '';
  const now = serverNow();
  const elapsedSeconds = Math.floor((now.getTime() - date.getTime()) / 1000);
  if (elapsedSeconds < 0 && elapsedSeconds >= -5 * 60) return 'الآن';
  if (elapsedSeconds >= 0 && elapsedSeconds < 60) return 'الآن';
  if (elapsedSeconds >= 60 && elapsedSeconds < 60 * 60) {
    return `منذ ${formatArabicCount(Math.floor(elapsedSeconds / 60), {
      one: 'دقيقة',
      two: 'دقيقتين',
      few: 'دقائق',
      many: 'دقيقة',
      other: 'دقيقة',
    })}`;
  }
  const today = roknCalendarDay(now);
  const target = roknCalendarDay(date);
  const targetUtcDay = Date.parse(`${target}T00:00:00Z`);
  const todayUtcDay = Date.parse(`${today}T00:00:00Z`);
  const dayDifference = Math.round((todayUtcDay - targetUtcDay) / 86_400_000);
  if (dayDifference === 0 && elapsedSeconds >= 0) {
    return `منذ ${formatArabicCount(Math.floor(elapsedSeconds / 3600), {
      one: 'ساعة',
      two: 'ساعتين',
      few: 'ساعات',
      many: 'ساعة',
      other: 'ساعة',
    })}`;
  }
  if (dayDifference === 0) {
    return formatRoknDate(date, {hour: 'numeric', minute: '2-digit'});
  }
  if (dayDifference === 1) return 'أمس';
  if (dayDifference >= 2 && dayDifference <= 6) {
    return `منذ ${formatArabicCount(dayDifference, {
      one: 'يوم',
      two: 'يومين',
      few: 'أيام',
      many: 'يومًا',
      other: 'يوم',
    })}`;
  }
  return formatRoknDate(date, {day: 'numeric', month: 'short'});
};
