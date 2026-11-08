<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A WebAuthn credential registered as a second factor (see
 * App\Services\PasskeyService). credential_id is base64url; public_key is
 * the PEM the authenticator handed over at registration — neither is
 * secret. `name` is the owner's own label (§0.2 `encrypted` cast, never
 * queried by value).
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $credential_id
 * @property string $public_key
 * @property int $sign_count
 * @property array<array-key, mixed>|null $transports
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey whereCredentialId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey wherePublicKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey whereSignCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey whereTransports($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Passkey withoutTrashed()
 * @mixin \Eloquent
 */
class Passkey extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'credential_id',
        'public_key',
        'sign_count',
        'transports',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'sign_count' => 'integer',
            'transports' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
