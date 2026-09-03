<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConnectionEdge extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'from_connection_id',
        'to_connection_id',
        'label_ciphertext',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'from_connection_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'to_connection_id');
    }
}
