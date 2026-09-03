<?php

namespace App\Support;

/**
 * The shape every owner-authored "localized text" field uses now
 * (`users.public_page_title`, `highlight_roles.label`) — a single JSON
 * object with a required `default` key plus any number of language-code-
 * keyed overrides (`hu`, `de`, ...), stored as one column instead of a
 * fixed `_en`/`_hu` column pair per field. Adding a third/fourth language
 * is then just typing a new key in the object — no migration, no new
 * column, no new backend field — which a fixed-column-pair design can't
 * offer without a schema change per language.
 *
 * `default` is deliberately not tied to any specific language (it's
 * NOT "the English one") — it's just whichever value a viewer sees when
 * there's no override for their own locale. For public_page_title this
 * mirrors the field's own pre-existing "may be blank" convention (falls
 * through to a computed default the caller supplies); for a
 * HighlightRole's label, the caller enforces `default` as required at
 * validation time instead, since there's no sensible fallback for a
 * role's own display label.
 */
class LocalizedText
{
    /**
     * @param  array<string, string>|null  $value
     */
    public static function resolve(?array $value, string $locale): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value[$locale] ?? $value['default'] ?? null;
    }
}
