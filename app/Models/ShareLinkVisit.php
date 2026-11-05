<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $share_link_id
 * @property string $timezone
 * @property Carbon $visited_at
 * @property string|null $locale
 * @property-read ShareLink|null $shareLink
 *
 * @method static \Database\Factories\ShareLinkVisitFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkVisit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkVisit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkVisit query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkVisit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkVisit whereLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkVisit whereShareLinkId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkVisit whereTimezone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkVisit whereVisitedAt($value)
 *
 * @mixin \Eloquent
 */
class ShareLinkVisit extends Model
{
    use HasFactory, HasUuids;

    /** No updated_at — a visit record is never modified after it's written. */
    const UPDATED_AT = null;

    /** visited_at, not created_at — see the migration's doc comment. */
    const CREATED_AT = 'visited_at';

    protected $fillable = [
        'id',
        'share_link_id',
        'timezone',
        'locale',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'timezone' => 'encrypted',
            'locale' => 'encrypted',
        ];
    }

    /** @return BelongsTo<ShareLink, $this> */
    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class);
    }
}
