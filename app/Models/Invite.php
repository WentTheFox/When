<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invite extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'inviter_user_id',
        'code',
        'max_uses',
        'used_at',
        'expires_at',
        'source_share_link_id',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    public function sourceShareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class, 'source_share_link_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(InviteRedemption::class);
    }
}
