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
        // §0.2 tier — Crypt/APP_KEY, populated instead of value_ciphertext
        // when the parent ConnectionAttributeDefinition has is_e2ee false.
        // See ConnectionController::serversideAttributeRow().
        'value_appkey_ciphertext',
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
