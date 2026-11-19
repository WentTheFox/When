# Zero-downtime deploys

New code goes live **before** its migrations run (the Argo CD sync does the migrate step). While `migrate` runs
the app is in *read-only mode*: pages and read APIs keep working, writes get `503` + `Retry-After`.
If migrate or the health check (`GET /status`, JSON) fails the flag stays on: fix forward, then run
`php artisan app:readonly off`.

## Read-only mode

- `app/Support/ReadOnlyMode.php` (file flag), `php artisan app:readonly on|off|status`.
- `BlockWritesInReadOnlyMode` (global middleware): non-safe methods get 503 + `Retry-After`
  (`ReadOnlyMode::RETRY_AFTER_SECONDS`) before any handler runs. Read-only POSTs that must keep working
  (`login/lookup`, `settings/calendar/preview`) are listed in `READING_POST_PATTERNS`.
- When adding a **GET route that writes to the database**, add it to `WRITING_GET_PATTERNS`; when adding a
  POST that only reads, add it to `READING_POST_PATTERNS`.
- The 503 renders the same maintenance page as `artisan down` (`bootstrap/app.php`), keeping the headers.
- Frontend: `resources/js/readOnlyRetry.ts` installs an axios interceptor (`bootstrap.js`). Inertia 2 sends
  its visits through the global axios instance, so page visits and the app's own calls are both covered. A
  503 that carries `Retry-After` is sent again after that wait (1-10 s plus up to 1 s jitter), up to 3 times,
  so a form just stays "processing" until the window is over. Safe for writes because the server refuses them
  before any handler runs. Covered by `resources/js/__tests__/readOnlyRetry.test.ts`.

## Rules for migrations

Each release must run against the **previous** release's schema, and the previous release against the new
one (Horizon workers drain on old code). So migrations are expand-only: add nullable/defaulted columns and
new tables; drop or rename in a *later* release. (The `introduced_by_ciphertext` drop shipped before this
existed; under this flow it would have been two releases.)

## Test

`php artisan test --filter=ReadOnlyModeTest` covers the middleware, the flag file,
the command and `/status`.
