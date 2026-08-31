# When *(The Fox)*

Availability/free-busy sharing with an end-to-end-encrypted Connections CRM bolted on.
An owner shares a `/free/{token}` link; a viewer sees computed free/busy/highlighted/
sleep blocks without ever seeing the owner's raw calendar. Invite-gated registration —
there is no open signup.

## The two-tier security model — never blur these together

This is the single most important thing to get right in this codebase. Two different
kinds of data get **genuinely different** cryptographic treatment, and conflating them
is the most common way this kind of design quietly regresses into "we encrypt
everything with one server-held key."

- **§0.1 — Connections CRM (names, notes, custom attributes, sources): true E2EE.**
  The server never sees plaintext, ever, at rest, in transit, or in a running process.
  Every `_ciphertext` column is AES-256-GCM-encrypted client-side (WebCrypto) before
  it's ever sent. The server stores and returns opaque blobs — zero server-side
  computation on this data, by design.
- **§0.2 — Calendar URL + computed availability: ciphertext at rest only.** Protected
  against a source-code + database-copy attacker, **not** against a compromised
  production runtime. `calendar_url_ciphertext` is encrypted with `Crypt`/`APP_KEY`
  (server-runtime secret, never in the DB or source) and decrypted transiently, only
  inside the ICS-fetch job, never logged. This can't be full E2EE because fetching and
  parsing the owner's ICS feed has to happen on a schedule independent of the owner
  being logged in — most calendar providers send no CORS headers, so only a server can
  fetch them. The **computed result** is re-encrypted with the relevant share link's own
  key immediately after computing, before caching/serving.

The live, user-facing statement of this model is `/security`
(`resources/js/Pages/Security.vue`, via `SecurityPageController`) — keep it in sync with
reality as the code changes, it's not fixed copy.

Owner login uses **one master password**, split client-side via two independent
Argon2id derivations (Bitwarden-style): a `vaultKey` (never leaves the browser) and a
`loginVerifier` (submitted as an opaque `password` string, hashed/checked like any
ordinary password). See `resources/js/crypto/argon2.ts`.

## Architecture

- **Backend:** Laravel 12, Postgres, Redis + Horizon (queue worker for the ICS-fetch/
  recompute pipeline — see `app/Jobs/RecomputeShareLinkAvailability.php`).
- **Frontend:** Inertia + Vue 3 SFCs (`resources/js/Pages/**`), Bootstrap 5 via
  `bootstrap-vue-next` (components imported explicitly per file — global registration
  silently produces unstyled unrecognized-custom-element markup, a real bug hit once
  already). `resources/js/Layouts/{Public,Dashboard}Layout.vue` share one header
  component, `resources/js/Components/SiteHeader.vue` — don't build a second navbar,
  extend that one (nav links vary by auth state; visual design never does).
- **Availability pipeline:** ICS fetch (Guzzle) → feed classification
  (`App\Services\Calendar\FeedClassifier` — full_detail / free_busy_only / mixed) →
  parse/normalize → compute free/busy/sleep/highlighted slots
  (`App\Services\Calendar\AvailabilityService`) → encrypt for the target share link →
  cache. Triggered by request traffic hitting a stale/missing cache, not a cron sweep.
- **Color palette:** `app/Support/ColorPalette.php` is the **single source of truth**
  for every owner-selectable UI color (light/dark hex pairs, WCAG-AA verified). It's
  shared to the frontend via Inertia (`HandleInertiaRequests`), seeded once into
  `resources/js/free/color-palette.ts` at boot (`resources/js/app.ts`). The client
  **never** sends or stores a raw hex for these slots — only a palette key, validated
  server-side against `ColorPalette::KEYS`. Don't reintroduce a free-form color picker
  for these fields; a colorpicker version existed before and was deliberately replaced.

## Verifying changes

