<?php

namespace App\Models;

use App\Models\Concerns\HasSortOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $label_ciphertext
 * @property string $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $options_ciphertext
 * @property string|null $icon_rules_ciphertext
 * @property Carbon|null $deleted_at
 * @property int $sort_order
 * @property-read User|null $user
 * @property-read Collection<int, ConnectionAttributeValue> $values
 * @property-read int|null $values_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition whereIconRulesCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition whereLabelCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition whereOptionsCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition withoutTrashed()
 *
 * @property string|null $staged_label
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionAttributeDefinition whereStagedLabel($value)
 *
 * @mixin \Eloquent
 */
class ConnectionAttributeDefinition extends Model
{
    use HasSortOrder, HasUuids, SoftDeletes;

    /**
     * Every custom field type. The single list the create endpoint validates against; the connection_attribute_definitions_type_check
     * constraint (one migration per new type) and the demo seeder must agree with it, and every type needs an entry in
     * Tests\Fixtures\CustomFieldTypeFixtures so the browser test exercises it (CustomFieldTypeCoverageTest enforces all three).
     */
    public const TYPES = [
        'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'country', 'language', 'timezone',
        'list', 'link_list', 'identifier', 'identifier_list', 'boolean', 'connection', 'relationship',
    ];

    protected $fillable = [
        'id',
        'user_id',
        'label_ciphertext',
        'staged_label',
        'type',
        'options_ciphertext',
        'icon_rules_ciphertext',
        'sort_order',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ConnectionAttributeValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ConnectionAttributeValue::class, 'attribute_definition_id');
    }
}
