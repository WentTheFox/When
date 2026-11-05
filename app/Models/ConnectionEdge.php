<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $from_connection_id
 * @property string $to_connection_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Connection|null $from
 * @property-read Connection|null $to
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge whereFromConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge whereToConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionEdge withoutTrashed()
 *
 * @mixin \Eloquent
 */
class ConnectionEdge extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'from_connection_id',
        'to_connection_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Connection, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'from_connection_id');
    }

    /** @return BelongsTo<Connection, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'to_connection_id');
    }
}
