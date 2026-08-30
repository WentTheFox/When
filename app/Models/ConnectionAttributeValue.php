<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectionAttributeValue extends Model
{
    use HasUuids;

    protected $fillable = [
        'connection_id',
        'attribute_definition_id',
        'value_ciphertext',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ConnectionAttributeDefinition::class, 'attribute_definition_id');
    }
}
