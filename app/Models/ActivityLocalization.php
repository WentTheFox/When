<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An owner-configurable (pattern, label) pair — generalizes the old
 * hardcoded "Host X"/"Visit X" convention into an arbitrary, ordered
 * list. `pattern` (and `pattern_preview`, the owner's own edited example
 * text for its live preview tester — see PatternPreview.vue) are §0.2
 * server-runtime Crypt/APP_KEY ciphertext, same tier and same plain
 * 'encrypted' cast as User's own *_pattern columns
 * (2026_09_03_120000_encrypt_pattern_and_timezone_fields) — never looked
 * up by value, so no *_hash/whereX() machinery needed. `label` is a
 * separate App\Support\LocalizedText — one row per locale in the shared
 * `localized_texts` table (see HasLocalizedFields), not a column on this
 * model — untouched by that migration, still plain: an owner-chosen
 * display label, not calendar-derived text. `icon_key` is a curated
 * App\Support\IconKey value (like every other *_icon_key column) shown on
 * a matched highlighted block instead of the generic highlighted icon —
 * plain, not encrypted, and optional: null just keeps using that generic
 * icon. `color_key` is the same idea for color: a curated
 * App\Support\ColorSwatchKey value shown instead of the share link's own
 * highlight_color_key — plain, not encrypted, optional: null keeps using
 * that regular highlighted color. `has_capture_group` is computed once at
 * validation time (ActivityLocalizationController, via App\Support\Regex::
 * countCaptureGroups) rather than re-derived from `pattern` on every
 * match — `pattern`'s capture group is now optional (0 or 1, never 2+):
 * a pattern with none (e.g. `^gaming`) only gates whether this role
 * applies at all, and HighlightMatcher::matchClauseText falls back to the
 * owner's default/custom highlight clause pattern to actually capture the
 * highlighted name(s) instead of forcing every role's own pattern to
 * duplicate that "with X, Y, Z" capturing itself.
 */
class ActivityLocalization extends Model
{
    /**
     * @use HasLocalizedFields<ActivityLocalization>
     */
    use HasLocalizedFields, HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'pattern',
        'pattern_preview',
        'has_capture_group',
        'sort_order',
        'icon_key',
        'color_key',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'pattern' => 'encrypted',
            'pattern_preview' => 'encrypted',
            'has_capture_group' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string>|null */
    public function getLabelAttribute(): ?array
    {
        return $this->getLocalizedField('label');
    }
}
