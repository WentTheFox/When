<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property string $id
 * @property string|null $name
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $passphrase_salt
 * @property string|null $two_factor_secret
 * @property array<array-key, mixed>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $calendar_url_ciphertext
 * @property string $calendar_parsing_mode
 * @property string|null $timezone
 * @property string|null $dnd_event_pattern
 * @property string|null $nap_event_pattern
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $key_ring_ciphertext
 * @property string|null $highlight_clause_pattern
 * @property string|null $email_hash
 * @property string|null $activity_clause_pattern
 * @property string|null $tentative_pattern
 * @property int $week_start
 * @property string|null $accent_color_key
 * @property string|null $secondary_color_key
 * @property string|null $sleep_color_key
 * @property string|null $busy_color_key
 * @property string|null $free_color_key
 * @property string|null $highlight_color_key
 * @property string $name_hash
 * @property string $verifier_salt_version
 * @property string|null $open_end_pattern
 * @property string|null $open_start_pattern
 * @property string|null $highlight_split_pattern
 * @property string|null $free_icon_key
 * @property string|null $busy_icon_key
 * @property string|null $sleep_icon_key
 * @property string|null $highlight_icon_key
 * @property string|null $now_color_key
 * @property Carbon|null $deleted_at
 * @property string|null $dnd_event_pattern_preview
 * @property string|null $nap_event_pattern_preview
 * @property string|null $highlight_split_pattern_preview
 * @property string|null $highlight_clause_pattern_preview
 * @property string|null $activity_clause_pattern_preview
 * @property string|null $tentative_pattern_preview
 * @property string|null $open_end_pattern_preview
 * @property string|null $open_start_pattern_preview
 * @property string|null $public_event_pattern
 * @property string|null $public_event_pattern_preview
 * @property string|null $public_color_key
 * @property string|null $public_icon_key
 * @property string|null $highlight_identifier_definition_id
 * @property string|null $bypass_dnd_definition_id
 * @property string|null $notes_field_definition_id
 * @property string|null $vrcx_base_url
 * @property string|null $vrcx_api_key
 * @property Carbon|null $vrcx_synced_at
 * @property string|null $vrchat_user_id_definition_id
 * @property string|null $discord_user_id_definition_id
 * @property bool $vrcx_auto_create_friends
 * @property string|null $discord_access_token
 * @property string|null $discord_refresh_token
 * @property Carbon|null $discord_token_expires_at
 * @property string|null $discord_account_name
 * @property Carbon|null $discord_synced_at
 * @property string|null $vrcx_friends_source_id
 * @property-read Collection<int, ActivityLocalization> $activityLocalizations
 * @property-read int|null $activity_localizations_count
 * @property-read Collection<int, AvailabilityWindow> $availabilityWindows
 * @property-read int|null $availability_windows_count
 * @property-read Collection<int, CalendarDetection> $calendarDetections
 * @property-read int|null $calendar_detections_count
 * @property-read Collection<int, ConnectionAttributeDefinition> $connectionAttributeDefinitions
 * @property-read int|null $connection_attribute_definitions_count
 * @property-read Collection<int, ConnectionEdge> $connectionEdges
 * @property-read int|null $connection_edges_count
 * @property-read Collection<int, ConnectionSourceCategory> $connectionSourceCategories
 * @property-read int|null $connection_source_categories_count
 * @property-read Collection<int, ConnectionSource> $connectionSources
 * @property-read int|null $connection_sources_count
 * @property-read Collection<int, Connection> $connections
 * @property-read int|null $connections_count
 * @property-read array<string, string>|null $public_page_title
 * @property-read Collection<int, Invite> $invitesIssued
 * @property-read int|null $invites_issued_count
 * @property-read Collection<int, Translation> $localizedTexts
 * @property-read int|null $localized_texts_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, Passkey> $passkeys
 * @property-read int|null $passkeys_count
 * @property-read Collection<int, ShareLink> $shareLinks
 * @property-read int|null $share_links_count
 * @property-read Collection<int, SleepException> $sleepExceptions
 * @property-read int|null $sleep_exceptions_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static Builder<static>|User newModelQuery()
 * @method static Builder<static>|User newQuery()
 * @method static Builder<static>|User onlyTrashed()
 * @method static Builder<static>|User query()
 * @method static Builder<static>|User whereAccentColorKey($value)
 * @method static Builder<static>|User whereActivityClausePattern($value)
 * @method static Builder<static>|User whereActivityClausePatternPreview($value)
 * @method static Builder<static>|User whereBusyColorKey($value)
 * @method static Builder<static>|User whereBusyIconKey($value)
 * @method static Builder<static>|User whereBypassDndDefinitionId($value)
 * @method static Builder<static>|User whereCalendarParsingMode($value)
 * @method static Builder<static>|User whereCalendarUrlCiphertext($value)
 * @method static Builder<static>|User whereCreatedAt($value)
 * @method static Builder<static>|User whereDeletedAt($value)
 * @method static Builder<static>|User whereDndEventPattern($value)
 * @method static Builder<static>|User whereDndEventPatternPreview($value)
 * @method static Builder<static>|User whereEmail($value)
 * @method static Builder<static>|User whereEmailHash($value)
 * @method static Builder<static>|User whereEmailVerifiedAt($value)
 * @method static Builder<static>|User whereFreeColorKey($value)
 * @method static Builder<static>|User whereFreeIconKey($value)
 * @method static Builder<static>|User whereHighlightClausePattern($value)
 * @method static Builder<static>|User whereHighlightClausePatternPreview($value)
 * @method static Builder<static>|User whereHighlightColorKey($value)
 * @method static Builder<static>|User whereHighlightIconKey($value)
 * @method static Builder<static>|User whereHighlightIdentifierDefinitionId($value)
 * @method static Builder<static>|User whereHighlightSplitPattern($value)
 * @method static Builder<static>|User whereHighlightSplitPatternPreview($value)
 * @method static Builder<static>|User whereId($value)
 * @method static Builder<static>|User whereKeyRingCiphertext($value)
 * @method static Builder<static>|User whereName($value)
 * @method static Builder<static>|User whereNameHash($value)
 * @method static Builder<static>|User whereNapEventPattern($value)
 * @method static Builder<static>|User whereNapEventPatternPreview($value)
 * @method static Builder<static>|User whereNowColorKey($value)
 * @method static Builder<static>|User whereOpenEndPattern($value)
 * @method static Builder<static>|User whereOpenEndPatternPreview($value)
 * @method static Builder<static>|User whereOpenStartPattern($value)
 * @method static Builder<static>|User whereOpenStartPatternPreview($value)
 * @method static Builder<static>|User wherePassphraseSalt($value)
 * @method static Builder<static>|User wherePassword($value)
 * @method static Builder<static>|User wherePublicColorKey($value)
 * @method static Builder<static>|User wherePublicEventPattern($value)
 * @method static Builder<static>|User wherePublicEventPatternPreview($value)
 * @method static Builder<static>|User wherePublicIconKey($value)
 * @method static Builder<static>|User whereRememberToken($value)
 * @method static Builder<static>|User whereSecondaryColorKey($value)
 * @method static Builder<static>|User whereSleepColorKey($value)
 * @method static Builder<static>|User whereSleepIconKey($value)
 * @method static Builder<static>|User whereTentativePattern($value)
 * @method static Builder<static>|User whereTentativePatternPreview($value)
 * @method static Builder<static>|User whereTimezone($value)
 * @method static Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static Builder<static>|User whereTwoFactorRecoveryCodes($value)
 * @method static Builder<static>|User whereTwoFactorSecret($value)
 * @method static Builder<static>|User whereUpdatedAt($value)
 * @method static Builder<static>|User whereVerifierSaltVersion($value)
 * @method static Builder<static>|User whereWeekStart($value)
 * @method static Builder<static>|User withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|User withoutTrashed()
 * @method static Builder<static>|User whereVrchatUserIdDefinitionId($value)
 * @method static Builder<static>|User whereVrcxApiKey($value)
 * @method static Builder<static>|User whereVrcxBaseUrl($value)
 * @method static Builder<static>|User whereVrcxSyncedAt($value)
 * @method static Builder<static>|User whereVrcxAutoCreateFriends($value)
 * @method static Builder<static>|User whereVrcxFriendsBaselineAt($value)
 * @method static Builder<static>|User whereVrcxFriendsSourceId($value)
 * @method static Builder<static>|User whereDiscordUserIdDefinitionId($value)
 * @method static Builder<static>|User whereDiscordAccessToken($value)
 * @method static Builder<static>|User whereDiscordAccountName($value)
 * @method static Builder<static>|User whereDiscordRefreshToken($value)
 * @method static Builder<static>|User whereDiscordSyncedAt($value)
 * @method static Builder<static>|User whereDiscordTokenExpiresAt($value)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    /**
     * @use HasFactory<UserFactory>
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
        'highlight_identifier_definition_id',
        'bypass_dnd_definition_id',
        'notes_field_definition_id',
        'vrchat_user_id_definition_id',
        'discord_user_id_definition_id',
        'vrcx_base_url',
        'vrcx_api_key',
        'vrcx_auto_create_friends',
        'vrcx_friends_source_id',
        'dnd_event_pattern',
        'dnd_event_pattern_preview',
        'nap_event_pattern',
        'nap_event_pattern_preview',
        'public_event_pattern',
        'public_event_pattern_preview',
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
        'public_color_key',
        'free_color_key',
        'highlight_color_key',
        'free_icon_key',
        'busy_icon_key',
        'public_icon_key',
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
        // The owner's VRCX API key — same discipline: decrypted only inside the sync.
        'vrcx_api_key',
        // Discord OAuth2 tokens: decrypted only inside the Discord client.
        'discord_access_token',
        'discord_refresh_token',
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
            // §0.2 tier: this owner's VRCX server, APP_KEY-encrypted at rest.
            'vrcx_base_url' => 'encrypted',
            'vrcx_api_key' => 'encrypted',
            'vrcx_synced_at' => 'datetime',
            'vrcx_auto_create_friends' => 'boolean',
            // §0.2 tier: the owner's Discord OAuth2 tokens, APP_KEY-encrypted at rest.
            'discord_access_token' => 'encrypted',
            'discord_refresh_token' => 'encrypted',
            'discord_token_expires_at' => 'datetime',
            'discord_account_name' => 'encrypted',
            'discord_synced_at' => 'datetime',
            'dnd_event_pattern' => 'encrypted',
            'dnd_event_pattern_preview' => 'encrypted',
            'nap_event_pattern' => 'encrypted',
            'nap_event_pattern_preview' => 'encrypted',
            'public_event_pattern' => 'encrypted',
            'public_event_pattern_preview' => 'encrypted',
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

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
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

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
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
     * Opaque, stable per-user value the browser keeps in localStorage after
     * the owner has logged in (flashed once at login, never shared per-request) (see resources/js/ownerMarker.ts), so a
     * later *logged-out* view of their own share link can be recognised and
     * skipped by ShareLinkVisitController instead of inflating visit counts.
     * HMAC of the user id under APP_KEY: nothing stored, not guessable
     * without the key, and it grants nothing beyond "don't record my visits".
     */
    public function ownerMarker(): string
    {
        return hash_hmac('sha256', 'owner-marker:'.$this->id, config('app.key'));
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

    /** @return HasMany<SleepException, $this> */
    public function sleepExceptions(): HasMany
    {
        return $this->hasMany(SleepException::class);
    }

    /** @return HasMany<Passkey, $this> */
    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    public function hasTotp(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Whether login is gated by a second factor at all — a confirmed TOTP
     * secret, a registered passkey, or both. Either alone is enough to
     * require the challenge; at the challenge itself the user picks
     * whichever method they have.
     */
    public function hasTwoFactor(): bool
    {
        return $this->hasTotp() || $this->passkeys()->exists();
    }

    /** @return HasMany<AvailabilityWindow, $this> */
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

    /** @return HasMany<ActivityLocalization, $this> */
    public function activityLocalizations(): HasMany
    {
        // Eager-loads each role's own localizedTexts whenever this
        // relation itself is loaded — every real caller immediately
        // reads ->label (ActivityLocalization::getLabelAttribute()) right after,
        // so this avoids an N+1 query per role.
        return $this->hasMany(ActivityLocalization::class)->with('localizedTexts')->orderBy('sort_order');
    }

    /** @return HasMany<ShareLink, $this> */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(ShareLink::class);
    }

    /** @return HasMany<Connection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    /** @return HasMany<ConnectionSource, $this> */
    public function connectionSources(): HasMany
    {
        return $this->hasMany(ConnectionSource::class);
    }

    /** @return HasMany<ConnectionSourceCategory, $this> */
    public function connectionSourceCategories(): HasMany
    {
        return $this->hasMany(ConnectionSourceCategory::class);
    }

    /** @return HasMany<ConnectionAttributeDefinition, $this> */
    public function connectionAttributeDefinitions(): HasMany
    {
        return $this->hasMany(ConnectionAttributeDefinition::class);
    }

    /** @return HasMany<ConnectionEdge, $this> */
    public function connectionEdges(): HasMany
    {
        return $this->hasMany(ConnectionEdge::class);
    }

    /** @return HasMany<Invite, $this> */
    public function invitesIssued(): HasMany
    {
        return $this->hasMany(Invite::class, 'inviter_user_id');
    }

    /** @return HasMany<CalendarDetection, $this> */
    public function calendarDetections(): HasMany
    {
        return $this->hasMany(CalendarDetection::class);
    }
}
