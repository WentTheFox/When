<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    private const TWO_FACTOR_SESSION_KEY = 'auth.two_factor.user_id';

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Session login (§0.3) — independent of the vault key. TOTP 2FA is
     * orthogonal: it gates *this* login, not access to encrypted data.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // email is encrypted at rest (§0.2) — Auth::validate()'s default
        // provider does a plain `where('email', ...)`, which can never match
        // ciphertext, so the lookup and password check are done manually
        // here via the whereEmail() scope (see User::hashEmail()'s doc
        // comment) instead.
        $user = User::whereEmail($credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($user->two_factor_confirmed_at !== null) {
            $request->session()->put(self::TWO_FACTOR_SESSION_KEY, $user->id);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
