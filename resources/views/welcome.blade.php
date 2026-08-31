<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WhenTheFox</title>
    <meta name="description" content="End-to-end-encrypted availability sharing and a Connections CRM.">
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="container py-5" style="max-width: 42rem;">
        <h1 class="mb-2 text-center">WhenTheFox</h1>
        <p class="text-center text-muted mb-5">
            Share when you're free without handing anyone your calendar.
        </p>

        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h5">What this is</h2>
                <p class="mb-3">
                    WhenTheFox turns your calendar into a free/busy link you can hand to
                    anyone &mdash; friends, coworkers, whoever &mdash; without exposing what's
                    actually on it. Your calendar URL is encrypted at rest and only
                    decrypted transiently to compute availability; the computed result is
                    encrypted again before it's ever served to a viewer, and decrypted in
                    their browser, not on the server.
                </p>
                <p class="mb-0">
                    It also includes a small end-to-end-encrypted Connections CRM for
                    keeping track of people &mdash; that data never leaves your device
                    unencrypted, not even to us.
                </p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h5">Getting an account</h2>
                <p class="mb-0">
                    Registration is invite-only. If someone shared a WhenTheFox calendar
                    link with you, it comes with an invite to create your own. Otherwise,
                    you'll need an invite from an existing owner.
                </p>
            </div>
        </div>

        <div class="d-flex justify-content-center flex-wrap" style="gap: 0.75rem;">
            <a href="{{ route('login') }}" class="btn btn-secondary">Log in</a>
            <a href="https://github.com/WentTheFox/WhenTheFox" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                Source code
            </a>
        </div>
    </div>
</body>
</html>
