// IANA tzdata 2026c: Morocco uses UTC from 2026-09-20T01:00Z.
// Older browser/Node ICU data still adds an hour. Keep historical dates on
// Africa/Casablanca, and apply the published transition for newer dates.
// https://www.iana.org/time-zones/releases
const permanentUtcFrom = Date.parse('2026-09-20T01:00:00Z');

export function moroccoDateTimeFormat(locales: Intl.LocalesArgument, options: Intl.DateTimeFormatOptions = {}) {
  const historical = new Intl.DateTimeFormat(locales, {...options, timeZone: 'Africa/Casablanca'});
  const current = new Intl.DateTimeFormat(locales, {...options, timeZone: 'UTC'});
  const formatter = (date: Date | number) => Number(date) >= permanentUtcFrom ? current : historical;

  return {
    format(date: Date | number = new Date()) {
      return formatter(date).format(date);
    },
    formatToParts(date: Date | number = new Date()) {
      return formatter(date).formatToParts(date);
    },
  };
}
