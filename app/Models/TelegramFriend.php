<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Telegram contact the sync has seen (§0.2 tier, APP_KEY-encrypted at rest; telegram_user_id_hash is the
 * lookup key). status: 'pending' = waiting for the owner, 'created' = they have a connection, 'dismissed' = the
 * owner chose not to add them. avatar_url is a small photo thumbnail as a data URL.
 *
 * @property string $id
 * @property string $user_id
 * @property string $telegram_user_id_hash
 * @property string $telegram_user_id
 * @property string|null $username
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $phone
 * @property string|null $avatar_url
 * @property bool $is_mutual
 * @property bool $is_premium
 * @property bool $has_photo
 * @property Carbon|null $photo_checked_at
 * @property string $status
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereAvatarUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereFirstName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereIsMutual($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereIsPremium($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereLastName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereSyncedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereTelegramUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereTelegramUserIdHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend whereHasPhoto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramFriend wherePhotoCheckedAt($value)
 *
 * @mixin \Eloquent
 */
class TelegramFriend extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const CREATED = 'created';

    public const DISMISSED = 'dismissed';

    protected $fillable = [
        'user_id',
        'telegram_user_id_hash',
        'telegram_user_id',
        'username',
        'first_name',
        'last_name',
        'phone',
        'avatar_url',
        'is_mutual',
        'is_premium',
        'has_photo',
        'photo_checked_at',
        'status',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'encrypted',
            'username' => 'encrypted',
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'phone' => 'encrypted',
            'avatar_url' => 'encrypted',
            'is_mutual' => 'boolean',
            'is_premium' => 'boolean',
            'has_photo' => 'boolean',
            'photo_checked_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /** "First Last", or whichever half there is; null when Telegram gave neither. */
    public function displayName(): ?string
    {
        $name = trim(implode(' ', array_filter([$this->first_name, $this->last_name])));

        return $name === '' ? null : $name;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
