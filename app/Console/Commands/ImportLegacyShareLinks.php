<?php

namespace App\Console\Commands;

use App\Models\ShareLink;
use App\Models\ShareLinkWord;
use App\Models\User;
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
 */
class ImportLegacyShareLinks extends Command
{
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

        foreach ($rows as $row) {
            if (ShareLink::where('legacy_token', $row['token'])->exists()) {
                $skipped++;

                continue;
            }

            $user = User::where('email', $row['owner_email'])->first();

            if ($user === null) {
                $this->warn("Skipping token {$row['token']}: no user found for {$row['owner_email']}.");

                continue;
            }

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

        $this->info("Imported {$imported} share link(s), skipped {$skipped} already-imported token(s).");

        return self::SUCCESS;
    }
}
