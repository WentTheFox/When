<?php

namespace App\Models;

use App\Models\Concerns\HasSortOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $category_id
 * @property string $name_ciphertext
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int $sort_order
 * @property-read ConnectionSourceCategory|null $category
 * @property-read Collection<int, Connection> $connections
 * @property-read int|null $connections_count
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource ordered()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource whereNameCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionSource withoutTrashed()
 *
 * @mixin \Eloquent
 */
class ConnectionSource extends Model
{
    use HasSortOrder, HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'category_id',
        'name_ciphertext',
        'sort_order',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ConnectionSourceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ConnectionSourceCategory::class, 'category_id');
    }

    /** @return BelongsToMany<Connection, $this> */
    public function connections(): BelongsToMany
    {
        return $this->belongsToMany(Connection::class, 'connection_source_links', 'source_id', 'connection_id');
    }
}
