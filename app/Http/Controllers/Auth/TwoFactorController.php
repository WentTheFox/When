<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    private const SESSION_KEY = 'auth.two_factor.user_id';

    public function __construct(private readonly TwoFactorAuthenticationService $twoFactor) {}

    public function setup(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->two_factor_secret || $user->two_factor_confirmed_at) {
            $this->twoFactor->generateSecret($user);
            $user->refresh();
        }

        return view('auth.two-factor-setup', [
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

        return redirect()
            ->route('two-factor.setup')
            ->with('recoveryCodes', $recoveryCodes);
    }

    public function disable(Request $request): RedirectResponse
    {
        $this->twoFactor->disable($request->user());

        return redirect()->route('dashboard');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has(self::SESSION_KEY)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $userId = $request->session()->get(self::SESSION_KEY);

        if (! $userId) {
            return redirect()->route('login');
        }

        $user = User::findOrFail($userId);

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

        $request->session()->forget(self::SESSION_KEY);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
