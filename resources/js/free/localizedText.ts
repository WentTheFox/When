/**
 * Client-side mirror of App\Support\LocalizedText — the shape every
 * owner-authored "localized text" field uses (public_page_title,
 * ActivityRole.label): a required `default` key plus any number of
 * language-code-keyed overrides. Resolution has to happen client-side
 * (not once server-side at compute/cache time) for anything that travels
 * through a share link's own cached, encrypted availability result —
 * that cache is shared across every locale route the link is viewed from
 * (`/free/token` and `/hu/free/token` alike), so baking in one language's
 * text at compute time would leak the wrong language to half of them.
 */
export interface LocalizedText {
  /** Required at the DB/validation layer for an ActivityRole's own label; left optional here since Public page title's own 'default' is allowed to stay blank (falls through to a computed default instead). */
  default?: string;
  [lang: string]: string | undefined;
}

export function resolveLocalizedText(value: LocalizedText | null | undefined, locale: string): string | null {
  if (!value) return null;
  return value[locale] ?? value.default ?? null;
}
