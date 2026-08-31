<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UnlocksVault;
use App\Models\Connection;
use App\Models\ShareLink;
use App\Models\ShareLinkWord;
use App\Models\User;
use App\Services\Crypto\AesGcm;
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
 * Input JSON shape (one file, array of rows):
 *   [
 *     {
 *       "token": "the old calendar_highlight_tokens.token value",
 *       "owner_email": "matches an existing users.email in this app",
 *       "bypass_dnd": false,
 *       "highlight_words": ["Alice", "Bob"]
 *     },
 *     ...
 *   ]
 *
 * Idempotent: re-running with the same input skips tokens already
 * imported (legacy_token is unique), so a partial/interrupted run is safe
 * to retry.
 *
 * Also establishes the connection ↔ share-link hierarchy the export data
 * already implies but never wired up: a share link's own highlight_words
 * name the specific person(s) it was created for, so a word that matches
 * an existing connection's name exactly (case-sensitive, same convention
 * as HighlightMatcher's own clause matching) ties that connection to this
 * link (Connection::share_link_id) — same outcome as picking it manually
 * from the Share links page, just derived instead of asked for. Only ever
 * sets share_link_id on a connection that doesn't already have one, and
 * only for an exact single match — an ambiguous (0 or 2+ matching
 * connections) word is left alone rather than guessed at. This runs for
 * every row on every invocation, including already-imported ones, so
 * re-running after adding more connections (or after this command
 * shipped, against links imported before it did) still backfills them.
 *
 * Connection names are client-vault E2EE (§0.1) — matching against them
 * needs the owner's vault unlocked (see UnlocksVault), same interactive-
 * passphrase-prompt boundary as the Connections CLI commands, even though
 * the rest of this command's own data (words, bypass_dnd) is only ever
 * §0.2 server-runtime tier and needs no such prompt.
 */
class ImportLegacyShareLinks extends Command
{
    use UnlocksVault;

    protected $signature = 'wtf:import-legacy-share-links {input : Path to the source export JSON file}';

    protected $description = 'One-time Stage 5 import of calendar_highlight_tokens rows into share_links';

    public function handle(): int
    {
        $inputPath = $this->argument('input');

        if (! file_exists($inputPath)) {
            $this->error("Input file not found: {$inputPath}");

            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($inputPath), associative: true, flags: JSON_THROW_ON_ERROR);
        $imported = 0;
        $skipped = 0;
        $linked = 0;

        /** @var array<string, array{0: string, 1: array<string, string>}|false> Keyed by user id — one vault prompt per distinct owner in the file, not per row; false after a wrong passphrase so matching is just skipped for the rest of that owner's rows rather than re-prompting endlessly. */
        $unlockedByUser = [];

        foreach ($rows as $row) {
            $user = User::whereEmail($row['owner_email'])->first();

            if ($user === null) {
                $this->warn("Skipping token {$row['token']}: no user found for {$row['owner_email']}.");

                continue;
            }

            $shareLink = ShareLink::where('legacy_token', $row['token'])->first();

            if ($shareLink !== null) {
                $skipped++;
            } else {
                $shareLink = ShareLink::create([
                    'user_id' => $user->id,
                    'key_protection' => 'fragment',
                    'bypass_dnd' => $row['bypass_dnd'] ?? false,
                    'legacy_token' => $row['token'],
                ]);

                foreach ($row['highlight_words'] ?? [] as $word) {
                    ShareLinkWord::create([
                        'share_link_id' => $shareLink->id,
                        'word_ciphertext' => Crypt::encryptString($word),
                    ]);
                }

                $imported++;
            }

            if (! array_key_exists($user->id, $unlockedByUser)) {
                $unlockedByUser[$user->id] = $this->unlockVault($user) ?? false;
            }

            if ($unlockedByUser[$user->id] === false) {
                continue;
            }

            [, $ring] = $unlockedByUser[$user->id];

            if ($this->linkMatchingConnection($user, $ring, $shareLink, $row['highlight_words'] ?? [])) {
                $linked++;
            }
        }

        $this->info("Imported {$imported} share link(s), skipped {$skipped} already-imported token(s), linked {$linked} connection(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $ring
     * @param  string[]  $highlightWords
     */
    private function linkMatchingConnection(User $user, array $ring, ShareLink $shareLink, array $highlightWords): bool
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

        $matchingWords = collect($highlightWords)
            ->filter(fn (string $word) => ($connectionsByName->get($word)?->count() ?? 0) === 1);

        if ($matchingWords->count() !== 1) {
            return false;
        }

        $connectionsByName->get($matchingWords->first())->first()
            ->update(['share_link_id' => $shareLink->id]);

        return true;
    }
}
