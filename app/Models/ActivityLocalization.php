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
 * display label, not calendar-derived text.
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
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'pattern' => 'encrypted',
            'pattern_preview' => 'encrypted',
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
