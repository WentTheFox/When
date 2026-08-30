<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShareLink extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'label_ciphertext',
        'key_protection',
        'wrapped_key',
        'wrap_salt',
        'archived',
        'bypass_dnd',
        'legacy_token',
    ];

    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
            'bypass_dnd' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function words(): HasMany
    {
        return $this->hasMany(ShareLinkWord::class);
    }

    public function cache(): HasOne
    {
        return $this->hasOne(ShareLinkCache::class);
    }
}
