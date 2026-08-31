<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
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
     * The login verifier's salt (resources/js/crypto/argon2.ts) is derived
     * from the account's immutable uuid `id` — never name or email, since
     * either of those may be edited later — so the browser needs that id
     * (and which salt scheme the row still uses; see the
     * verifier_salt_version migration) before it can compute anything.
     * Gated on '@' the same way the login form itself is: an identifier
     * containing one is looked up by email, otherwise by name.
     *
     * Always returns 200 with a plausible id, real account or not — a
     * 404-shaped response here would let anyone probe whether a given
     * email/name is registered. For an unknown identifier the id is a
     * deterministic HMAC of it rather than random, so repeated lookups of
     * the same nonexistent identifier stay indistinguishable from a real
     * one across requests.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
        ]);

        $identifier = $data['identifier'];
        $user = str_contains($identifier, '@')
            ? User::whereEmail($identifier)->first()
            : User::whereName($identifier)->first();

        return response()->json([
            'id' => $user->id ?? self::pseudoId($identifier),
            'saltVersion' => $user->verifier_salt_version ?? 'id',
        ]);
    }

    /**
     * Session login (§0.3) — independent of the vault key. TOTP 2FA is
     * orthogonal: it gates *this* login, not access to encrypted data.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // name and email are encrypted at rest (§0.2) — a plain
        // `where('email', ...)` (or `where('name', ...)`) can never match
        // ciphertext, so the lookup is done manually via the
        // whereEmail()/whereName() scopes instead (see User::hashName()'s
        // doc comment). Same '@' gate as lookup() above.
        $user = str_contains($credentials['identifier'], '@')
            ? User::whereEmail($credentials['identifier'])->first()
            : User::whereName($credentials['identifier'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => 'These credentials do not match our records.',
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

    /**
     * Transparent one-time migration off the legacy email-salted verifier
     * (see the verifier_salt_version migration): the server never sees the
     * master password, so it can't re-derive the new id-salted verifier
     * itself — only the client can, and only in the moment right after a
     * successful legacy login while the password is still in memory. This
     * just swaps the stored hash for the freshly-derived one and flips the
     * version flag; it doesn't re-authenticate anything, since the session
     * from store() above already did that.
     */
    public function migrateVerifier(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'verifier' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($user->verifier_salt_version === 'id') {
            return back();
        }

        $user->forceFill([
            'password' => Hash::make($data['verifier']),
            'verifier_salt_version' => 'id',
        ])->save();

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $redirectTo = $this->redirectPathAfterLogout($request);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($redirectTo);
    }

    /**
     * Land back on whatever page the user was just looking at (SiteHeader's
     * "Log out" button is global, reachable from any page) rather than
     * always bouncing to the homepage. Only trusts the Referer header when
     * it points at this same origin — anything else (a missing/foreign
     * referrer, or the logout route itself) falls back to '/'.
     */
    private function redirectPathAfterLogout(Request $request): string
    {
        $referer = $request->headers->get('referer');

        if ($referer === null || parse_url($referer, PHP_URL_HOST) !== $request->getHost()) {
            return '/';
        }

        $path = parse_url($referer, PHP_URL_PATH) ?? '/';

        if ($path === $request->getPathInfo()) {
            return '/';
        }

        $query = parse_url($referer, PHP_URL_QUERY);

        return $query ? "{$path}?{$query}" : $path;
    }

    /**
     * Every real account's id is a client-generated crypto.randomUUID() —
     * always a well-formed v4 (version nibble '4', variant nibble
     * 8/9/a/b). Raw HMAC bytes only land on that pattern by chance
     * (~1-in-64), so without forcing those nibbles here, a fake id would be
     * trivially distinguishable from a real one by format alone — deciding
     * the very thing this endpoint exists to hide.
     */
    private static function pseudoId(string $identifier): string
    {
        $bytes = hash_hmac('sha256', mb_strtolower(trim($identifier)), config('app.key'), true);
        $bytes = substr($bytes, 0, 16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
