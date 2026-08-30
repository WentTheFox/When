<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareLinkCache extends Model
{
    use HasUuids;

    protected $table = 'share_link_cache';

    protected $fillable = [
        'share_link_id',
        'ciphertext',
        'computed_range_start',
        'computed_range_end',
        'encrypted_at',
    ];

    protected function casts(): array
    {
        return [
            'computed_range_start' => 'datetime',
            'computed_range_end' => 'datetime',
            'encrypted_at' => 'datetime',
        ];
    }

    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class);
    }
}
