<?php

namespace App\Models\Concerns;

use App\Models\Translation;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backs a model's own App\Support\LocalizedText-shaped fields (e.g.
 * users.public_page_title, activity_localizations.label — before this trait,
 * each was a single JSON column; now each row lives in the shared
 * `localized_texts` table instead, one per (field, locale)). A field
 * using this is deliberately NOT in the model's own $fillable/mass-
 * assignment — same "outside fill(), handled explicitly" pattern
 * User::calendar_url_ciphertext already uses — call
 * getLocalizedField()/setLocalizedField() directly instead.
 *
 * @template T
 */
trait HasLocalizedFields
{
    /**
     * @return MorphMany<T>
     */
    public function localizedTexts(): MorphMany
    {
        return $this->morphMany(Translation::class, 'localizable');
    }

    /**
     * Assembles this model's own locale => text map for one field, in
     * the exact App\Support\LocalizedText shape every caller already
     * expects — null if nothing's been set for it at all (matching the
     * old JSON column's own "null means unset" convention).
     *
     * @return array<string, string>|null
     */
    public function getLocalizedField(string $field): ?array
    {
        $rows = $this->localizedTexts->where('field', $field);

        return $rows->isEmpty() ? null : $rows->pluck('text', 'locale')->all();
    }

    /**
     * Replaces every row for this field with exactly what's in $value —
     * simplest correct way to reconcile "some locales added, some
     * removed, some edited" in one call, at the cost of a delete-then-
     * insert instead of a diff/upsert (this app's own localized fields
     * are small, owner-edited lists, not something saved often enough
     * for that cost to matter).
     *
     * @param  array<string, string>|null  $value
     */
    public function setLocalizedField(string $field, ?array $value): void
    {
        // Delete-then-insert across a whole locale set — wrapped so a
        // failure partway through (e.g. one locale's insert erroring)
        // can never leave the field with only some of its rows removed
        // and none of the replacements written.
        DB::transaction(function () use ($field, $value) {
            // forceDelete(), not delete(): this rewrites the field's rows
            // on every ordinary save (Translation now has SoftDeletes,
            // added only to serve account-wide deletion — App\Services\
            // Account\AccountDeletionService). A plain delete() here would
            // leave a soft-deleted row behind on every single edit,
            // accumulating garbage and risking a duplicate (field, locale)
            // pair once the row is ever un-hidden.
            $this->localizedTexts()->where('field', $field)->forceDelete();

            foreach ($value ?? [] as $locale => $text) {
                if (! is_string($text) || $text === '') {
                    continue;
                }

                $this->localizedTexts()->create([
                    'id' => (string) Str::uuid(),
                    'field' => $field,
                    'locale' => $locale,
                    'text' => $text,
                ]);
            }
        });
    }
}
