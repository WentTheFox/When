<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard-side entry point for account identity (name/email) and
 * security (currently just two-factor status). Reachable by clicking your
 * own avatar/name in the top-right nav — TwoFactorController itself
 * already had a working setup/disable flow and route (/two-factor), the
 * gap this originally closed was that nothing in the UI ever linked to it.
 *
 * Changing name or email here never touches the login-verifier salt —
 * that's deliberately derived from the account's immutable uuid id, not
 * either of these (see the verifier_salt_version migration and
 * resources/js/crypto/argon2.ts) — so neither update below has any crypto
 * fallout, unlike registration/login.
 */
class AccountController extends Controller
{
    use ConfirmsPassword;

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Account', [
            'name' => $user->name,
            'email' => $user->email,
            'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
        ]);
    }

    public function updateName(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            // No '@' — this is what gates login-identifier detection
            // (AuthenticatedSessionController). Uniqueness is checked via
            // the name_hash-backed whereName() scope, not a plain unique
            // rule, since name is encrypted at rest (see
            // User::hashName()'s doc comment).
            'name' => ['required', 'string', 'max:255', 'regex:/^[^@]+$/', function (string $attribute, string $value, \Closure $fail) use ($user) {
                if (User::whereName($value)->whereKeyNot($user->id)->exists()) {
                    $fail('That name is already taken.');
                }
            }],
        ]);

        $user->name = $data['name'];
        $user->save();

        return back()->with('status', 'Name updated.');
    }

    public function updateEmail(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Nullable — email is optional (see RegisteredUserController); an
        // empty submission clears it back to null rather than failing
        // validation.
        $data = $request->validate([
            'email' => ['nullable', 'string', 'email', 'max:255', function (string $attribute, string $value, \Closure $fail) use ($user) {
                if (User::whereEmail($value)->whereKeyNot($user->id)->exists()) {
                    $fail('The email has already been taken.');
                }
            }],
        ]);

        $user->email = $data['email'] ?? null;
        $user->save();

        return back()->with('status', 'Email updated.');
    }

    /**
     * The client already proves possession of the *current* master password
     * client-side before this request is ever built: it derives the current
     * vault key from the re-entered current password and decrypts the
     * existing key_ring_ciphertext with it (AES-GCM is authenticated, so a
     * wrong password can't produce a ciphertext that re-encrypts back to
     * anything meaningful) — then re-encrypts the same key-ring contents
     * under a freshly-derived key from the new password + a new salt.
     *
     * That's not a substitute for a server-side check, though — it only
     * proves anything about a client that's honestly running this app's own
     * JS. A request forged directly at this endpoint (stolen session
     * cookie, XSS) would otherwise carry no proof at all that whoever sent
     * it knows the real current password, turning a hijacked session into a
     * full vault takeover. ConfirmsPassword's Hash::check closes that gap
     * the same way it does for delete/export, as defense in depth alongside
     * the client-side proof above. The persist step itself mirrors
     * AuthenticatedSessionController::migrateVerifier()'s forceFill
     * pattern.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $this->confirmPassword($request);

        $data = $request->validate([
            'passphrase_salt' => ['required', 'string'],
            'key_ring_ciphertext' => ['required', 'string'],
            'verifier' => ['required', 'string'],
        ]);

        $request->user()->forceFill([
            'passphrase_salt' => $data['passphrase_salt'],
            'key_ring_ciphertext' => $data['key_ring_ciphertext'],
            'password' => Hash::make($data['verifier']),
            'verifier_salt_version' => 'id',
        ])->save();

        return back()->with('status', 'Master password updated.');
    }
}
