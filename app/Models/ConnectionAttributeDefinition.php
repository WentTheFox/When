<?php

namespace App\Models;

use App\Models\Concerns\HasSortOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConnectionAttributeDefinition extends Model
{
    use HasSortOrder, HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'label_ciphertext',
        'type',
        'options_ciphertext',
        'sort_order',
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
