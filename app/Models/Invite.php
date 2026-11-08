<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $inviter_user_id
 * @property string $code
 * @property int|null $max_uses
 * @property Carbon|null $used_at
 * @property Carbon|null $expires_at
 * @property string|null $source_share_link_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $inviter
 * @property-read Collection<int, InviteRedemption> $redemptions
 * @property-read int|null $redemptions_count
 * @property-read ShareLink|null $sourceShareLink
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereInviterUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereMaxUses($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereSourceShareLinkId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Invite extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'inviter_user_id',
        'code',
        'max_uses',
        'used_at',
        'expires_at',
        'source_share_link_id',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    /** @return BelongsTo<ShareLink, $this> */
    public function sourceShareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class, 'source_share_link_id');
    }

    /** @return HasMany<InviteRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(InviteRedemption::class);
    }
}
