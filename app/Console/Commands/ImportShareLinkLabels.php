<?php

namespace App\Console\Commands;

use App\Models\ShareLink;
use App\Models\User;
use App\Services\Crypto\AesGcm;
use App\Services\Crypto\Argon2id;
use App\Services\Crypto\DecryptionFailedException;
use App\Services\Crypto\KeyRing;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Operator CLI (Stage 7's "CLI for command-line data entry/import" —
 * scoped down to the concrete need: backfilling share-link labels after
 * the Stage 5 token migration, since ImportLegacyShareLinks can't produce
 * client-vault ciphertext without the real passphrase). Meant to be run by
 * whoever operates the deployment, on the machine hosting it — not an
 * owner-facing tool.
 *
 * This still respects the E2EE boundary: the passphrase is prompted
 * interactively (never passed as a CLI argument, never logged), the vault
 * key is derived locally via Argon2id.php (libsodium, proven to match
 * resources/js/crypto/argon2.ts byte-for-byte), and every label is
 * encrypted client-side (from this process's point of view — it's the
 * "client" here, the same as a browser would be) before touching the
 * database. The server process itself never holds a passphrase or a vault
 * key beyond this command's own memory.
 *
 * Input JSON shape (one file, array of rows):
 *   [
 *     { "token": "a share_links.id or legacy_token value", "label": "For Mom" },
 *     ...
 *   ]
 */
class ImportShareLinkLabels extends Command
{
    protected $signature = 'wtf:vault:import-labels {email : Owner email} {input : Path to the labels JSON file}';

    protected $description = 'Operator CLI: backfill share-link labels via the owner\'s vault (requires their passphrase)';

    public function handle(): int
    {
        $user = User::whereEmail($this->argument('email'))->first();

        if ($user === null) {
            $this->error("No user found for {$this->argument('email')}.");

            return self::FAILURE;
        }

        $inputPath = $this->argument('input');

        if (! file_exists($inputPath)) {
            $this->error("Input file not found: {$inputPath}");

            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($inputPath), associative: true, flags: JSON_THROW_ON_ERROR);

        $passphrase = $this->secret('Enter the vault passphrase for '.$user->email);

        try {
            $vaultKey = Argon2id::derive($passphrase, $user->passphrase_salt);
            $ring = KeyRing::decrypt($vaultKey, $user->key_ring_ciphertext);
        } catch (DecryptionFailedException) {
            $this->error('Wrong passphrase — could not unlock the vault.');

            return self::FAILURE;
        } finally {
            $passphrase = null; // Never held longer than deriving the key needs.
        }

        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            // The uuid column type rejects a non-UUID string even in an
            // unmatched OR branch (Postgres validates literal types before
            // evaluating which branch wins), so only compare against `id`
            // when the token is actually UUID-shaped.
            $shareLink = ShareLink::where('user_id', $user->id)
                ->where(function ($q) use ($row) {
                    $q->where('legacy_token', $row['token']);

                    if (Str::isUuid($row['token'])) {
                        $q->orWhere('id', $row['token']);
                    }
                })
                ->first();

            if ($shareLink === null) {
                $this->warn("Skipping \"{$row['token']}\": no matching share link for this owner.");
                $skipped++;

                continue;
            }

            [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $shareLink->id);
            $shareLink->update(['label_ciphertext' => AesGcm::encrypt($rawKey, $row['label'])]);

            $updated++;
        }

        $user->update(['key_ring_ciphertext' => KeyRing::encrypt($vaultKey, $ring)]);

        $this->info("Updated {$updated} label(s), skipped {$skipped}.");

        return self::SUCCESS;
    }
}
