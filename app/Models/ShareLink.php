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
        'archived',
        'bypass_dnd',
        'show_activity',
        'legacy_token',
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

    /**
     * The connection this link is "for," if the owner has tied one — set
     * from Connection::share_link_id, not a column here. hasOne rather
     * than hasMany: nothing stops more than one connection pointing at the
     * same link at the schema level, but the picker only ever shows/sets
     * one, and this always resolves to whichever one comes first.
     */
    public function connection(): HasOne
    {
        return $this->hasOne(Connection::class);
    }
}
