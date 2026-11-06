<?php

namespace App\Services;

use App\Models\ShareLink;
use App\Services\Discord\DiscordSyncService;
use App\Services\Vrcx\VrcxSyncService;

/**
 * The Discord / VRChat account shown in a free page's header (§0.2 tier: names and pictures are
 * server-readable). Options come from the link's connection; only accounts with a name are
 * offered, as an ID nobody has looked up has nothing to show.
 */
class ShareLinkIdentity
{
    public const NONE = 'none';

    public function __construct(
        private readonly DiscordSyncService $discord,
        private readonly VrcxSyncService $vrcx,
    ) {}

    /** @return list<array{key: string, provider: 'discord'|'vrchat', name: string, username: ?string, picture_url: ?string}> */
    public function options(ShareLink $shareLink): array
    {
        $options = [];

        foreach ($this->discord->profilesFor($shareLink->user, $shareLink->connection_id) as $p) {
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

        foreach ($this->vrcx->profilesFor($shareLink->connection_id) as $p) {
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

        return $options;
    }

    /** @return array{provider: string, name: string, username: ?string, picture_url: ?string}|null */
    public function resolve(ShareLink $shareLink): ?array
    {
        $choice = $shareLink->display_identity;

        if ($choice === self::NONE) {
            return null;
        }

        $options = $this->options($shareLink);
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

    public function isValidChoice(ShareLink $shareLink, string $choice): bool
    {
        return $choice === self::NONE || collect($this->options($shareLink))->contains('key', $choice);
    }
}
