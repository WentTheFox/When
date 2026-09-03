<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;

class User extends Authenticatable
{
    /**
     * @use HasFactory<UserFactory>
     * @use HasLocalizedFields<User>
     */
    use HasFactory, HasLocalizedFields, HasUuids, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'verifier_salt_version',
        'passphrase_salt',
        'key_ring_ciphertext',
        'timezone',
        'week_start',
        'dnd_event_pattern',
        'dnd_event_pattern_preview',
        'nap_event_pattern',
        'nap_event_pattern_preview',
        'work_event_pattern',
        'work_event_pattern_preview',
        'school_event_pattern',
        'school_event_pattern_preview',
        'calendar_parsing_mode',
        'highlight_clause_pattern',
        'highlight_clause_pattern_preview',
        'highlight_split_pattern',
        'highlight_split_pattern_preview',
        'activity_clause_pattern',
        'activity_clause_pattern_preview',
        'tentative_pattern',
        'tentative_pattern_preview',
        'open_end_pattern',
        'open_end_pattern_preview',
        'open_start_pattern',
        'open_start_pattern_preview',
        'accent_color_key',
        'secondary_color_key',
        'sleep_color_key',
        'busy_color_key',
        'work_color_key',
        'school_color_key',
        'free_color_key',
        'highlight_color_key',
        'free_icon_key',
        'busy_icon_key',
        'work_icon_key',
        'school_icon_key',
        'sleep_icon_key',
        'highlight_icon_key',
        'now_color_key',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'name_hash',
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
    /**
     * timezone and every *_pattern/*_pattern_preview column are §0.2
     * server-runtime Crypt/APP_KEY ciphertext (2026_09_03_120000), same
     * tier as calendar_url_ciphertext — but unlike name/email (below),
     * none of these is ever looked up by value anywhere in the app, so
     * the plain 'encrypted' cast is enough: no companion *_hash column,
     * no whereX() scope, transparent decrypt on every existing read site.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'week_start' => 'integer',
            'timezone' => 'encrypted',
            'dnd_event_pattern' => 'encrypted',
            'dnd_event_pattern_preview' => 'encrypted',
            'nap_event_pattern' => 'encrypted',
            'nap_event_pattern_preview' => 'encrypted',
            'work_event_pattern' => 'encrypted',
            'work_event_pattern_preview' => 'encrypted',
            'school_event_pattern' => 'encrypted',
            'school_event_pattern_preview' => 'encrypted',
            'highlight_clause_pattern' => 'encrypted',
            'highlight_clause_pattern_preview' => 'encrypted',
            'highlight_split_pattern' => 'encrypted',
            'highlight_split_pattern_preview' => 'encrypted',
            'activity_clause_pattern' => 'encrypted',
            'activity_clause_pattern_preview' => 'encrypted',
            'tentative_pattern' => 'encrypted',
            'tentative_pattern_preview' => 'encrypted',
            'open_end_pattern' => 'encrypted',
            'open_end_pattern_preview' => 'encrypted',
            'open_start_pattern' => 'encrypted',
            'open_start_pattern_preview' => 'encrypted',
        ];
    }

    /**
     * name and email are encrypted at rest (§0.2's server-runtime tier —
     * same Crypt/APP_KEY as calendar_url_ciphertext). Not client-vault E2EE:
     * login has to work before a passphrase is ever entered.
     * Neither can use Eloquent's plain 'encrypted' cast, because Crypt's
     * ciphertext is randomized per call — a plain `where('name', ...)` (or
     * `where('email', ...)`) can never match it. *_hash is a deterministic
     * HMAC (keyed on APP_KEY) that stands in for the plaintext everywhere a
     * lookup or uniqueness check is needed — name doubles as the login
     * identifier alongside optional email (see RegisteredUserController and
     * AuthenticatedSessionController), so it needs the same treatment email
     * already had. See whereName()/whereEmail() below; every
     * `where('name', ...)`/`where('email', ...)` in the app must use those
     * scopes instead.
     */
    public function setNameAttribute(string $value): void
    {
        $this->attributes['name_hash'] = self::hashName($value);
        $this->attributes['name'] = Crypt::encryptString($value);
    }

    public function getNameAttribute(?string $value): ?string
    {
        return $value === null ? null : Crypt::decryptString($value);
    }

    public static function hashName(string $name): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($name)), config('app.key'));
    }

    public function scopeWhereName(Builder $query, string $name): Builder
    {
        return $query->where('name_hash', self::hashName($name));
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email_hash'] = $value === null ? null : self::hashEmail($value);
        $this->attributes['email'] = $value === null ? null : Crypt::encryptString($value);
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
    public function gravatarUrl(int $size = 64): ?string
    {
        if ($this->email === null) {
            return null;
        }

        $hash = md5(mb_strtolower(trim($this->email)));

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=mp";
    }

    /** @return array<string, string>|null */
    public function getPublicPageTitleAttribute(): ?array
    {
        return $this->getLocalizedField('public_page_title');
    }

    public function sleepExceptions(): HasMany
    {
        return $this->hasMany(SleepException::class);
    }

    public function availabilityWindows(): HasMany
    {
        return $this->hasMany(AvailabilityWindow::class);
    }

    /**
     * Reassembles the availability_windows table back into the same
     * `array<int, array{wake: ?string, sleep: ?string}>` shape (keyed
     * 0=Sun..6=Sat) the old users.availability_settings JSON column used
     * to hold directly — every existing consumer of that shape
     * (AvailabilityService::compute() and its callers) reads it through
     * here now, unchanged.
     *
     * @return array<int, array{wake: ?string, sleep: ?string}>
     */
    public function weeklyAvailability(): array
    {
        return $this->availabilityWindows->mapWithKeys(fn (AvailabilityWindow $window) => [
            $window->weekday => [
                'wake' => $window->wake_time,
                'sleep' => $window->sleep_time,
            ],
        ])->all();
    }

    /**
     * Upserts one weekday's window at a time — a partial PATCH from a
     * subset of days (Settings.vue's own availability form always sends
     * all 7, but this mirrors the old JSON-merge write's per-key
     * semantics) only touches the weekdays actually present in $weekly.
     *
     * @param  array<int, array{wake: ?string, sleep: ?string}>  $weekly
     */
    public function setWeeklyAvailability(array $weekly): void
    {
        foreach ($weekly as $weekday => $config) {
            $this->availabilityWindows()->updateOrCreate(
                ['weekday' => (int) $weekday],
                ['wake_time' => $config['wake'] ?: null, 'sleep_time' => $config['sleep'] ?: null],
            );
        }
    }

    public function activityLocalizations(): HasMany
    {
        // Eager-loads each role's own localizedTexts whenever this
        // relation itself is loaded — every real caller immediately
        // reads ->label (ActivityLocalization::getLabelAttribute()) right after,
        // so this avoids an N+1 query per role.
        return $this->hasMany(ActivityLocalization::class)->with('localizedTexts')->orderBy('sort_order');
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
