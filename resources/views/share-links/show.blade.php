<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    @vite(['resources/css/app.css', 'resources/js/free/index.ts'])
</head>
<body>
    <div class="container py-4"
         id="free-page"
         data-token="{{ $token }}"
         data-key-protection="{{ $shareLink->key_protection }}"
         style="
            @if($colors['accent']) --wtf-accent: {{ $colors['accent'] }}; @endif
            @if($colors['free']) --wtf-color-free: {{ $colors['free'] }}; @endif
            @if($colors['busy']) --wtf-color-busy: {{ $colors['busy'] }}; @endif
            @if($colors['sleep']) --wtf-color-sleep: {{ $colors['sleep'] }}; @endif
            @if($colors['highlighted']) --wtf-color-highlighted: {{ $colors['highlighted'] }}; @endif
         ">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-theme-toggle" style="position: absolute; top: 1rem; right: 1rem;" aria-label="Toggle light/dark theme">
            <span id="theme-toggle-icon">&#9789;</span>
        </button>

        <h1 class="mb-1 text-center">{{ $pageTitle }}</h1>
        <p class="small text-center text-muted mt-n2 mb-1" id="timezone-note">
            Times shown in your local time
        </p>
        <p class="small text-center text-muted mt-n1 mb-3" id="timezone-offset-note" hidden></p>
        <p class="small text-center text-warning mb-3">
            This link is personalized to you. Please don't share it with others.
        </p>

        <div id="free-expired" class="text-center py-5" hidden>
            <h2 class="h4 mb-3">Link Expired</h2>
            <p class="mb-0 text-muted">This calendar link has expired or is no longer valid.</p>
        </div>

        <div id="free-main">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-3" id="free-controls" style="gap: 0.75rem;">
                <div class="d-flex align-items-center flex-wrap justify-content-center" style="gap: 0.5rem;">
                    <button class="btn btn-outline-secondary btn-sm" id="btn-prev" aria-label="Previous">
                        &laquo;
                    </button>
                    <span class="font-weight-bold text-center" id="nav-label" style="min-width: 12rem;"></span>
                    <button class="btn btn-outline-secondary btn-sm" id="btn-next" aria-label="Next">
                        &raquo;
                    </button>
                    <button class="btn btn-secondary btn-sm ml-2" id="btn-today">Today</button>
                </div>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-view-month">Month</button>
                    <button type="button" class="btn btn-secondary btn-sm" id="btn-view-week">Week</button>
                </div>
            </div>

            <div id="free-status" class="text-center text-muted py-5">
                <span id="free-status-text">Decrypting&hellip;</span>
            </div>

            <div id="free-calendar-root" class="wtf-desktop-only" hidden></div>
            <div id="free-agenda-root" class="wtf-mobile-only" hidden></div>
        </div>
    </div>

    <div id="passphrase-modal" class="position-fixed d-flex align-items-center justify-content-center" style="inset: 0; background: rgba(0,0,0,0.6); z-index: 1000;" hidden>
        <div class="card p-4" style="max-width: 24rem; width: 90%;">
            <h2 class="h5 mb-3">Enter passphrase</h2>
            <p class="small text-muted">This calendar requires a passphrase to view.</p>
            <form id="passphrase-form">
                <input type="password" class="form-control mb-3" id="passphrase-input" autocomplete="off" required>
                <p class="small text-danger mb-3" id="passphrase-error" hidden></p>
                <button type="submit" class="btn btn-secondary btn-block">Unlock</button>
            </form>
        </div>
    </div>

    <p class="text-center small text-muted mt-4">
        <a href="{{ route('register') }}?code={{ $inviteCode }}">
            Want your own WhenTheFox calendar? Create one &mdash; you're invited by {{ $ownerName }}.
        </a>
    </p>
</body>
</html>
