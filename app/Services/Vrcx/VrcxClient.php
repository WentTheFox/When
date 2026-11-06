<?php

namespace App\Services\Vrcx;

use App\Models\User;
use App\Support\VrchatUserId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only client for a VRCX headless server's external API
 * (<base>/api/v1/openapi.json): `users:read` for cached name/picture and
 * `playtime:read` for shared-instance intervals. The API key is a bearer
 * token and is never put in an exception message.
 */
final class VrcxClient
{
    /** Most ids /api/v1/users accepts per call. */
    public const USERS_PER_REQUEST = 50;

    /** Per-user page size for POST /api/v1/time-together (its maximum). */
    private const PAGE_SIZE = 500;

    /** Safety valve: 90 days of one-on-one history never legitimately needs this many pages. */
    private const MAX_PAGES = 40;

    /** API versions (the OpenAPI document's info.version) this client is written against. */
    public const SUPPORTED_API_VERSIONS = ['1'];

    /**
     * Operations the sync calls (path => HTTP method); a server whose spec lacks
     * any of them can't be used. Users are looked up with POST (ids in the
     * body), which older VRCX servers don't have.
     */
    public const REQUIRED_OPERATIONS = ['/api/v1/users' => 'post', '/api/v1/time-together' => 'post'];

    public function __construct(private readonly string $baseUrl, private readonly string $apiKey, private readonly int $timeout = 15) {}

    /** @param  int  $timeout  Seconds per request; short for calls made while the owner waits on a save. */
    public static function forUser(User $user, int $timeout = 15): ?self
    {
        $baseUrl = $user->vrcx_base_url;
        $apiKey = $user->vrcx_api_key;

        return $baseUrl === null || $baseUrl === '' || $apiKey === null || $apiKey === ''
            ? null
            : new self(self::normalizeBaseUrl($baseUrl), $apiKey, $timeout);
    }

    /** Accepts the server root, its /api/v1 prefix, or the openapi.json URL pasted as-is. */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $url = preg_replace('#/openapi\.json$#', '', $url) ?? $url;
        $url = preg_replace('#/api/v1$#', '', $url) ?? $url;

