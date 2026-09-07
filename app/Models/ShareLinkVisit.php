<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareLinkVisit extends Model
{
    use HasFactory, HasUuids;

    /** No updated_at — a visit record is never modified after it's written. */
    const UPDATED_AT = null;

    /** visited_at, not created_at — see the migration's doc comment. */
    const CREATED_AT = 'visited_at';

    protected $fillable = [
        'id',
        'share_link_id',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'timezone' => 'encrypted',
        ];
    }

    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class);
    }
}
