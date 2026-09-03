<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key_ring_ciphertext' => ['required', 'string'],
        ]);

        $request->user()->update(['key_ring_ciphertext' => $data['key_ring_ciphertext']]);

        return response()->json(['status' => 'ok']);
    }
}
