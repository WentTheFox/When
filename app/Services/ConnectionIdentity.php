<?php

namespace App\Services;

use App\Models\Connection;
use App\Services\Discord\DiscordSyncService;
use App\Services\Telegram\TelegramSyncService;
use App\Services\Vrcx\VrcxSyncService;

/**
 * The linked Discord / VRChat / Telegram account that stands for a connection: its picture and
 * name in the dashboard's lists and in the free page's header (§0.2 tier: names and pictures are
 * server-readable). Options come from the connection; only accounts with a name are offered, as an
 * ID nobody has looked up has nothing to show.
 *
 * The owner's pick lives on the connection (preferred_identity). Keep resources/js/dashboard/
 * connectionIdentity.ts in step: it makes the same choice in the browser, from the same profiles.
 */
class ConnectionIdentity
{
    public const NONE = 'none';

    public function __construct(
        private readonly DiscordSyncService $discord,
        private readonly VrcxSyncService $vrcx,
        private readonly TelegramSyncService $telegram,
    ) {}

    /** @return list<array{key: string, provider: 'discord'|'vrchat'|'telegram', name: string, username: ?string, picture_url: ?string}> */
    public function options(Connection $connection): array
    {
        $options = [];

        foreach ($this->discord->profilesFor($connection->user, $connection->id) as $p) {
            $name = $p['global_name'] ?: $p['username'];

            if ($p['known'] && $name) {
                $options[] = [
                    'key' => "discord:{$p['discord_user_id']}",
                    'provider' => 'discord',
                    'name' => $name,
                    'username' => $p['username'],
                    'picture_url' => $p['picture_url'],
                ];
            }
        }

        foreach ($this->vrcx->profilesFor($connection->id) as $p) {
            if (! empty($p['display_name'])) {
                $options[] = [
                    'key' => "vrchat:{$p['vrchat_user_id']}",
                    'provider' => 'vrchat',
                    'name' => $p['display_name'],
                    'username' => null,
                    'picture_url' => $p['picture_url'],
                ];
            }
        }

        foreach ($this->telegram->profilesFor($connection->user, $connection->id) as $p) {
            $name = $p['name'] ?: $p['username'];

            if ($p['known'] && $name) {
                $options[] = [
                    'key' => "telegram:{$p['telegram_user_id']}",
                    'provider' => 'telegram',
                    'name' => $name,
                    'username' => $p['username'],
                    'picture_url' => $p['picture_url'],
                ];
            }
        }

        return $options;
    }

    /** @return array{provider: string, name: string, username: ?string, picture_url: ?string}|null */
    public function resolve(Connection $connection): ?array
    {
        $choice = $connection->preferred_identity;

        if ($choice === self::NONE) {
            return null;
        }

        $options = $this->options($connection);
        $picked = $choice === null
            ? ($options[0] ?? null)
            : collect($options)->firstWhere('key', $choice);

        // A chosen account that has since lost its name (or link) shows nobody rather than someone else.
        if ($picked === null) {
            return null;
        }

        unset($picked['key']);

        return $picked;
    }

    public function isValidChoice(Connection $connection, string $choice): bool
    {
        return $choice === self::NONE || collect($this->options($connection))->contains('key', $choice);
    }
}
