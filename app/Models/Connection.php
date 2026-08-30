<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Connection extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'source_id',
        'name_ciphertext',
        'notes_ciphertext',
        'share_link_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ConnectionSource::class, 'source_id');
    }

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
