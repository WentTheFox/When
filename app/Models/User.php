<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'passphrase_salt',
        'key_ring_ciphertext',
        'timezone',
        'week_start',
        'dnd_event_name',
        'nap_event_name',
        'availability_settings',
        'calendar_parsing_mode',
        'highlight_clause_pattern',
        'activity_clause_pattern',
        'tentative_pattern',
        'public_page_title_en',
        'public_page_title_hu',
        'accent_color',
        'secondary_color',
        'sleep_color',
        'busy_color',
        'free_color',
        'highlight_color',
        'now_color',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_hash',
        'two_factor_secret',
        'two_factor_recovery_codes',
        // Never serialized — decryption only ever happens transiently, inside
        // the ICS-fetch job. See PLAN.md §0.2.
        'calendar_url_ciphertext',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'name' => 'encrypted',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'availability_settings' => 'array',
            'week_start' => 'integer',
        ];
    }

    /**
     * name and email are encrypted at rest (§0.2's server-runtime tier —
     * same Crypt/APP_KEY as calendar_url_ciphertext). Not client-vault E2EE:
     * login has to work before a passphrase is ever entered.
     *
     * email can't just use the 'encrypted' cast like name does, because
     * Crypt's ciphertext is randomized per call — a plain `where('email',
     * ...)` can never match it. email_hash is a deterministic HMAC (keyed
     * on APP_KEY) that stands in for the plaintext everywhere a lookup or
     * uniqueness check is needed; see whereEmail() below. Every
     * `where('email', ...)` in the app must use that scope instead.
     */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email_hash'] = self::hashEmail($value);
        $this->attributes['email'] = Crypt::encryptString($value);
    }

    public function getEmailAttribute(?string $value): ?string
    {
        return $value === null ? null : Crypt::decryptString($value);
    }

    public static function hashEmail(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), config('app.key'));
    }

    public function scopeWhereEmail(Builder $query, string $email): Builder
    {
        return $query->where('email_hash', self::hashEmail($email));
    }

    /**
     * The registration bootstrap exception (§4) checks this on every
     * `/register` and `/` request, and every page that renders the header
     * partial — `count()` forces a full aggregate, `doesntExist()` is just
     * `SELECT 1 ... LIMIT 1`, cheap regardless of table size.
     */
    public static function isFirstUser(): bool
    {
        return static::query()->doesntExist();
    }

    /**
     * Gravatar only ever sees an MD5 of the email (its own lookup key, not
     * something this app invented) — never the plaintext, and never sent to
     * the browser either; this URL is built server-side from the decrypted
     * email and handed to the client as an opaque image URL. `d=mp` falls
     * back to a generic silhouette instead of Gravatar's own placeholder ad.
     */
    public function gravatarUrl(int $size = 64): string
    {
        $hash = md5(mb_strtolower(trim($this->email)));

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=mp";
    }

    public function sleepExceptions(): HasMany
    {
        return $this->hasMany(SleepException::class);
    }

    public function shareLinks(): HasMany
    {
        return $this->hasMany(ShareLink::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    public function connectionSources(): HasMany
    {
        return $this->hasMany(ConnectionSource::class);
    }

    public function connectionSourceCategories(): HasMany
    {
        return $this->hasMany(ConnectionSourceCategory::class);
    }

    public function connectionAttributeDefinitions(): HasMany
    {
        return $this->hasMany(ConnectionAttributeDefinition::class);
    }

    public function connectionEdges(): HasMany
    {
        return $this->hasMany(ConnectionEdge::class);
    }

    public function invitesIssued(): HasMany
    {
        return $this->hasMany(Invite::class, 'inviter_user_id');
    }

    public function calendarDetections(): HasMany
    {
        return $this->hasMany(CalendarDetection::class);
    }
}
