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
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly InviteService $invites) {}

    public function create(Request $request): View
    {
        return view('auth.register', ['code' => $request->query('code', '')]);
    }

    /**
     * Registration is closed by default (§4) — a valid, unexhausted,
     * unexpired invite code is the only way in.
     *
     * The server never sees the owner's passphrase — only the salt and the
     * already-client-encrypted key ring (§0.3), both opaque to it.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'invite_code' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'passphrase_salt' => ['required', 'string'],
            'key_ring_ciphertext' => ['required', 'string'],
        ]);

        $invite = $this->invites->findValid($data['invite_code']);

        if (! $invite) {
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['invite_code' => 'This invite link is invalid, expired, or has already been used.']);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'passphrase_salt' => $data['passphrase_salt'],
            'key_ring_ciphertext' => $data['key_ring_ciphertext'],
        ]);

        $this->invites->redeem($invite, $user);

        event(new Registered($user));

        Auth::login($user);

        return redirect()->intended(route('dashboard'));
    }
}
