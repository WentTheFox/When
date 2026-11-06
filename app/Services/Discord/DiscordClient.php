<?php

namespace App\Services\Discord;

use App\Support\DiscordUserId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Discord OAuth2 + the two read calls the friends import needs. Tokens and the
 * client secret are only ever passed to Discord; no message here contains them.
 */
class DiscordClient
{
    private const API = 'https://discord.com/api/v10';

    public const SCOPES = 'identify relationships.read';

    /** Discord relationship type for a mutual friend (others: blocked, pending requests, implicit). */
    private const FRIEND = 1;

    /** Discord relationship type for a user the owner has blocked. */
    private const BLOCKED = 2;

    public static function isConfigured(): bool
    {
        return filled(config('services.discord.client_id')) && filled(config('services.discord.client_secret'));
    }

    /** The per-user lookup needs the app's bot token (the owner's OAuth grant can only read their own data). */
    public static function canLookUp(): bool
    {
        return filled(config('services.discord.bot_token'));
    }

    public static function redirectUri(): string
    {
        return (string) (config('services.discord.redirect') ?: route('settings.discord.callback'));
    }

    public function authorizeUrl(string $state): string
    {
        return 'https://discord.com/oauth2/authorize?'.http_build_query([
            'client_id' => config('services.discord.client_id'),
            'response_type' => 'code',
            'redirect_uri' => self::redirectUri(),
            'scope' => self::SCOPES,
            'state' => $state,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: Carbon, scope: string}
     *
     * @throws DiscordException
     */
    public function exchangeCode(string $code): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri()]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: Carbon, scope: string}
     *
     * @throws DiscordException
     */
    public function refresh(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function revoke(string $token): void
    {
        try {
            $this->form()->post(self::API.'/oauth2/token/revoke', [
                'token' => $token,
                'client_id' => config('services.discord.client_id'),
                'client_secret' => config('services.discord.client_secret'),
            ]);
        } catch (ConnectionException) {
            // Disconnecting never depends on Discord being reachable; the token is forgotten locally either way.
        }
    }

    /**
     * @return array{id: string, name: string}
     *
     * @throws DiscordException
     */
    public function me(string $accessToken): array
    {
        $json = $this->get($accessToken, '/users/@me');
        $id = is_string($json['id'] ?? null) ? $json['id'] : '';

        return ['id' => $id, 'name' => $this->displayName($json) ?? $id];
    }

    /**
     * The owner's relationships from one call: mutual friends and blocked users, each by Discord user id.
     *
     * @return array{friends: array<string, array{username: ?string, globalName: ?string, nickname: ?string, avatarUrl: ?string}>, blocked: array<string, array{username: ?string, globalName: ?string, nickname: ?string, avatarUrl: ?string}>}
     *
     * @throws DiscordException
     */
    public function relationships(string $accessToken): array
    {
        $rows = $this->get($accessToken, '/users/@me/relationships');
        $result = ['friends' => [], 'blocked' => []];
        $text = fn (mixed $v) => is_string($v) && $v !== '' ? $v : null;

        foreach ($rows as $row) {
            $key = match (is_array($row) ? ($row['type'] ?? null) : null) {
                self::FRIEND => 'friends',
                self::BLOCKED => 'blocked',
                default => null,
            };

            if ($key === null || ! is_array($row['user'] ?? null)) {
                continue;
            }

            $user = $row['user'];
            $id = DiscordUserId::normalize(is_string($user['id'] ?? null) ? $user['id'] : null);

            if ($id === null) {
                continue;
            }

            $result[$key][$id] = [
                'username' => $text($user['username'] ?? null),
                'globalName' => $text($user['global_name'] ?? null),
                'nickname' => $text($row['nickname'] ?? null),
                'avatarUrl' => $this->avatarUrl($id, $text($user['avatar'] ?? null)),
            ];
        }

        return $result;
    }

    /**
     * Any Discord user by id, with the app's bot token: their public profile only.
     *
     * @return array{username: ?string, globalName: ?string, avatarUrl: ?string}
     *
     * @throws DiscordException
     */
    public function user(string $id): array
    {
        $id = DiscordUserId::normalize($id) ?? throw new DiscordException('That is not a Discord user ID.');

        try {
            $response = Http::withHeaders(['Authorization' => 'Bot '.config('services.discord.bot_token')])
                ->acceptJson()->timeout(20)->get(self::API.'/users/'.$id);
        } catch (ConnectionException) {
            throw new DiscordException('Discord could not be reached.');
        }

        // Not decode(): a 401 here means the bot token is wrong, not that an owner's grant ended.
        if ($response->status() === 404) {
            throw new DiscordException("Discord has no user with the ID {$id}.", 404);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new DiscordException('Discord rejected the bot token this server has.', $response->status());
        }

        if ($response->status() === 429) {
            throw new DiscordException('Discord is rate limiting requests. Try again in a minute.', 429);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new DiscordException('Discord answered with an error ('.$response->status().').', $response->status());
        }

        $user = $response->json();
        $text = fn (mixed $v) => is_string($v) && $v !== '' ? $v : null;

        return [
            'username' => $text($user['username'] ?? null),
            'globalName' => $text($user['global_name'] ?? null),
            'avatarUrl' => $this->avatarUrl($id, $text($user['avatar'] ?? null)),
        ];
    }

    private function avatarUrl(string $id, ?string $hash): ?string
    {
        return $hash === null ? null : "https://cdn.discordapp.com/avatars/{$id}/{$hash}.png?size=64";
    }

    /** @param array<string, mixed> $user */
    private function displayName(array $user): ?string
    {
        foreach (['global_name', 'username'] as $key) {
            if (is_string($user[$key] ?? null) && $user[$key] !== '') {
                return $user[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, refresh_token: string, expires_at: Carbon, scope: string}
     */
    private function token(array $params): array
    {
        try {
            $response = $this->form()->post(self::API.'/oauth2/token', $params + [
                'client_id' => config('services.discord.client_id'),
                'client_secret' => config('services.discord.client_secret'),
            ]);
        } catch (ConnectionException) {
            throw new DiscordException('Discord could not be reached.');
        }

        $json = $response->json();

        if (! $response->successful()) {
            // invalid_grant = the code/refresh token is spent or revoked: only reconnecting helps.
            $error = is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : null;

            throw new DiscordException(
                $error === 'invalid_grant' ? 'Discord no longer accepts this connection. Connect it again.' : 'Discord refused the request ('.$response->status().').',
                $response->status(),
                authRevoked: $error === 'invalid_grant',
            );
        }

        if (! is_array($json) || ! is_string($json['access_token'] ?? null) || ! is_string($json['refresh_token'] ?? null)) {
            throw new DiscordException('Discord sent an unexpected response.', $response->status());
        }

        $scopes = is_string($json['scope'] ?? null) ? $json['scope'] : '';

        // Without relationships.read the friends call can only fail: say so up front.
        if (! in_array('relationships.read', explode(' ', $scopes), true)) {
            throw new DiscordException('Discord did not grant access to your friends list (relationships.read).', $response->status());
        }

        return [
            'access_token' => $json['access_token'],
            'refresh_token' => $json['refresh_token'],
            'expires_at' => now()->addSeconds(max(60, (int) ($json['expires_in'] ?? 604800))),
            'scope' => $scopes,
        ];
    }

    /**
     * @return array<mixed>
     *
     * @throws DiscordException
     */
    private function get(string $accessToken, string $path): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->timeout(20)->get(self::API.$path);
        } catch (ConnectionException) {
            throw new DiscordException('Discord could not be reached.');
        }

        return $this->decode($response);
    }

    /**
     * @return array<mixed>
     *
     * @throws DiscordException
     */
    private function decode(Response $response): array
    {
        if ($response->status() === 401) {
            throw new DiscordException('Discord no longer accepts this connection. Connect it again.', 401, authRevoked: true);
        }

        if ($response->status() === 429) {
            throw new DiscordException('Discord is rate limiting requests. Try again in a minute.', 429);
        }

        if (! $response->successful()) {
            throw new DiscordException('Discord answered with an error ('.$response->status().').', $response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function form(): PendingRequest
    {
        return Http::asForm()->acceptJson()->timeout(20);
    }
}
