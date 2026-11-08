<?php

namespace App\Models;

use App\Models\Concerns\HasSortOrder;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name_ciphertext
 * @property string|null $notes_ciphertext
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $archived
 * @property Carbon|null $deleted_at
 * @property int $sort_order
 * @property-read Collection<int, ConnectionAttributeValue> $attributeValues
 * @property-read int|null $attribute_values_count
 * @property-read Collection<int, ConnectionEdge> $edgesFrom
 * @property-read int|null $edges_from_count
 * @property-read Collection<int, ConnectionEdge> $edgesTo
 * @property-read int|null $edges_to_count
 * @property-read ShareLink|null $shareLink
 * @property-read Collection<int, ConnectionSource> $sources
 * @property-read int|null $sources_count
 * @property-read User|null $user
 *
 * @method static \Database\Factories\ConnectionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereArchived($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereNameCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereNotesCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection withoutTrashed()
 *
 * @property-read Collection<int, ConnectionVrchatProfile> $vrchatProfiles
 * @property-read int|null $vrchat_profiles_count
 * @property-read Collection<int, ConnectionVrchatStay> $vrchatStays
 * @property-read int|null $vrchat_stays_count
 *
 * @mixin \Eloquent
 */
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A connection can belong to more than one source (connection_source_links).
     *
     * @return BelongsToMany<ConnectionSource, $this>
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(ConnectionSource::class, 'connection_source_links', 'connection_id', 'source_id');
    }

    /**
     * The connection's one optional share link (the FK lives on share_links.connection_id, unique).
     *
     * @return HasOne<ShareLink, $this>
     */
    public function shareLink(): HasOne
    {
        return $this->hasOne(ShareLink::class);
    }

    /** @return HasMany<ConnectionAttributeValue, $this> */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ConnectionAttributeValue::class);
    }

    /** @return HasMany<ConnectionVrchatProfile, $this> */
    public function vrchatProfiles(): HasMany
    {
        return $this->hasMany(ConnectionVrchatProfile::class);
    }

    /** @return HasMany<ConnectionVrchatStay, $this> */
    public function vrchatStays(): HasMany
    {
        return $this->hasMany(ConnectionVrchatStay::class);
    }

    /** @return HasMany<ConnectionEdge, $this> */
    public function edgesFrom(): HasMany
    {
        return $this->hasMany(ConnectionEdge::class, 'from_connection_id');
    }

    /** @return HasMany<ConnectionEdge, $this> */
    public function edgesTo(): HasMany
    {
        return $this->hasMany(ConnectionEdge::class, 'to_connection_id');
    }
}
