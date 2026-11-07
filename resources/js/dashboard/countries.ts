/**
 * Countries for the "country" custom field type. The stored value is the ISO 3166-1 alpha-2 code (E2EE like
 * any other value); names come from the browser's own region data in the viewer's language, and the flag is
 * drawn by the flag-icons CSS (class `fi fi-xx`), so there is no list of names to ship or translate.
 */
export const COUNTRY_CODES: readonly string[] = (
  'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ ' +
  'CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR ' +
  'GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP ' +
  'KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT ' +
  'MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW ' +
  'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG ' +
  'UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW'
).split(' ');

export interface CountryOption {
  /** ISO 3166-1 alpha-2 code; '' is the "(unset)" entry. */
  value: string;
  text: string;
}

/** "(unset)" first, then every country sorted by its name in the given locale. */
export function countryOptions(locale?: string): CountryOption[] {
  let names: Intl.DisplayNames | null = null;
  try {
    names = new Intl.DisplayNames(locale ? [locale] : undefined, { type: 'region' });
  } catch {
    names = null;
  }

  const options = COUNTRY_CODES.map((code) => ({ value: code, text: names?.of(code) ?? code }));
  options.sort((a, b) => a.text.localeCompare(b.text, locale));

  return [{ value: '', text: '(unset)' }, ...options];
}

/**
 * Common alternative names and abbreviations, matched in every country search (the country selector and the
 * icon picker's flags) on top of the localized name and the code. English only: they are search hints, never shown.
 */
export const COUNTRY_ALIASES: Readonly<Record<string, readonly string[]>> = {
  GB: ['uk', 'u.k.', 'britain', 'great britain', 'england', 'scotland', 'wales', 'northern ireland', 'united kingdom of great britain'],
  US: ['usa', 'u.s.', 'u.s.a.', 'america', 'united states of america', 'the states'],
  AE: ['uae', 'emirates'],
  CZ: ['czech republic'],
  NL: ['holland'],
  DE: ['deutschland'],
  CH: ['switzerland', 'schweiz', 'suisse'],
  KR: ['south korea', 'korea'],
  KP: ['north korea', 'korea'],
  RU: ['russian federation'],
  CD: ['drc', 'congo-kinshasa', 'democratic republic of the congo'],
  CG: ['congo-brazzaville', 'republic of the congo'],
  CI: ['ivory coast'],
  MM: ['burma'],
  SZ: ['swaziland'],
  TR: ['turkey', 'turkiye'],
  VA: ['vatican', 'holy see'],
  MK: ['macedonia'],
  CV: ['cape verde'],
  TL: ['east timor'],
  HK: ['hong kong'],
  MO: ['macau'],
  PS: ['palestine'],
  TW: ['taiwan', 'roc'],
  IE: ['eire'],
  FM: ['micronesia'],
  BA: ['bosnia'],
  TT: ['trinidad', 'tobago'],
  AG: ['antigua', 'barbuda'],
  KN: ['st kitts', 'nevis'],
  VC: ['st vincent', 'grenadines'],
  ST: ['sao tome'],
};

/** Every term a country is searchable by beyond its displayed name: its code and the aliases above, lowercased. */
export function countryKeywords(code: string): string[] {
  return [code.toLowerCase(), ...(COUNTRY_ALIASES[code.toUpperCase()] ?? [])];
}

/**
 * How well a search query matches: 0 = exactly the name or a keyword (so "uk" puts the United Kingdom above
 * Ukraine), 1 = the start of the name, a word of it, or a keyword, 2 = anywhere inside one of them, null = no match.
 */
export function searchRank(query: string, texts: readonly string[], keywords: readonly string[] = []): number | null {
  const q = query.trim().toLowerCase();
  if (q === '') return 0;

  const all = [...texts, ...keywords].map((t) => t.toLowerCase());
  if (all.some((t) => t === q)) return 0;
  if (all.some((t) => t.startsWith(q) || t.split(/[\s\-/]+/).some((word) => word.startsWith(q)))) return 1;
  if (all.some((t) => t.includes(q))) return 2;

  return null;
}

/** A rule icon that is a country flag instead of a palette icon is stored as `flag:xx` (lowercase alpha-2). */
export const FLAG_ICON_PREFIX = 'flag:';

export function flagIconKey(code: string): string {
  return FLAG_ICON_PREFIX + code.toLowerCase();
}

/** The country code behind a `flag:xx` icon key, or null when the key is not a (known) flag. */
export function flagIconCode(key: string | null | undefined): string | null {
  if (!key || !key.startsWith(FLAG_ICON_PREFIX)) return null;
  const code = key.slice(FLAG_ICON_PREFIX.length).toUpperCase();

  return COUNTRY_CODES.includes(code) ? code : null;
}
