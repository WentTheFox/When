<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A VRChat friend the VRCX sync has seen (§0.2 tier, APP_KEY-encrypted at
 * rest; vrchat_user_id_hash is the lookup key). status: 'pending' = the
 * browser should link them to a matching connection or create one, 'created'
 * = they have a connection, 'dismissed' = they matched several connections and
 * are left for the owner to sort out.
 *
 * @property string $id
 * @property string $user_id
 * @property string $vrchat_user_id_hash
 * @property string $vrchat_user_id
 * @property string|null $display_name
 * @property string|null $profile_picture_url
 * @property string $status
 * @property Carbon|null $claimed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend whereClaimedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend whereProfilePictureUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend whereVrchatUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VrchatFriend whereVrchatUserIdHash($value)
 *
 * @mixin \Eloquent
 */
class VrchatFriend extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const CREATED = 'created';

    public const DISMISSED = 'dismissed';

    protected $fillable = [
        'user_id',
        'vrchat_user_id_hash',
        'vrchat_user_id',
        'display_name',
        'profile_picture_url',
        'status',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'vrchat_user_id' => 'encrypted',
            'display_name' => 'encrypted',
            'profile_picture_url' => 'encrypted',
            'claimed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
