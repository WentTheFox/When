<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InviteService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly InviteService $invites) {}

    /**
     * The invite code itself is never manually entered — the owner-facing
     * form only ever shows *who* invited you (resolved server-side from the
     * code in the link), in a disabled field, with the real code carried in
     * a hidden one. There's no text box a visitor could type/guess a code
     * into. isFirstUser is a globally shared Inertia prop (see
     * HandleInertiaRequests), not passed here.
     */
    public function create(Request $request): Response
    {
        $isFirstUser = User::isFirstUser();
        $code = $request->query('code', '');
        $invite = $isFirstUser || $code === '' ? null : $this->invites->findValid($code);

        return Inertia::render('Auth/Register', [
            'code' => $code,
            'inviterName' => $invite?->inviter->name,
            'hasValidInvite' => $isFirstUser || $invite !== null,
        ]);
    }

    /**
     * Registration is closed by default (§4) — a valid, unexhausted,
     * unexpired invite code is the only way in, with one bootstrap
     * exception: when there isn't a single registered user yet, there's no
     * one who could have issued an invite, so the very first account skips
     * the requirement entirely.
     *
     * The server never sees the owner's passphrase — only the salt and the
     * already-client-encrypted key ring (§0.3), both opaque to it.
     */
    public function store(Request $request): RedirectResponse
    {
        $isFirstUser = User::isFirstUser();

        $data = $request->validate([
            'invite_code' => [$isFirstUser ? 'nullable' : 'required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            // email is encrypted at rest (§0.2) — a plain 'unique:users,email'
            // rule compares against ciphertext and would never catch a
            // duplicate, so uniqueness is checked via the email_hash-backed
            // whereEmail() scope instead. See User::hashEmail()'s doc comment.
            'email' => ['required', 'string', 'email', 'max:255', function (string $attribute, string $value, \Closure $fail) {
                if (User::whereEmail($value)->exists()) {
                    $fail('The email has already been taken.');
                }
            }],
            'password' => ['required', 'confirmed', Password::defaults()],
            'passphrase_salt' => ['required', 'string'],
            'key_ring_ciphertext' => ['required', 'string'],
        ]);

        $invite = null;

        if (! $isFirstUser) {
            $invite = $this->invites->findValid($data['invite_code']);

            if (! $invite) {
                return back()
                    ->withInput($request->except('password', 'password_confirmation'))
                    ->withErrors(['invite_code' => 'This invite link is invalid, expired, or has already been used.']);
            }
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'passphrase_salt' => $data['passphrase_salt'],
            'key_ring_ciphertext' => $data['key_ring_ciphertext'],
        ]);

        if ($invite !== null) {
            $this->invites->redeem($invite, $user);
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect()->intended(route('dashboard'));
    }
}
