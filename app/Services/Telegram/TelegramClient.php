<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the optional Telegram sidecar (docker/telegram-sidecar). The sidecar is stateless: each call carries
 * the owner's session string and the reply carries it back, possibly rotated. The session, phone, code and
 * password only ever go to the sidecar; no exception message here contains them. The sidecar URL is operator
 * configuration (TELEGRAM_SIDECAR_URL), never owner input.
 */
class TelegramClient
{
    /** Telegram support exists only when the operator set up a sidecar. */
    public static function isConfigured(): bool
    {
        return filled(config('services.telegram.sidecar_url')) && filled(config('services.telegram.sidecar_token'));
    }

    /**
     * @return array{session: string, phone_code_hash: string}
     *
     * @throws TelegramException
     */
    public function loginStart(string $phone): array
    {
        $r = $this->post('/v1/login/start', ['phone' => $phone]);

        return ['session' => (string) $r['session'], 'phone_code_hash' => (string) $r['phone_code_hash']];
    }

    /**
     * @return array{needs_password: bool, session: string, account_name: ?string, user_id: ?string}
     *
     * @throws TelegramException
     */
    public function loginCode(string $session, string $phone, string $phoneCodeHash, string $code): array
    {
        $r = $this->post('/v1/login/code', ['session' => $session, 'phone' => $phone, 'phone_code_hash' => $phoneCodeHash, 'code' => $code]);

        return [
            'needs_password' => (bool) ($r['needs_password'] ?? false),
            'session' => (string) $r['session'],
            'account_name' => isset($r['account_name']) ? (string) $r['account_name'] : null,
            'user_id' => isset($r['user_id']) ? (string) $r['user_id'] : null,
        ];
    }

    /**
     * @return array{session: string, account_name: ?string, user_id: ?string}
     *
     * @throws TelegramException
     */
    public function loginPassword(string $session, string $password): array
    {
        $r = $this->post('/v1/login/password', ['session' => $session, 'password' => $password]);

        return [
            'session' => (string) $r['session'],
            'account_name' => isset($r['account_name']) ? (string) $r['account_name'] : null,
            'user_id' => isset($r['user_id']) ? (string) $r['user_id'] : null,
        ];
    }

    /**
     * @return array{session: string, contacts: array<string, array{username: ?string, first_name: ?string, last_name: ?string, phone: ?string, mutual: bool, premium: bool, has_photo: bool}>}
     *
     * @throws TelegramException
     */
    public function contacts(string $session): array
    {
        $r = $this->post('/v1/contacts', ['session' => $session]);
        $contacts = [];

        foreach ((array) ($r['contacts'] ?? []) as $id => $c) {
            // Bots are not people to keep in touch with.
            if (! is_array($c) || ($c['bot'] ?? false)) {
                continue;
            }

            $contacts[(string) $id] = [
                'username' => $this->text($c['username'] ?? null),
                'first_name' => $this->text($c['first_name'] ?? null),
                'last_name' => $this->text($c['last_name'] ?? null),
                'phone' => $this->text($c['phone'] ?? null, 32),
                'mutual' => (bool) ($c['mutual'] ?? false),
                'premium' => (bool) ($c['premium'] ?? false),
                'has_photo' => (bool) ($c['has_photo'] ?? false),
            ];
        }

        return ['session' => (string) $r['session'], 'contacts' => $contacts];
    }

    /**
     * Profile-photo thumbnails (data URLs) for up to 40 contact ids.
     *
     * @param  list<string>  $ids
     * @return array{session: string, photos: array<string, string>}
     *
     * @throws TelegramException
     */
    public function photos(string $session, array $ids): array
    {
        $r = $this->post('/v1/photos', ['session' => $session, 'ids' => $ids]);
        $photos = [];

        foreach ((array) ($r['photos'] ?? []) as $id => $dataUrl) {
            // Only what we asked for, and only an inline image: this ends up in <img src> in the browser.
            if (in_array((string) $id, $ids, true) && is_string($dataUrl) && str_starts_with($dataUrl, 'data:image/jpeg;base64,') && strlen($dataUrl) < 100_000) {
                $photos[(string) $id] = $dataUrl;
            }
        }

        return ['session' => (string) $r['session'], 'photos' => $photos];
    }

    /** Logs the session out at Telegram, best effort: a session that is already dead is as good as logged out. */
    public function logout(string $session): void
    {
        try {
            $this->post('/v1/logout', ['session' => $session]);
        } catch (TelegramException) {
            // Nothing to do: the owner is disconnecting either way.
        }
    }

    private function text(mixed $value, int $max = 200): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws TelegramException
     */
    private function post(string $path, array $payload): array
    {
        try {
            $response = $this->http()->post($path, $payload);
        } catch (ConnectionException) {
            throw new TelegramException('The Telegram service could not be reached. Try again in a moment.');
        }

        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : throw new TelegramException('The Telegram service sent something unexpected.', $response->status());
        }

        $code = $response->json('code');
        $message = $response->json('message');
        $status = $response->status();

        if ($status === 401 && $code === 'auth_revoked') {
            throw new TelegramException('Telegram ended this session (for example from the Devices list in the Telegram app). Log in again.', $status, authRevoked: true);
        }

        if ($status === 429) {
            $wait = (int) $response->json('retry_after');

            throw new TelegramException('Telegram asked us to slow down. Try again in '.($wait > 0 ? $this->waitText($wait) : 'a while').'.', $status, retryAfter: $wait > 0 ? $wait : null);
        }

        // 422: the sidecar's own owner-safe wording (wrong code, bad phone number, ...).
        if ($status === 422 && is_string($message) && $message !== '') {
            throw new TelegramException($message, $status);
        }

        // 401 without auth_revoked is a bad sidecar token: an operator problem, not something the owner can fix.
        throw new TelegramException('The Telegram service is not available right now.', $status);
    }

    private function waitText(int $seconds): string
    {
        return $seconds >= 120 ? intdiv($seconds, 60).' minutes' : $seconds.' seconds';
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.telegram.sidecar_url'), '/'))
            ->withToken((string) config('services.telegram.sidecar_token'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(90);
    }
}
