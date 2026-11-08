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
 * @property string $name_ciphertext
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $color_key
 * @property Carbon|null $deleted_at
 * @property int $sort_order
 * @property-read Collection<int, ConnectionSource> $sources
 * @property-read int|null $sources_count
 * @property-read User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory whereColorKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory whereNameCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSourceCategory withoutTrashed()
 * @mixin \Eloquent
 */
class ConnectionSourceCategory extends Model
{
    use HasSortOrder, HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'name_ciphertext',
        'color_key',
        'sort_order',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ConnectionSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(ConnectionSource::class, 'category_id');
    }
}
