<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * start_date/end_date are §0.2 server-runtime Crypt/APP_KEY ciphertext
 * (2026_09_03_140000_encrypt_sleep_exception_dates) — same plain
 * 'encrypted' cast as User's own *_pattern columns, transparently
 * decrypting to a plain 'Y-m-d' string on every read. Never queried by
 * value (no `where`/`orderBy` on either column anywhere in the app after
 * that migration), so no *_hash/whereX() machinery needed.
 *
 * label_ciphertext is a different, unrelated tier — §0.1 client-vault
 * E2EE, untouched by that migration.
 *
 * @property string $id
 * @property string $user_id
 * @property string $start_date
 * @property string $end_date
 * @property string|null $label_ciphertext
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException whereEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException whereLabelCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException whereStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SleepException withoutTrashed()
 *
 * @mixin \Eloquent
 */
class SleepException extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'start_date',
        'end_date',
        'label_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'encrypted',
            'end_date' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
