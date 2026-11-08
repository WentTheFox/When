<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $share_link_id
 * @property string $ciphertext
 * @property Carbon $computed_range_start
 * @property Carbon $computed_range_end
 * @property Carbon $encrypted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ShareLink|null $shareLink
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkCache newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkCache newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkCache query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkCache whereCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkCache whereComputedRangeEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkCache whereComputedRangeStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkCache whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkCache whereEncryptedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkCache whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkCache whereShareLinkId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShareLinkCache whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ShareLinkCache extends Model
{
    use HasUuids;

    protected $table = 'share_link_cache';

    protected $fillable = [
        'share_link_id',
        'ciphertext',
        'computed_range_start',
        'computed_range_end',
        'encrypted_at',
    ];

    protected function casts(): array
    {
        return [
            'computed_range_start' => 'datetime',
            'computed_range_end' => 'datetime',
            'encrypted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ShareLink, $this> */
    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class);
    }
}
