# Zero-downtime deploys

New code goes live **before** its migrations run. Between the code swap and the end of `migrate`
the app is in *read-only mode*: pages and read APIs keep working, writes get `503` + `Retry-After`.
Modelled on SledgeHammerTime's setup; the toolkit side is git-deploy-toolkit's `docs/releases.md`.

## Flow (git-deploy-toolkit `RELEASES=1`, see `deploy.conf`)

1. `deploy_build` (old release still serving, cwd = the new release dir): composer, pnpm install,
   `pnpm build` (a failure aborts the deploy, the old release is untouched), `artisan optimize`, fixperms,
   then raise the flag `storage/framework/readonly`.
2. Toolkit swaps the `WORKTREE` symlink to the new release.
3. `deploy_restart`: reload php-fpm (opcache), `migrate --force`, restart `when-horizon.service`,
   `GET /status` health check (JSON: database + cache, 503 on failure), then remove the flag.

If migrate or the health check fails the flag stays on. Roll back with `git-deploy-rollback`
(`deploy_rollback` reloads php-fpm and restarts Horizon, never migrates) or fix forward, then run
`php artisan app:readonly off`.

Without release dirs (`$RELEASE` equal to `$WORKTREE`, in-place mode) `deploy.conf` keeps the old
`artisan down` / `up` flow, so the file works before and after the one-time conversion below.

## One-time server conversion (not done by deploying)

`WORKTREE` (`/var/www/WhenTheFox`) is a real directory today; converting it has a sub-second gap.

1. Make sure the toolkit on the server is current (`install.sh` re-run) and that `reload php*-fpm.service`
   is allowed in `/etc/git-deploy/services` (root-owned).
2. php-fpm global config: `process_control_timeout = 10s`. Without it a graceful reload resets requests in
   flight.
3. `git-deploy-releases-init <app> .env storage` (the `SHARED` list in `deploy.conf`; there is no
   untracked `setup/` file to carry).
4. Set `RELEASES=1` in the bare repo's `deploy.env`.
5. `when-horizon.service` has `WorkingDirectory=/var/www/WhenTheFox`, the stable symlink, so a restart
   after the swap picks up the new release.
6. Check the nginx `root` / php-fpm `SCRIPT_FILENAME` use the symlink path (`$realpath_root` makes opcache
   see each release as its own path, which is what we want).

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

`php artisan test --filter=ReadOnlyModeTest` covers the middleware, the flag file `deploy.conf` touches,
the command and `/status`.
