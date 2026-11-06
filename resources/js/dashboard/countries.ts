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
