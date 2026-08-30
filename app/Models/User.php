<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
        'dnd_event_name',
        'nap_event_name',
        'availability_settings',
        'calendar_parsing_mode',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'availability_settings' => 'array',
        ];
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

    public function invitesIssued(): HasMany
    {
        return $this->hasMany(Invite::class, 'inviter_user_id');
    }
}