        return rtrim($url, '/');
    }

    /**
     * Cached name and picture for the given ids (VRCX never queries VRChat for these).
     *
     * @param  list<string>  $ids
     * @return array<string, array{displayName: string, profilePictureUrl: string, avatarThumbnailUrl: string, discordId: ?string, note: ?string}>
     */
    public function users(array $ids): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($ids)), self::USERS_PER_REQUEST) as $chunk) {
            $users = $this->send('post', '/api/v1/users', ['ids' => $chunk])->json('users');

            foreach (is_array($users) ? $users : [] as $id => $user) {
                if (is_array($user)) {
                    $found[(string) $id] = [
                        'displayName' => (string) ($user['displayName'] ?? ''),
                        'profilePictureUrl' => (string) ($user['profilePictureUrl'] ?? ''),
                        'avatarThumbnailUrl' => (string) ($user['avatarThumbnailUrl'] ?? ''),
                        // Only when VRChat has ever shown one for this user.
                        'discordId' => self::cleanDiscordId($user['discordId'] ?? null),
                        // The owner's private VRChat note; only present for a key with the notes:read scope.
                        'note' => self::cleanNote($user['note'] ?? null),
                    ];
                }
            }
        }

        return $found;
    }

    /** A VRChat note as text, trimmed; empty or absent counts as "no note". */
    private static function cleanNote(mixed $value): ?string
    {
        $note = is_string($value) ? trim($value) : '';

        return $note === '' ? null : $note;
    }

    /** A Discord user id is a numeric snowflake; anything else (empty, null, junk) counts as "none". */
    private static function cleanDiscordId(mixed $value): ?string
    {
        $id = is_string($value) || is_int($value) ? trim((string) $value) : '';

        return preg_match('/^\d{15,25}$/', $id) === 1 ? $id : null;
    }

    /**
     * Every shared-instance interval with each of the given users inside VRCX's
     * look-back window, up to 50 users per request. `limit`/`offset` apply to each
     * user's list separately, so a following page only asks for the users whose
     * previous page came back full. Every requested id is present in the result.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{start: string, end: string, duration_ms: int, other_player_count: int}>>
     */
    public function timeTogether(array $ids, int $days = 90): array
    {
        $intervals = array_fill_keys($ids, []);

        foreach (array_chunk(array_values(array_unique($ids)), self::USERS_PER_REQUEST) as $chunk) {
            $pending = $chunk;

            for ($page = 0; $page < self::MAX_PAGES && $pending !== []; $page++) {
                $users = $this->send('post', '/api/v1/time-together', [
                    'ids' => $pending,
                    'days' => $days,
                    'limit' => self::PAGE_SIZE,
                    'offset' => $page * self::PAGE_SIZE,
                ])->json('users');

                $full = [];

                foreach ($pending as $id) {
                    $results = is_array($users[$id]['results'] ?? null) ? $users[$id]['results'] : [];

                    foreach ($results as $row) {
                        if (is_array($row) && isset($row['start'], $row['end'])) {
                            $intervals[$id][] = [
                                'start' => (string) $row['start'],
                                'end' => (string) $row['end'],
                                'duration_ms' => max(0, (int) ($row['durationMs'] ?? 0)),
                                'other_player_count' => max(0, (int) ($row['otherPlayerCount'] ?? 0)),
                            ];
                        }
                    }

                    if (count($results) >= self::PAGE_SIZE) {
                        $full[] = $id;
                    }
                }

                $pending = $full;
            }
        }

        return $intervals;
    }

    /**
     * The account's whole friend list from VRCX's cache (needs friends:read).
     * Entries that are not valid VRChat user ids are skipped.
     *
     * @return array<string, array{displayName: string, pictureUrl: string}> keyed by user id
     */
    public function friends(): array
    {
        $friends = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $results = $this->send('get', '/api/v1/friends', [
                'limit' => self::PAGE_SIZE,
                'offset' => $page * self::PAGE_SIZE,
            ], 'friends:read')->json('results');
            $results = is_array($results) ? $results : [];

            foreach ($results as $row) {
                $id = is_array($row) ? VrchatUserId::normalize((string) ($row['id'] ?? '')) : null;

                if ($id !== null) {
                    $friends[$id] = [
                        'displayName' => (string) ($row['displayName'] ?? ''),
                        'pictureUrl' => (string) (($row['profilePictureUrl'] ?? '') ?: ($row['avatarThumbnailUrl'] ?? '')),
                    ];
                }
            }

            if (count($results) < self::PAGE_SIZE) {
                break;
            }
        }

        return $friends;
    }

    /**
     * Confirms the URL is a VRCX headless server speaking an API version we
     * know: its (public) /api/v1/openapi.json must be an OpenAPI document
     * titled VRCX, with a supported info.version and the paths the sync uses.
     *
     * @param  array<string, string>  $also  Further operations (path => method) the server must offer, for optional features.
     *
     * @throws VrcxException with a message safe to show next to the URL field
     */
    public static function assertCompatible(string $baseUrl, array $also = []): void
    {
        try {
            $response = Http::acceptJson()->timeout(10)->connectTimeout(5)
                ->get(self::normalizeBaseUrl($baseUrl).'/api/v1/openapi.json');
        } catch (ConnectionException) {
            throw new VrcxException('Could not reach that address. Check the URL and that the VRCX server is running.');
        }

        if (self::blockedByFirewall($response)) {
            throw new VrcxException(self::FIREWALL_MESSAGE);
        }

        if ($response->failed()) {
            throw new VrcxException("There is no VRCX API at that address (/api/v1/openapi.json answered HTTP {$response->status()}). Is the external API enabled?");
        }

        $spec = $response->json();

        if (! is_array($spec) || ! is_string($spec['openapi'] ?? null) || ! is_array($spec['paths'] ?? null)) {
            throw new VrcxException('That address did not return an OpenAPI document at /api/v1/openapi.json, so it does not look like a VRCX server.');
        }

        $title = $spec['info']['title'] ?? null;
        if (! is_string($title) || ! str_contains(strtolower($title), 'vrcx')) {
            throw new VrcxException('That address serves an API, but not the VRCX headless server\'s.');
        }

        $version = (string) ($spec['info']['version'] ?? '');
        if (! in_array($version, self::SUPPORTED_API_VERSIONS, true)) {
            throw new VrcxException(sprintf(
                'That VRCX server speaks API version "%s"; this app supports: %s.',
                $version === '' ? 'unknown' : $version,
                implode(', ', self::SUPPORTED_API_VERSIONS),
            ));
        }

        $missing = [];
        foreach (self::REQUIRED_OPERATIONS + $also as $path => $method) {
            if (! is_array($spec['paths'][$path] ?? null) || ! array_key_exists($method, $spec['paths'][$path])) {
                $missing[] = strtoupper($method).' '.$path;
            }
        }

        if ($missing !== []) {
            throw new VrcxException('That VRCX server\'s API does not offer: '.implode(', ', $missing).'. It may need updating.');
        }
    }

    /** A cheap authenticated call for the "test connection" check. */
    public function ping(): void
    {
        $this->get('/api/v1/time-together', ['limit' => 1, 'days' => 1]);
    }

    /** @param  array<string, scalar>  $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->send('get', $path, $query);
    }

    /** @param  array<string, mixed>  $data  Query string for GET, JSON body for POST. */
    private function send(string $method, string $path, array $data = [], ?string $scope = null): Response
    {
        try {
            $response = $this->request()->{$method}($path, $data);
        } catch (ConnectionException) {
            throw new VrcxException('Could not reach the VRCX server. Check the URL and that it is running.');
        }

        return match (true) {
            self::blockedByFirewall($response) => throw new VrcxException(self::FIREWALL_MESSAGE, $response->status()),
            $response->status() === 401 => throw new VrcxException('The VRCX server rejected the API key (it may be wrong or expired).', 401),
            $response->status() === 403 => throw new VrcxException($scope !== null
                ? "The API key lacks the {$scope} scope."
                : 'The API key lacks a required scope (needs users:read and playtime:read).', 403),
            $response->failed() => throw $this->failure($method, $path, $response),
            default => $response,
        };
    }

    private const FIREWALL_MESSAGE = 'A firewall or proxy in front of that address (it looks like Cloudflare) refused this server\'s request; it did not come from VRCX itself. '
        .'Allow this server\'s IP for that hostname in the proxy\'s firewall or bot protection rules.';

    /**
     * A 403 / 503 that VRCX did not send: VRCX answers with {"ok": false, ...}, a
     * proxy in front of it (Cloudflare's bot protection or a WAF rule, typically
     * aimed at server IP ranges) answers with its own page or headers. Worth telling
     * apart, since neither the API key nor the URL is what is wrong then.
     */
    private static function blockedByFirewall(Response $response): bool
    {
        if (! in_array($response->status(), [403, 503], true) || $response->json('ok') === false) {
            return false;
        }

        return $response->header('cf-mitigated') !== ''
            || stripos($response->header('server'), 'cloudflare') !== false
            || stripos($response->body(), 'cloudflare') !== false;
    }

    /**
     * "The VRCX server answered POST /api/v1/users with HTTP 500: <its own error text>": which request failed and
     * what VRCX said, so a server-side error can be traced. VRCX's text is shown shortened, with the API key blanked
     * out should it ever be echoed. The same line (no key, no ids) goes to the log.
     */
    private function failure(string $method, string $path, Response $response): VrcxException
    {
        $error = $response->json('error');
        $detail = is_string($error) && trim($error) !== ''
            ? ': '.mb_substr(str_replace($this->apiKey, '***', trim($error)), 0, 200)
            : '';

        $message = sprintf('The VRCX server answered %s %s with HTTP %d%s.', strtoupper($method), $path, $response->status(), $detail);
        Log::warning($message);

        return new VrcxException($message, $response->status());
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(min(5, $this->timeout));
    }
}
