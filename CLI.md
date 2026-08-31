# Operator CLI

These are Artisan commands meant to be run by whoever operates the deployment, on the
machine hosting it — they are **not** owner-facing. There is no owner-facing API-token
CLI; owners use the dashboard.

Every command below that touches Connections CRM data (`_ciphertext` columns) respects
the same end-to-end encryption boundary the browser does: it prompts for the owner's
vault passphrase interactively (never as a CLI argument, never logged), derives the vault
key locally with the same Argon2id profile the browser's WebCrypto uses
(`app/Services/Crypto/Argon2id.php`, proven byte-for-byte compatible with
`resources/js/crypto/argon2.ts`), and encrypts/decrypts every record client-side from
this process's point of view before it ever touches the database. The server process
itself never holds a passphrase or a vault key beyond a single command's own memory.

## `wtf:connections:list {email}`

Lists an owner's connections as `id` + decrypted name. Mainly useful to get an id for
`wtf:connections:edit`, since names are encrypted and can't otherwise be searched from
the CLI.

```
php artisan wtf:connections:list owner@example.com
```

## `wtf:connections:add {email}`

Interactively adds one connection: prompts for name (required), notes, a source name
(created if it doesn't already exist), and a value for each of the owner's existing
custom attribute definitions (leave blank to skip).

```
php artisan wtf:connections:add owner@example.com
```

## `wtf:connections:edit {email} {id}`

Interactively edits one connection (get its `id` from `wtf:connections:list`). Leaving a
prompt blank keeps that field's current value; leaving an attribute prompt blank removes
that attribute's value if it had one.

```
php artisan wtf:connections:edit owner@example.com 018f2e2b-....
```

## `wtf:connections:import {email} {input}`

Bulk-imports connections from a `.json` or `.csv` file. Sources and custom attribute
definitions are matched by name/label against what the owner already has, and created
automatically if missing (new attribute definitions default to type `text`).

**JSON shape** — an array of objects:

```json
[
  {
    "name": "Alice Example",
    "notes": "Met at a conference",
    "source": "Discord",
    "attributes": { "Birthday": "1990-01-01" }
  },
  { "name": "Bob Builder", "source": "LinkedIn" }
]
```

Only `name` is required.

**CSV shape** — a header row of `name,notes,source,attr:<Label>,attr:<AnotherLabel>,...`.
Any column prefixed `attr:` becomes a custom attribute keyed by the rest of its header:

```csv
name,notes,source,attr:Birthday,attr:Company
Alice Example,Met at a conference,Discord,1990-01-01,
Bob Builder,,LinkedIn,,Acme
```

```
php artisan wtf:connections:import owner@example.com ./connections.json
php artisan wtf:connections:import owner@example.com ./connections.csv
```

**the source app export shape** — a JSON object with a top-level `connections`
key is auto-detected and handled differently:

```json
{
  "sources": [{ "name": "Discord Server", "category": "group" }],
  "attribute_definitions": [
    { "label": "Standing", "type": "radio", "options": { "choices": ["friend", "acquaintance"] }, "sort_order": 0 },
    { "label": "Bio", "type": "textarea", "options": null, "sort_order": 0 }
  ],
  "connections": [
    {
      "name": "Alice Example",
      "archived": false,
      "created_at": "2026-01-15T10:00:00+00:00",
      "attribute_values": [{ "attribute_label": "Standing", "value": "friend" }],
      "highlight_token_label": "Alice Example",
      "edges": [
        { "type": "one_way", "target_kind": "source", "target_name": "Discord Server" },
        { "type": "bi_directional", "target_kind": "connection", "target_name": "Bob Builder" }
      ]
    }
  ]
}
```

- `sources[].category` and `attribute_definitions[]` are created up front, matched by
  decrypted name/label the same way the simple shape matches sources — attribute types
  `text`/`textarea`/`date`/`number`/`url`/`email`/`phone`/`radio` are all supported
  (`radio`'s `options.choices` round-trips through its own `options_ciphertext`,
  encrypted with that definition's key); anything else falls back to `text`.
- A connection can have any number of `source`-kind edges — WhenTheFox has no
  one-source-per-connection limit.
- A `connection`-kind edge creates one `ConnectionEdge` row (`one_way`) or two, one each
  direction (`bi_directional`). Edges may reference a connection defined later in the
  same file.
- `highlight_token_label` isn't wired into any share link automatically — it just
  guarantees a bare connection exists for that name, creating one if the file's own
  `connections` list doesn't already define it under that exact name.
- `archived` maps directly onto `connections.archived`.

## `wtf:vault:import-labels {email} {input}`

One-time/backfill helper: sets share-link labels via the owner's vault. Input is a JSON
array of `{ "token": "<share_links.id or legacy_token>", "label": "..." }`.

## `wtf:import-legacy-share-links {input}`

One-time Stage 5 migration: imports rows from the source app's old
`calendar_highlight_tokens` export into `share_links`, keeping each row's original token
as `legacy_token` (see `App\Services\Crypto\LegacyShareLinkKey` for how that token alone,
with no separately stored key, derives the link's content key).
