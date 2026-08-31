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
        'id',
        'user_id',
        'label_ciphertext',
        'key_protection',
        'wrapped_key',
        'wrap_salt',
        'content_key_ciphertext',
        'archived',
        'bypass_dnd',
        'show_activity',
        'legacy_token',
    ];

    protected $hidden = [
        // Never serialized — decryption only ever happens transiently,
        // inside the recompute job. See PLAN.md §0.2/§5.3.
        'content_key_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
            'bypass_dnd' => 'boolean',
            'show_activity' => 'boolean',
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

    public function manualTags(): HasMany
    {
        return $this->hasMany(ShareLinkManualTag::class);
    }
}
