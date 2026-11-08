<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One weekday's wake/sleep window for a user — always exactly 7 rows per
 * user (weekday 0=Sun.
 *
 * .6=Sat), replacing the old single
 * users.availability_settings JSON blob. wake_time/sleep_time are §0.2
 * server-runtime Crypt/APP_KEY ciphertext (see the table's own creation
 * migration) — same plain 'encrypted' cast as User's own *_pattern
 * columns, never queried by value. Read/written via
 * User::weeklyAvailability()/setWeeklyAvailability() rather than this
 * model directly, so every existing consumer of the old
 * $weeklyAvailability array shape (AvailabilityService::compute() and
 * callers) needs no changes.
 *
 * @property string $id
 * @property string $user_id
 * @property int $weekday
 * @property string|null $wake_time
 * @property string|null $sleep_time
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AvailabilityWindow newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AvailabilityWindow newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AvailabilityWindow query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AvailabilityWindow whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AvailabilityWindow whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AvailabilityWindow whereSleepTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AvailabilityWindow whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AvailabilityWindow whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AvailabilityWindow whereWakeTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AvailabilityWindow whereWeekday($value)
 *
 * @mixin \Eloquent
 */
class AvailabilityWindow extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'weekday',
        'wake_time',
        'sleep_time',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'wake_time' => 'encrypted',
            'sleep_time' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
