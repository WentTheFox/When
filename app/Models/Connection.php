<?php

namespace App\Models;

use App\Models\Concerns\HasSortOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Connection extends Model
{
    use HasFactory, HasSortOrder, HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'name_ciphertext',
        'notes_ciphertext',
        'archived',
        'sort_order',
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

    /** The connection's one optional share link (the FK lives on share_links.connection_id, unique). */
    public function shareLink(): HasOne
    {
        return $this->hasOne(ShareLink::class);
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
