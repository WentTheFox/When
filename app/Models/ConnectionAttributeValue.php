<?php

namespace App\Models;

use App\Casts\EncryptedBoolean;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $connection_id
 * @property string $attribute_definition_id
 * @property string|null $value_ciphertext
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $identifier_value
 * @property array<array-key, mixed>|null $identifier_list_value
 * @property bool|null $boolean_value
 * @property-read Connection|null $connection
 * @property-read ConnectionAttributeDefinition|null $definition
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue whereAttributeDefinitionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue whereBooleanValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue whereConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue whereIdentifierListValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue whereIdentifierValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeValue whereValueCiphertext($value)
 *
 * @mixin \Eloquent
 */
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

    /** @return BelongsTo<Connection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /** @return BelongsTo<ConnectionAttributeDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ConnectionAttributeDefinition::class, 'attribute_definition_id');
    }
}
