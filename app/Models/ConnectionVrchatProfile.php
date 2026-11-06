<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One VRChat account linked to a connection, with its cached profile and
 * rolled-up time-together stats. §0.2 tier: every text column is APP_KEY-
 * encrypted at rest; vrchat_user_id_hash is the lookup key.
 *
 * @property string $id
 * @property string $connection_id
 * @property string $vrchat_user_id_hash
 * @property string $vrchat_user_id
 * @property string|null $display_name
 * @property string|null $profile_picture_url
 * @property string|null $avatar_thumbnail_url
 * @property array<array-key, mixed>|null $stats
 * @property Carbon|null $profile_fetched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Connection|null $connection
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile whereAvatarThumbnailUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile whereConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile whereProfileFetchedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile whereProfilePictureUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile whereStats($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile whereVrchatUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatProfile whereVrchatUserIdHash($value)
 *
 * @mixin \Eloquent
 */
class ConnectionVrchatProfile extends Model
{
    use HasUuids;

    protected $fillable = [
        'connection_id',
        'vrchat_user_id_hash',
        'vrchat_user_id',
        'display_name',
        'profile_picture_url',
        'avatar_thumbnail_url',
        'stats',
        'profile_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'vrchat_user_id' => 'encrypted',
            'display_name' => 'encrypted',
            'profile_picture_url' => 'encrypted',
            'avatar_thumbnail_url' => 'encrypted',
            'stats' => 'encrypted:array',
            'profile_fetched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Connection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
