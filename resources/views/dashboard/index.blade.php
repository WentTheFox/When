<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dashboard — WhenTheFox</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <main>
        <p><em>Stub view — the full dashboard (settings, share links, Connections CRM) lands in Stage 7.</em></p>

        <h1>Welcome, {{ $user->name }}</h1>

        <nav>
            <a href="{{ route('invites.index') }}">Invites</a>
            <a href="{{ route('two-factor.setup') }}">Two-factor authentication</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Log out</button>
            </form>
        </nav>
    </main>
</body>
</html>
