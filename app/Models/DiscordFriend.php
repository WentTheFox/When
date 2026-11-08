<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Discord friend the sync has seen (§0.2 tier, APP_KEY-encrypted at rest;
 * discord_user_id_hash is the lookup key). status: 'pending' = waiting for the
 * owner, 'created' = they have a connection, 'dismissed' = the owner chose not
 * to add them, 'lookup' = looked up by id only.
 *
 * @property string $id
 * @property string $user_id
 * @property string $discord_user_id_hash
 * @property string $discord_user_id
 * @property string|null $username
 * @property string|null $global_name
 * @property string|null $nickname
 * @property string|null $avatar_url
 * @property string $status
 * @property bool $is_blocked
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereAvatarUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereDiscordUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereDiscordUserIdHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereGlobalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereNickname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereIsBlocked($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordFriend whereSyncedAt($value)
 *
 * @mixin \Eloquent
 */
class DiscordFriend extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const CREATED = 'created';

    public const DISMISSED = 'dismissed';

    /** Not a friend: looked up by id for a connection's Discord card. Never listed as pending. */
    public const LOOKUP = 'lookup';

    protected $fillable = [
        'user_id',
        'discord_user_id_hash',
        'discord_user_id',
        'username',
        'global_name',
        'nickname',
        'avatar_url',
        'status',
        'is_blocked',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'discord_user_id' => 'encrypted',
            'username' => 'encrypted',
            'global_name' => 'encrypted',
            'nickname' => 'encrypted',
            'avatar_url' => 'encrypted',
            'is_blocked' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
