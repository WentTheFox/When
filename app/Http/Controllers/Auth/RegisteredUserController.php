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
            // Client-generated (crypto.randomUUID()), not server-assigned —
            // the login-verifier salt (resources/js/crypto/argon2.ts) is
            // derived from this id, so the browser has to know it before it
            // can compute the verifier it submits as 'password' below.
            // HasUuids respects a pre-set id rather than generating its own
            // (see Illuminate\Database\Eloquent\Concerns\HasUniqueIds::setUniqueIds()).
            'id' => ['required', 'uuid', 'unique:users,id'],
            'invite_code' => [$isFirstUser ? 'nullable' : 'required', 'string'],
            // name doubles as the login identifier (alongside optional
            // email) — no '@' is what gates login-identifier detection
            // (AuthenticatedSessionController), and it has to be unique for
            // the same reason a username would. Uniqueness is checked via
            // the name_hash-backed whereName() scope, not a plain
            // 'unique:users,name' rule, since name is encrypted at rest
            // (ciphertext comparison would never catch a duplicate) — see
            // User::hashName()'s doc comment.
            'name' => ['required', 'string', 'max:255', 'regex:/^[^@]+$/', function (string $attribute, string $value, \Closure $fail) {
                if (User::whereName($value)->exists()) {
                    $fail('That name is already taken.');
                }
            }],
            // Optional — only ever used to fetch a Gravatar avatar (see
            // User::gravatarUrl()) and identify the account for login.
            // Encrypted at rest (§0.2) like name is — a plain
            // 'unique:users,email' rule compares against ciphertext and
            // would never catch a duplicate, so uniqueness is checked via
            // the email_hash-backed whereEmail() scope instead. See
            // User::hashEmail()'s doc comment.
            'email' => ['nullable', 'string', 'email', 'max:255', function (string $attribute, string $value, \Closure $fail) {
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

        // 'id' is deliberately not mass-assigned via fillable (a primary
        // key generally shouldn't be) — set directly instead, before save()
        // so HasUuids' setUniqueIds() sees it already populated and leaves
        // it alone (Illuminate\Database\Eloquent\Concerns\HasUniqueIds).
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'verifier_salt_version' => 'id',
            'passphrase_salt' => $data['passphrase_salt'],
            'key_ring_ciphertext' => $data['key_ring_ciphertext'],
        ]);
        $user->id = $data['id'];
        $user->save();

        if ($invite !== null) {
            $this->invites->redeem($invite, $user);
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect()->intended(route('dashboard'));
    }
}
