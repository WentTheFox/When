<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Connection extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'name_ciphertext',
        'notes_ciphertext',
        'share_link_id',
        'archived',
    ];

    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A connection can belong to more than one source (connection_source_links). */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(ConnectionSource::class, 'connection_source_links', 'connection_id', 'source_id');
    }

    /** The one share link this connection is "for" — the picker in ShareLinkCard.vue sets this from the link's own side, but the FK lives here since a connection can only ever point at one link. */
    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class);
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ConnectionAttributeValue::class);
    }

    public function edgesFrom(): HasMany
    {
        return $this->hasMany(ConnectionEdge::class, 'from_connection_id');
    }

    public function edgesTo(): HasMany
    {
        return $this->hasMany(ConnectionEdge::class, 'to_connection_id');
    }
}
