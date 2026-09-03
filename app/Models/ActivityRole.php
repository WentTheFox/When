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
 * list. `pattern` is matched the same way highlight_clause_pattern is
 * (a regex fragment requiring exactly one capture group, see
 * HighlightMatcher); `label` is an App\Support\LocalizedText — one row
 * per locale in the shared `localized_texts` table (see
 * HasLocalizedFields), not a column on this model — never plaintext
 * freetext extracted from the owner's own calendar, an owner writes it
 * themselves, so unlike calendar_url/connections data there's nothing
 * here that needs §0.1/§0.2 treatment.
 */
class ActivityRole extends Model
{
    use HasLocalizedFields, HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'pattern',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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
