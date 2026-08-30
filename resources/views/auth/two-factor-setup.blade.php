<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Two-factor authentication — WhenTheFox</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <main>
        <h1>Two-factor authentication</h1>

        @if (session('recoveryCodes'))
            <p><strong>Save these recovery codes somewhere safe — each one works once, and this is the only time they're shown:</strong></p>
            <ul>
                @foreach (session('recoveryCodes') as $recoveryCode)
                    <li><code>{{ $recoveryCode }}</code></li>
                @endforeach
            </ul>
        @endif

        @if ($errors->any())
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <p>Scan this into your authenticator app, or enter the secret manually:</p>
        <p><code>{{ $secret }}</code></p>
        <p><a href="{{ $qrCodeUrl }}">{{ $qrCodeUrl }}</a></p>

        <form method="POST" action="{{ route('two-factor.confirm') }}">
            @csrf
            <label for="code">Enter the 6-digit code from your app to confirm</label>
            <input type="text" id="code" name="code" inputmode="numeric" required>
            <button type="submit">Confirm</button>
        </form>

        <form method="POST" action="{{ route('two-factor.disable') }}">
            @csrf
            @method('DELETE')
            <button type="submit">Disable two-factor authentication</button>
        </form>
    </main>
</body>
</html>
