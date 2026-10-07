<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A WebAuthn credential registered as a second factor (see
 * App\Services\PasskeyService). credential_id is base64url; public_key is
 * the PEM the authenticator handed over at registration — neither is
 * secret. `name` is the owner's own label (§0.2 `encrypted` cast, never
 * queried by value).
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