- `pnpm typecheck` — `tsconfig.json`'s `include` covers both `**/*.ts` and `**/*.vue`
  (it didn't always; a stale config meant `.vue` files went unchecked for a while). A
  handful of pre-existing, unrelated type errors exist (`SiteHeader.vue`,
  `DashboardLayout.vue`, `Account.vue`, `Settings.vue`, `ConnectionCard.vue`) from
  untyped Inertia page-prop shapes (`page.props.auth`, `.flash`, etc. resolve to `{}`).
  These are known and not regressions — don't chase them down as part of an unrelated
  change; do fix them if you're the one touching that exact prop's typing.
- `pnpm build` — Vite build; must succeed before any deploy.
- `php artisan test` — full suite, currently 177 tests. Run before committing anything
  backend-touching.
- `./vendor/bin/pint --dirty` — **only ever run this scoped to your own changed files**
  if there's any chance another session/process is concurrently editing the repo;
  running it unscoped across the whole working tree will reformat and stage changes in
  files you didn't touch, including someone else's in-progress edits.

## Deploy

Two independent git remotes:
- `git push origin main` → GitHub, code hosting only.
- `git push production main` → a **non-bare** repo on a remote SSH host
  with `receive.denyCurrentBranch=updateInstead`, so pushing directly updates the
  working tree. Triggers `setup/post-receive.sh`: fetch → (composer install if
  `composer.lock` changed) → `artisan down` → `artisan migrate --force` → (pnpm install
  if lockfile changed) → `pnpm build` (if `resources/` changed) → `artisan optimize` →
  restart `whenthefox-horizon.service` → `artisan up`. Live at `https://when.went.tf`.

Pushing to `production` deploys immediately and takes the app down briefly during
migrate/build — never push there without the user's explicit go-ahead for that specific
push, even if `origin` was already approved.

## Operator CLI

Artisan commands meant for whoever operates the deployment, on the machine hosting it —
**not** owner-facing (owners use the dashboard; there's no owner-facing API-token CLI).
Every command that touches Connections CRM data (`_ciphertext` columns) respects the
same E2EE boundary the browser does: it prompts for the owner's vault passphrase
interactively (never a CLI argument, never logged), derives the vault key locally with
the same Argon2id profile the browser's WebCrypto uses (`app/Services/Crypto/
Argon2id.php`, proven byte-for-byte compatible with `resources/js/crypto/argon2.ts`),
and encrypts/decrypts every record from this process's own memory before it ever
touches the database — the server process never holds a passphrase or vault key beyond
a single command's own lifetime.

- **`wtf:connections:list {email}`** — lists an owner's connections as `id` + decrypted
  name. Mainly useful to get an id for `wtf:connections:edit`, since names are
  encrypted and can't otherwise be searched from the CLI.
- **`wtf:connections:add {email}`** — interactively adds one connection: prompts for
  name (required), notes, a source name (created if it doesn't already exist), and a
  value for each of the owner's existing custom attribute definitions (blank to skip).
- **`wtf:connections:edit {email} {id}`** — interactively edits one connection (get its
  `id` from `wtf:connections:list`). A blank prompt keeps that field's current value; a
  blank attribute prompt removes that attribute's value if it had one.
- **`wtf:connections:import {email} {input}`** — bulk-imports from a `.json` or `.csv`
  file. Sources and custom attribute definitions are matched by name/label against what
  the owner already has, created automatically if missing (new definitions default to
  type `text`). JSON is an array of `{name, notes, source, attributes: {Label: value}}`
  objects (only `name` required); CSV is `name,notes,source,attr:<Label>,...` (any
  `attr:`-prefixed column becomes a custom attribute keyed by the rest of its header). A
  JSON file with a top-level `connections` key is auto-detected as a **source-app
  export** instead and handled differently — `sources[].category` and
  `attribute_definitions[]` (types `text`/`textarea`/`date`/`number`/`url`/`email`/
  `phone`/`radio`, anything else falls back to `text`; `radio`'s `options.choices`
  round-trips through its own `options_ciphertext`) are created up front; a connection
  can have any number of `source`-kind edges (no one-source-per-connection limit); a
  `connection`-kind edge creates one `ConnectionEdge` row (`one_way`) or two
  (`bi_directional`) and may reference a connection defined later in the same file;
  `highlight_token_label` just guarantees a bare connection exists under that name (not
  wired into any share link automatically); `archived` maps directly onto
  `connections.archived`.
- **`wtf:vault:import-labels {email} {input}`** — one-time/backfill helper: sets
  share-link labels via the owner's vault, from a JSON array of `{"token":
  "<share_links.id or legacy_token>", "label": "..."}`.
- **`wtf:import-legacy-share-links {input}`** — one-time migration: imports rows from
  the source app's old `calendar_highlight_tokens` export into `share_links`, keeping
  each row's original token as `legacy_token` (see `App\Services\Crypto\
  LegacyShareLinkKey` for how that token alone, with no separately stored key, derives
  the link's content key).

## Gotchas already paid for — don't rediscover these

- **CSS custom properties that reference other `var()`s inside their own value resolve
  against the scope where THEY are declared, not wherever they're later used.** A
  formula like `--x: color-mix(in srgb, var(--hue) 65%, var(--text) 35%)` declared once
  at `:root` will always resolve using `:root`'s own `--hue`/`--text`, even inside a
  descendant that locally overrides those — the descendant just inherits `--x`'s
  already-computed value. This bit the dual-theme settings preview (text color silently
  tracked the page's live theme instead of each preview panel's own fixed theme). Fix:
  redeclare the formula inside every scope that needs its own independently-resolved
  value; don't rely on inheritance for anything that embeds a `var()` inside its own
  declaration.
- A `border: Npx solid transparent` sitting under a gradient/image background can
  produce a visible anti-aliasing seam at the element's edge. Use `box-shadow` for a
  "no border" state instead of a transparent one.
- A CSS comment containing a literal `*/` substring inside prose (e.g. writing
  `--wtf-hue-*/` as a variable-name fragment) closes the comment early and breaks the
  parser. Watch for this specifically when a comment mentions a wildcard/glob next to a
  slash.
- Prefer `sticky-top` over `fixed-top` for a persistent header unless there's a real
  reason to take it out of flow — sticky needs no compensating `padding-top` hack on
  the content below it.
- For a single shared tooltip/popover across many repeated elements (e.g. a swatch
  grid), use one `<BTooltip>` instance that repositions to whichever element is
  hovered/focused, not a `v-b-tooltip` directive per element — the per-element form
  mounts a separate always-in-DOM floating instance per target, which at any nontrivial
  repeat count is both wasteful and prone to one bubble's pointer-events intercepting
  hover meant for a neighboring element. Pair with a global `.tooltip { pointer-events:
  none; }` so no tooltip can ever be the thing the mouse lands on.

## Deferred, not forgotten

Change-master-password flow: flagged as needed, deliberately not built yet. Full design
sketch (re-derive old vault key from re-entered current password to verify it, generate
a new `passphrase_salt`, re-encrypt the same key-ring contents under the new vault key,
compute a new login verifier, submit to a new endpoint) exists in this repo's git
history (the now-deleted `PLAN.md`, under its "Post-Stage-7 revisions" section) if this
gets picked up — check there or ask the user before re-deriving it from scratch.
