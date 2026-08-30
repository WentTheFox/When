<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Create your WhenTheFox account</title>
    @vite(['resources/css/app.css', 'resources/js/auth/register.js'])
</head>
<body>
    <main>
        <h1>Create your WhenTheFox account</h1>

        <p>Registration is invite-only. You need a valid invite code to sign up.</p>

        @if ($errors->any())
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form id="register-form" method="POST" action="{{ route('register') }}">
            @csrf

            <div>
                <label for="invite_code">Invite code</label>
                <input type="text" id="invite_code" name="invite_code" value="{{ old('invite_code', request('code')) }}" required>
            </div>

            <div>
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required>
            </div>

            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required>
            </div>

            <div>
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
                <p>This logs you in. It's separate from your passphrase below.</p>
            </div>

            <div>
                <label for="password_confirmation">Confirm password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required>
            </div>

            <hr>

            <div>
                <label for="passphrase">Passphrase</label>
                <input type="password" id="passphrase" required>
                <p>
                    This unlocks your encrypted data (calendar, Connections). It never leaves your
                    device — not even we can see it. If you lose it, your encrypted data is
                    unrecoverable.
                </p>
            </div>

            <div>
                <label for="passphrase_confirmation">Confirm passphrase</label>
                <input type="password" id="passphrase_confirmation" required>
            </div>

            <p id="passphrase-error" role="alert"></p>

            <input type="hidden" id="passphrase_salt" name="passphrase_salt">
            <input type="hidden" id="key_ring_ciphertext" name="key_ring_ciphertext">

            <button type="submit">Create account</button>
        </form>

        <p><a href="{{ route('login') }}">Already have an account? Log in</a></p>
    </main>
</body>
</html>
