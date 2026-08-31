<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UnlocksVault;
use App\Models\Connection;
use App\Models\ShareLink;
use App\Models\ShareLinkWord;
use App\Models\User;
use App\Services\Crypto\AesGcm;
use App\Services\Crypto\KeyRing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

/**
 * One-time Stage 5 import (§0.5): every `calendar_highlight_tokens` row
 * from the source app gets a 1:1 mapping to a new `share_links` row — no
 * collapsing/renumbering, and the old token is kept forever as
 * `legacy_token` rather than being replaced with a fresh id (see
 * ShareLinkController's doc comment: a legacy token already has enough
 * entropy to be a safe public identifier on its own, so it just keeps
 * being one).
 *
 * No content key is generated or stored here — a migrated link's key is
 * derived deterministically from its token on demand (§0.5,
 * App\Services\Crypto\LegacyShareLinkKey), so there's nothing to generate
 * at import time.
 *
 * This command's job ends at creating those rows. WentTheNuxt (the sibling
 * repo, currently serving the old `/free/{token}` URLs) needs no data from
 * this app at all — its whole role in the migration is a blanket
 * same-path domain redirect, since the token in the URL never changes
 * between the two apps.
 *
 * IMPORTANT: per PLAN.md Stage 5, get explicit go-ahead before running
 * this against a real production export — this is the one step in the
 * whole plan that touches data from the other app.
 *
 * Input JSON shape: the source app's own `/dashboard/highlights/export`
 * download (see its DashboardController::exportHighlights()) — a plain
 * array, one owner per file (whoever was logged in when it was exported,
 * hence {email} being a command argument here rather than a per-row
 * field — the source app's export has no such field to read):
 *   [
 *     {
 *       "label": "Alice",
 *       "token": "the old calendar_highlight_tokens.token_base64 value",
 *       "created_at": "...",
 *       "archived": false,
 *       "bypass_dnd": false,
 *       "words": ["Alice"]
 *     },
 *     ...
 *   ]
 * `highlight_words` is also accepted as an alias for `words`, for any
 * hand-written input that predates seeing a real export.
 *
 * Idempotent: re-running with the same input skips tokens already
 * imported (legacy_token is unique), so a partial/interrupted run is safe
 * to retry.
 *
 * A row's `label` is imported as the share link's own label_ciphertext
 * (client-vault E2EE, §0.1/§0.3 — encrypted here from this process's own
 * memory, the vault having already been unlocked to match connections
 * below) — verbatim, not synthesized from anything else.
 *
 * Also establishes the connection ↔ share-link tie the export data already
 * implies but never wired up: a candidate name — the row's own `label`, or
 * any of its `words` — that matches an existing connection's name exactly
 * (case-sensitive, same convention as HighlightMatcher's own clause
 * matching) ties that connection to this link (Connection::share_link_id)
 * — same outcome as picking it manually from the Share links page, just
 * derived instead of asked for. Only ever sets share_link_id on a
 * connection that doesn't already have one, and only for a candidate name
 * with exactly one matching connection — an ambiguous (0 or 2+ matching
 * connections) candidate is left alone rather than guessed at. This runs
 * for every row on every invocation, including already-imported ones, so
 * re-running after adding more connections (or after this command shipped,
 * against links imported before it did) still backfills them.
 *
 * Connection names are client-vault E2EE (§0.1) — matching against them,
 * and encrypting a row's label, needs the owner's vault unlocked (see
 * UnlocksVault), same interactive-passphrase-prompt boundary as the
 * Connections CLI commands, even though the rest of this command's own
 * data (words, bypass_dnd) is only ever §0.2 server-runtime tier and needs
 * no such prompt.
 *
 * Hidden from `artisan list` — wtf:connections:reimport (which delegates to
 * this command) is the one meant for direct use.
 */
class ImportLegacyShareLinks extends Command
{
    use UnlocksVault;

    protected $signature = 'wtf:import-legacy-share-links {email : Owner email — the source app export has no per-row owner} {input : Path to the source export JSON file}';

    protected $description = 'One-time Stage 5 import of calendar_highlight_tokens rows into share_links';

    protected $hidden = true;

    public function handle(): int
    {
        $user = $this->findUserOrFail($this->argument('email'));

        if ($user === null) {
            return self::FAILURE;
        }

        $inputPath = $this->argument('input');

        if (! file_exists($inputPath)) {
            $this->error("Input file not found: {$inputPath}");

            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($inputPath), associative: true, flags: JSON_THROW_ON_ERROR);
        $imported = 0;
        $skipped = 0;
        $linked = 0;

        [$vaultKey, $ring] = $this->unlockVault($user) ?? [null, null];
        $vaultUnlocked = $vaultKey !== null;

        foreach ($rows as $row) {
            $words = $row['words'] ?? $row['highlight_words'] ?? [];

            $shareLink = ShareLink::where('legacy_token', $row['token'])->first();

            if ($shareLink !== null) {
                $skipped++;
            } else {
                $shareLink = ShareLink::create([
                    'user_id' => $user->id,
                    'archived' => $row['archived'] ?? false,
                    'bypass_dnd' => $row['bypass_dnd'] ?? false,
                    'legacy_token' => $row['token'],
                ]);

                foreach ($words as $word) {
                    ShareLinkWord::create([
                        'share_link_id' => $shareLink->id,
                        'word_ciphertext' => Crypt::encryptString($word),
                    ]);
                }

                if ($vaultUnlocked && ! empty($row['label'])) {
                    [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $shareLink->id);
                    $shareLink->update(['label_ciphertext' => AesGcm::encrypt($rawKey, $row['label'])]);
                }

                $imported++;
            }

            if (! $vaultUnlocked) {
                continue;
            }

            $candidates = array_values(array_unique(array_filter([$row['label'] ?? null, ...$words])));

            if ($this->linkMatchingConnection($user, $ring, $shareLink, $candidates)) {
                $linked++;
            }
        }

        if ($vaultUnlocked) {
            $this->persistRing($user, $vaultKey, $ring);
        }

        $this->info("Imported {$imported} share link(s), skipped {$skipped} already-imported token(s), linked {$linked} connection(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $ring
     * @param  string[]  $candidates  Names to try matching a connection against — the row's label plus its highlight words.
     */
    private function linkMatchingConnection(User $user, array $ring, ShareLink $shareLink, array $candidates): bool
    {
        if ($shareLink->connection()->exists()) {
            return false;
        }

        // groupBy, not keyBy — two connections sharing a name is exactly
        // the ambiguous case this should refuse to guess at, and keyBy
        // would silently let the second one clobber the first under the
        // same key, hiding that there were ever two.
        $connectionsByName = $user->connections()
            ->whereNull('share_link_id')
            ->get(['id', 'name_ciphertext'])
            ->filter(fn (Connection $c) => isset($ring[$c->id]))
            ->groupBy(fn (Connection $c) => AesGcm::decrypt(base64_decode($ring[$c->id], true), $c->name_ciphertext));

        $matchingCandidates = collect($candidates)
            ->filter(fn (string $name) => ($connectionsByName->get($name)?->count() ?? 0) === 1);

        if ($matchingCandidates->count() !== 1) {
            return false;
        }

        $connectionsByName->get($matchingCandidates->first())->first()
            ->update(['share_link_id' => $shareLink->id]);

        return true;
    }
}
