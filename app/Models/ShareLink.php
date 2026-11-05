<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShareLink extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'connection_id',
        'archived',
        'bypass_dnd',
        'highlight_token',
    ];

    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
            'bypass_dnd' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every share link's public identifier — used as both its /free URL
     * segment and (via App\Services\Crypto\HighlightTokenKey) its content
     * key derivation. Same generation method as the old app's own
     * CalendarHighlightToken::generateToken(): 32 random bytes, base64url
     * encoded (`+`/`/` swapped for `-`/`_`, `=` padding stripped), retried
     * until the result happens to contain no `-`/`_` at all — i.e. until
     * it's alphanumeric-only, nothing to escape in a URL path segment.
     * Plus one addition of our own: also retried on a token collision,
     * since the old app had no equivalent uniqueness constraint to satisfy.
     */
    public static function generateHighlightToken(): string
    {
        do {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } while (str_contains($token, '-') || str_contains($token, '_') || static::where('highlight_token', $token)->exists());

        return $token;
    }

    public function words(): HasMany
    {
        return $this->hasMany(ShareLinkWord::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ShareLinkVisit::class);
    }

    public function cache(): HasOne
    {
        return $this->hasOne(ShareLinkCache::class);
    }

    /** The connection this link belongs to (NOT NULL, unique: exactly one link per connection). Its name is the link's name. */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
