<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareLinkManualTag extends Model
{
    use HasUuids;

    protected $fillable = [
        'share_link_id',
        'word_ciphertext',
        'weekday',
        'start_time',
        'end_time',
    ];

    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class);
    }
}
