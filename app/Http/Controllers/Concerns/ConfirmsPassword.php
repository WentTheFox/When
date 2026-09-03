<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Re-proves the caller knows their master password before a sensitive
 * account-lifecycle action (data export, account deletion) proceeds. The
 * client derives the same login verifier used at sign-in
 * (deriveLoginVerifier(), resources/js/crypto/argon2.ts) and submits it as
 * `password` — this is exactly AuthenticatedSessionController::store()'s
 * Hash::check pattern, just against the already-authenticated user instead
 * of a looked-up one. No Fortify/password.confirm precedent exists in this
 * app, so this stays a plain trait rather than middleware.
 */
trait ConfirmsPassword
{
    protected function confirmPassword(Request $request): void
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'That password is incorrect.',
            ]);
        }
    }
}
