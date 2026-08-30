<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectionSource extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'category_id',
        'name_ciphertext',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ConnectionSourceCategory::class, 'category_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class, 'source_id');
    }
}
