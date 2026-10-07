<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Services\PasskeyService;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Owner-side management of passkeys (WebAuthn second factors) from the
 * Account page. Registering and removing both re-confirm the master password
 * (ConfirmsPassword): a hijacked session must not be able to quietly plant
 * its own passkey, or strip a legitimate one, without knowing it. The
 * options step only mints a one-time challenge, so it needs no confirmation.
 */
class PasskeyController extends Controller
{
    use ConfirmsPassword;

    public function __construct(
        private readonly PasskeyService $passkeys,
        private readonly TwoFactorAuthenticationService $twoFactor,
    ) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json($this->passkeys->registrationOptions($request, $request->user()));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->confirmPassword($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'client_data_json' => ['required', 'string'],
            'attestation_object' => ['required', 'string'],
            'transports' => ['nullable', 'array'],
            'transports.*' => ['string', 'max:32'],
        ]);

        $user = $request->user();

        try {
            $this->passkeys->register($request, $user, $data['name'], $data);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['passkey' => $e->getMessage()]);
        }

        // Recovery codes back up every second factor — a passkey-only
        // account needs them just as much as a TOTP one, and this may be
        // its first second factor.
        return back()
            ->with('status', 'Passkey added.')
            ->with('recoveryCodes', $this->twoFactor->ensureRecoveryCodes($user));
    }

    public function destroy(Request $request, Passkey $passkey): RedirectResponse
    {
        $this->confirmPassword($request);

        $user = $request->user();

        // Scoped to the caller's own passkeys — same 404-not-403 treatment
        // as every other per-user resource.
        abort_unless($passkey->user_id === $user->id, 404);

        // Hard delete: removing a credential is a security action, and a
        // soft-deleted row would keep blocking its credential_id from ever
        // being re-registered.
        $passkey->forceDelete();
        $this->twoFactor->pruneRecoveryCodes($user);

        return back()->with('status', 'Passkey removed.');
    }
}
