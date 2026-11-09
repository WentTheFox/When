/**
 * Languages for the "language" custom field type. The stored entries are lowercase ISO 639 language codes ("en",
 * "hu"; a browser locale such as "en-US" is reduced to its language), kept like a "list" (see encryptedList.ts).
 * Names come from the browser's own language data in the viewer's language, so there is no list of names to ship.
 */
export const LANGUAGE_CODES: readonly string[] = (
  'aa ab ae af ak am an ar as av ay az ba be bg bh bi bm bn bo br bs ca ce ch co cr cs cu cv cy da de dv dz ee el en eo ' +
  'es et eu fa ff fi fj fo fr fy ga gd gl gn gu gv ha he hi ho hr ht hu hy hz ia id ie ig ii ik io is it iu ja jv ka kg ' +
  'ki kj kk kl km kn ko kr ks ku kv kw ky la lb lg li ln lo lt lu lv mg mh mi mk ml mn mr ms mt my na nb nd ne ng nl nn ' +
  'no nr nv ny oc oj om or os pa pi pl ps pt qu rm rn ro ru rw sa sc sd se sg si sk sl sm sn so sq sr ss st su sv sw ta ' +
  'te tg th ti tk tl tn to tr ts tt tw ty ug uk ur uz ve vi vo wa wo xh yi yo za zh zu'
).split(' ');

export interface LanguageOption {
  /** Lowercase language code. */
  value: string;
  text: string;
  /** Extra search terms: the code and the language's name in its own language ("Deutsch"). */
  keywords: string[];
}

function displayNames(locale: string | undefined): Intl.DisplayNames | null {
  try {
    return new Intl.DisplayNames(locale ? [locale] : undefined, { type: 'language' });
  } catch {
    return null;
  }
}

/** The name of a language in a locale, or the code itself when the browser doesn't know it. */
export function languageName(code: string, locale?: string): string {
  try {
    return displayNames(locale)?.of(code) ?? code;
  } catch {
    return code;
  }
}

/**
 * Every language sorted by its name in `locale`, plus any `extra` codes that are not in the list (an old or
 * unusual value must stay visible and removable rather than silently vanish from the control).
 */
export function languageOptions(locale?: string, extra: readonly string[] = []): LanguageOption[] {
  const names = displayNames(locale);
  const codes = [...new Set([...LANGUAGE_CODES, ...extra])];

  const options = codes.map((value) => {
    let text = value;
    const keywords = [value];
    try {
      text = names?.of(value) ?? value;
      // A code the browser can't name comes back as itself; the native name is then the same, nothing to add.
      const native = displayNames(value)?.of(value);
      if (native && native.toLowerCase() !== text.toLowerCase() && native !== value) keywords.push(native.toLowerCase());
    } catch {
      // A code Intl rejects stays listed by its code.
    }

    return { value, text, keywords };
  });
  options.sort((a, b) => a.text.localeCompare(b.text, locale));

  return options;
}

/** The language of a browser locale tag ("en-US" gives "en"), or null when it has none. */
export function languageOfLocale(locale: string | null | undefined): string | null {
  const match = /^([A-Za-z]{2,3})(?=$|[-_])/.exec((locale ?? '').trim());

  return match ? match[1].toLowerCase() : null;
}
