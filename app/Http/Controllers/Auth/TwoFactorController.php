<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasskeyService;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    private const SESSION_KEY = 'auth.two_factor.user_id';

    public function __construct(
        private readonly TwoFactorAuthenticationService $twoFactor,
        private readonly PasskeyService $passkeys,
    ) {}

    public function setup(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Already enabled — visiting this page directly must never
        // silently regenerate the secret (that would invalidate the
        // authenticator entry the user already has confirmed, without
        // disabling 2FA first). Management (including disabling) lives on
        // the Account page now.
        if ($user->two_factor_confirmed_at) {
            return redirect()->route('dashboard.account');
        }

        if (! $user->two_factor_secret) {
            $this->twoFactor->generateSecret($user);
            $user->refresh();
        }

        return Inertia::render('Auth/TwoFactorSetup', [
            'secret' => $user->two_factor_secret,
            'qrCodeUrl' => $this->twoFactor->getQrCodeUrl($user),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        try {
            $recoveryCodes = $this->twoFactor->confirm($request->user(), $data['code']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['code' => 'That code did not match.']);
        }

        // Back to the Account page's own two-factor section, not the
        // setup wizard — that's where the "Enabled" status and recovery
        // codes now belong (see Dashboard/Account.vue).
        return redirect()
            ->route('dashboard.account')
            ->with('recoveryCodes', $recoveryCodes ?: null);
    }

    public function disable(Request $request): RedirectResponse
    {
        $this->twoFactor->disable($request->user());

        return redirect()->route('dashboard.account');
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has(self::SESSION_KEY)) {
            return redirect()->route('login');
        }

        $user = User::find($request->session()->get(self::SESSION_KEY));

        if (! $user) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            // Which methods the login page offers — the user picks one, so
            // a passkey can be used instead of a code (and vice versa) when
            // both are configured.
            'hasTotp' => $user->hasTotp(),
            'hasPasskeys' => $user->passkeys()->exists(),
        ]);
    }

    public function passkeyOptions(Request $request): JsonResponse
    {
        $user = $this->pendingUser($request);

        $options = $user ? $this->passkeys->loginOptions($request, $user) : null;

        abort_if($options === null, 422, 'No passkey is registered for this account.');

        return response()->json($options);
    }

    public function verifyPasskey(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'id' => ['required', 'string'],
            'client_data_json' => ['required', 'string'],
            'authenticator_data' => ['required', 'string'],
            'signature' => ['required', 'string'],
        ]);

        if (! $this->passkeys->verifyLogin($request, $user, $data)) {
            throw ValidationException::withMessages(['passkey' => 'That passkey could not be verified.']);
        }

        return $this->completeLogin($request, $user);
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'code' => ['required_without:recovery_code', 'nullable', 'string'],
            'recovery_code' => ['required_without:code', 'nullable', 'string'],
        ]);

        $verified = $data['recovery_code'] ?? null
            ? $this->twoFactor->redeemRecoveryCode($user, $data['recovery_code'])
            : $this->twoFactor->verifyCode($user, $data['code']);

        if (! $verified) {
            throw ValidationException::withMessages(['code' => 'That code did not match.']);
        }

        return $this->completeLogin($request, $user);
    }

    /** The user who passed the password step and is awaiting their second factor, if any. */
    private function pendingUser(Request $request): ?User
    {
        $userId = $request->session()->get(self::SESSION_KEY);

        return $userId ? User::find($userId) : null;
    }

    private function completeLogin(Request $request, User $user): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->flash('ownerMarker', $user->ownerMarker());

        return redirect()->intended(route('dashboard'));
    }
}
