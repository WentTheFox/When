<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Two-factor verification — WhenTheFox</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <main>
        <h1>Two-factor verification</h1>

        @if ($errors->any())
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('two-factor.challenge') }}">
            @csrf

            <div>
                <label for="code">Authenticator code</label>
                <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
            </div>

            <p>Or, if you've lost your device:</p>

            <div>
                <label for="recovery_code">Recovery code</label>
                <input type="text" id="recovery_code" name="recovery_code">
            </div>

            <button type="submit">Verify</button>
        </form>
    </main>
</body>
</html>
