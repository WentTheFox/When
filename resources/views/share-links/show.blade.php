<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Shared calendar — WhenTheFox</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <main>
        <p><em>Stub view — the full scrambled→decrypted public viewer lands in Stage 6.</em></p>

        <h1>{{ $shareLink->user->name }}'s availability</h1>

        <p>This is where the free/busy calendar will render once Stage 6 is built.</p>

        <p>
            <a href="{{ route('register') }}?code={{ $inviteCode }}">
                Want your own WhenTheFox calendar? Create one — you're invited by {{ $shareLink->user->name }}.
            </a>
        </p>
    </main>
</body>
</html>
