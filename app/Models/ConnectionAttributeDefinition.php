<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectionAttributeDefinition extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'label_ciphertext',
        'type',
        'options_ciphertext',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ConnectionAttributeValue::class, 'attribute_definition_id');
    }
}
