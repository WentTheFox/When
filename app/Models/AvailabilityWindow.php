<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekday's wake/sleep window for a user — always exactly 7 rows per
 * user (weekday 0=Sun..6=Sat), replacing the old single
 * users.availability_settings JSON blob. wake_time/sleep_time are §0.2
 * server-runtime Crypt/APP_KEY ciphertext (see the table's own creation
 * migration) — same plain 'encrypted' cast as User's own *_pattern
 * columns, never queried by value. Read/written via
 * User::weeklyAvailability()/setWeeklyAvailability() rather than this
 * model directly, so every existing consumer of the old
 * $weeklyAvailability array shape (AvailabilityService::compute() and
 * callers) needs no changes.
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
