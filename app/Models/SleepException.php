<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * start_date/end_date are §0.2 server-runtime Crypt/APP_KEY ciphertext
 * (2026_09_03_140000_encrypt_sleep_exception_dates) — same plain
 * 'encrypted' cast as User's own *_pattern columns, transparently
 * decrypting to a plain 'Y-m-d' string on every read. Never queried by
 * value (no `where`/`orderBy` on either column anywhere in the app after
 * that migration), so no *_hash/whereX() machinery needed.
 * label_ciphertext is a different, unrelated tier — §0.1 client-vault
 * E2EE, untouched by that migration.
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
