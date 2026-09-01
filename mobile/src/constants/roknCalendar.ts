const ROKN_TIME_ZONE = 'Africa/Cairo';

const twoDigits = (value: number) => String(value).padStart(2, '0');

export const shiftRoknCalendarDay = (day: string, offset: number): string => {
  const matched = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day);
  if (!matched) return day;
  const instant = new Date(
    Date.UTC(Number(matched[1]), Number(matched[2]) - 1, Number(matched[3]) + offset, 12),
  );
  return instant.toISOString().slice(0, 10);
};

/** The backend awards streaks and daily rewards on the Cairo calendar. */
export const roknCalendarDay = (date = new Date()): string => {
  try {
    const parts = new Intl.DateTimeFormat('en', {
      timeZone: ROKN_TIME_ZONE,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).formatToParts(date);
    const values = Object.fromEntries(
      parts.map(part => [part.type, part.value]),
    );
    if (values.year && values.month && values.day) {
      return `${values.year}-${values.month}-${values.day}`;
    }
  } catch {
    // Older runtimes without time-zone data still get a stable local date.
  }

  return `${date.getFullYear()}-${twoDigits(date.getMonth() + 1)}-${twoDigits(
    date.getDate(),
  )}`;
};
