<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One interval in which the owner shared a VRChat instance with a linked
 * account. payload (APP_KEY-encrypted, §0.2 tier) holds vrchat_user_id,
 * start, end, duration_ms and other_player_count; dedupe_hash is the keyed
 * hash of (user id, start) so a re-sync updates rather than duplicates.
 *
 * @property string $id
 * @property string $connection_id
 * @property string $vrchat_user_id_hash
 * @property string $dedupe_hash
 * @property array<array-key, mixed> $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Connection|null $connection
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatStay newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatStay newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatStay query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatStay whereConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatStay whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatStay whereDedupeHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatStay whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatStay wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatStay whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionVrchatStay whereVrchatUserIdHash($value)
 * @mixin \Eloquent
 */
class ConnectionVrchatStay extends Model
{
    use HasUuids;

    protected $fillable = [
        'connection_id',
        'vrchat_user_id_hash',
        'dedupe_hash',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
        ];
    }

    /** @return BelongsTo<Connection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
