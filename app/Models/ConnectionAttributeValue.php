<?php

namespace App\Models;

use App\Casts\EncryptedBoolean;
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
        'identifier_value',
        'identifier_list_value',
        'boolean_value',
    ];

    /**
     * identifier_value is the one deliberately non-E2EE field on this table
     * (§0.2 tier — see ConnectionController's doc comment): APP_KEY-encrypted
     * at rest, readable server-side for future external-service matching.
     */
    protected function casts(): array
    {
        return [
            'identifier_value' => 'encrypted',
            // Same §0.2 tier, list-valued: a JSON array of strings (identifier_list definitions).
            'identifier_list_value' => 'encrypted:array',
            // Same §0.2 tier: a yes/no the server can act on (boolean definitions).
            'boolean_value' => EncryptedBoolean::class,
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ConnectionAttributeDefinition::class, 'attribute_definition_id');
    }
}
