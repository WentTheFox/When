<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * §0.3's key ring, session-authenticated for the dashboard. The server
 * only ever holds and returns this ciphertext, never the vault key needed
 * to open it — the dashboard is responsible for deriving that locally
 * from the owner's passphrase and doing all encrypt/decrypt client-side.
 */
class VaultController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'passphrase_salt' => $user->passphrase_salt,
            'key_ring_ciphertext' => $user->key_ring_ciphertext,
        ]);
    }

    /**
     * Returns the session's vault wrap key, or 204 if there is none / it has
     * expired. With ?create=1 (a fresh, passphrase-proven unlock) a new key is
     * minted, replacing any previous one.
     *
     * The browser keeps the vault key AES-wrapped under this key in
     * IndexedDB, which has no TTL of its own — so the expiry that matters is
     * enforced here: once this key is gone the stored blob is undecryptable.
     * Lives only in the Laravel session (destroyed on logout), never logged.
     */
    public function wrapKey(Request $request): JsonResponse|Response
    {
        $session = $request->session();

        if ($request->boolean('create')) {
            $session->put('vault_wrap_key', base64_encode(random_bytes(32)));
            $session->put('vault_wrap_expires_at', $this->newExpiry($request));
        } elseif ($this->isExpired($request)) {
            $this->forget($request);

            return response()->noContent();
        }

        return $this->payload($request);
    }

    /** Idle ping: slides the expiry forward (per the user's current timeout setting). */
    public function touchWrapKey(Request $request): JsonResponse|Response
    {
        if ($this->isExpired($request)) {
            $this->forget($request);

            return response()->noContent();
        }

        $request->session()->put('vault_wrap_expires_at', $this->newExpiry($request));

        return $this->payload($request);
    }

    public function forgetWrapKey(Request $request): Response
    {
        $this->forget($request);

        return response()->noContent();
    }

    private function forget(Request $request): void
    {
        $request->session()->forget(['vault_wrap_key', 'vault_wrap_expires_at']);
    }

    /** No key at all counts as expired; a null expiry means "never". */
    private function isExpired(Request $request): bool
    {
        $session = $request->session();

        if (! $session->has('vault_wrap_key')) {
            return true;
        }

        $expiresAt = $session->get('vault_wrap_expires_at');

        return $expiresAt !== null && now()->getTimestampMs() >= (int) $expiresAt;
    }

    /** Epoch milliseconds, or null for "never". */
    private function newExpiry(Request $request): ?int
    {
        $minutes = $request->user()->vault_lock_timeout_minutes;

        return $minutes === null ? null : now()->addMinutes($minutes)->getTimestampMs();
    }

    private function payload(Request $request): JsonResponse
    {
        return response()->json([
            'key' => $request->session()->get('vault_wrap_key'),
            // Epoch ms (server clock), null = never. Display only on the client.
            'expires_at' => $request->session()->get('vault_wrap_expires_at'),
            'server_now' => now()->getTimestampMs(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key_ring_ciphertext' => ['required', 'string'],
        ]);

        $request->user()->update(['key_ring_ciphertext' => $data['key_ring_ciphertext']]);

        return response()->json(['status' => 'ok']);
    }
}
