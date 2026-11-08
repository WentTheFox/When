<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $invite_id
 * @property string $user_id
 * @property Carbon $redeemed_at
 * @property Carbon|null $deleted_at
 * @property-read Invite|null $invite
 * @property-read User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InviteRedemption newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InviteRedemption newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InviteRedemption onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InviteRedemption query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InviteRedemption whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InviteRedemption whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InviteRedemption whereInviteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InviteRedemption whereRedeemedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InviteRedemption whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InviteRedemption withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InviteRedemption withoutTrashed()
 * @mixin \Eloquent
 */
class InviteRedemption extends Model
{
    use HasUuids, SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'invite_id',
        'user_id',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invite, $this> */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(Invite::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
