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
 * @property string $detected_mode
 * @property Carbon $fetched_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CalendarDetection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CalendarDetection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CalendarDetection onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CalendarDetection query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CalendarDetection whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CalendarDetection whereDetectedMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CalendarDetection whereFetchedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CalendarDetection whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CalendarDetection whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CalendarDetection withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CalendarDetection withoutTrashed()
 *
 * @mixin \Eloquent
 */
class CalendarDetection extends Model
{
    use HasUuids, SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'detected_mode',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
