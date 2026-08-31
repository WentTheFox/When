<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}
