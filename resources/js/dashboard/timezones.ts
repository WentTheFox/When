/**
 * Time zones for the "timezone" custom field type. The stored entries are IANA zone ids ("Europe/Budapest"),
 * kept like a "list" (see encryptedList.ts). The ids come from the browser's own zone data.
 */
export interface TimezoneOption {
  /** IANA zone id. */
  value: string;
  /** The id with spaces instead of underscores ("America/New York"). */
  text: string;
  /** The region before the first slash: the group heading. */
  group: string;
  keywords: string[];
}

/** Used only where the browser can't enumerate zones itself. */
const FALLBACK_ZONES = [
  'UTC', 'Africa/Cairo', 'Africa/Johannesburg', 'Africa/Lagos', 'America/Anchorage', 'America/Argentina/Buenos_Aires',
  'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Mexico_City', 'America/New_York', 'America/Sao_Paulo',
  'America/Toronto', 'Asia/Bangkok', 'Asia/Dubai', 'Asia/Hong_Kong', 'Asia/Kolkata', 'Asia/Seoul', 'Asia/Shanghai',
  'Asia/Singapore', 'Asia/Tokyo', 'Australia/Sydney', 'Europe/Berlin', 'Europe/Budapest', 'Europe/London', 'Europe/Madrid',
  'Europe/Moscow', 'Europe/Paris', 'Pacific/Auckland', 'Pacific/Honolulu',
];

function knownZones(): string[] {
  try {
    const zones = Intl.supportedValuesOf('timeZone');
    if (zones.length > 0) return zones.includes('UTC') ? zones : [...zones, 'UTC'];
  } catch {
    // Older browsers lack supportedValuesOf.
  }

  return FALLBACK_ZONES;
}

/**
 * Every zone grouped by region (zones sharing a region are adjacent, as SearchableSelect needs), plus any
 * `extra` ids that are not in the list (an alias such as "Asia/Calcutta" a visitor's browser reported must stay
 * visible and removable).
 */
export function timezoneOptions(extra: readonly string[] = []): TimezoneOption[] {
  const ids = [...new Set([...knownZones(), ...extra])];

  const options = ids.map((value) => {
    const slash = value.indexOf('/');
    const text = value.replaceAll('_', ' ');

    return { value, text, group: slash === -1 ? 'Other' : value.slice(0, slash).replaceAll('_', ' '), keywords: [value.toLowerCase(), text.toLowerCase()] };
  });
  // Stable by region, then by id; "Other" (UTC and friends) last.
  options.sort((a, b) => (a.group === 'Other' ? 1 : 0) - (b.group === 'Other' ? 1 : 0) || a.group.localeCompare(b.group) || a.value.localeCompare(b.value));

  return options;
}
