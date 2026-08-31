<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UnlocksVault;
use App\Models\Connection;
use App\Models\ShareLink;
use App\Services\Crypto\AesGcm;
use App\Services\Crypto\KeyRing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * One-time fix-up for source-app-export connection imports that ran before
 * ImportConnections started wiring highlight_token_label up to a share link
 * (see CLAUDE.md's "wtf:connections:import" entry). Re-running
 * wtf:connections:import itself on the same file is NOT safe for this: its
 * source-app-export connection-creation loop has no dedupe-by-name check
 * (unlike sources/categories/attribute definitions, which do), so it would
 * create a second copy of every connection in the file. This command only
 * ever reads {@see ImportConnections}'s source-app-export `connections[].name` +
 * `connections[].highlight_token_label` fields, resolves each token label
 * against a connection that must already exist (from the original import —
 * it never creates one), and ties/creates a share link for it. Everything
 * else in the file (sources, attribute_definitions, edges, archived,
 * created_at) is ignored.
 *
 * Same E2EE boundary as every other command in this file: the passphrase is
 * prompted interactively, the vault key is derived locally, and every
 * ciphertext this writes is produced from this process's own memory.
 */
class BackfillConnectionShareLinks extends Command
{
    use UnlocksVault;

    protected $signature = 'wtf:connections:backfill-share-links {email : Owner email} {input : Path to the same source-app export .json file used with wtf:connections:import}';

    protected $description = 'Operator CLI: one-time fix-up to tie/create share links for connections already imported from a source-app export';

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

        $data = json_decode(file_get_contents($inputPath), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! array_key_exists('connections', $data)) {
            $this->error('Input file is not a source-app export (no top-level "connections" key).');

            return self::FAILURE;
        }

        [$vaultKey, $ring] = $this->unlockVault($user) ?? [null, null];

        if ($vaultKey === null) {
            return self::FAILURE;
        }

        $connectionIdsByName = [];
        foreach ($user->connections()->get(['id', 'name_ciphertext']) as $connection) {
            $key = $ring[$connection->id] ?? null;

            if ($key !== null) {
                $connectionIdsByName[AesGcm::decrypt(base64_decode($key, true), $connection->name_ciphertext)] = $connection->id;
            }
        }

        $linked = 0;
        $skippedAlreadyLinked = 0;
        $skippedUnresolved = 0;

        foreach ($data['connections'] ?? [] as $row) {
            $tokenName = $row['highlight_token_label'] ?? null;

            if ($tokenName === null) {
                continue;
            }

            $connectionId = $connectionIdsByName[$tokenName] ?? null;

            if ($connectionId === null) {
                $this->warn("Skipping \"{$tokenName}\": no matching connection found — was this file already imported with wtf:connections:import?");
                $skippedUnresolved++;

                continue;
            }

            $connection = Connection::findOrFail($connectionId);

            if ($connection->share_link_id !== null) {
                $skippedAlreadyLinked++;

                continue;
            }

            $shareLinkId = (string) Str::uuid();
            [$labelKey, $ring] = KeyRing::getOrCreateKey($ring, $shareLinkId);

            ShareLink::create([
                'id' => $shareLinkId,
                'user_id' => $user->id,
                'label_ciphertext' => AesGcm::encrypt($labelKey, $tokenName),
                'content_key_ciphertext' => Crypt::encryptString(base64_encode(random_bytes(32))),
                'key_protection' => 'fragment',
            ]);

            $connection->update(['share_link_id' => $shareLinkId]);

            $linked++;
        }

        $this->persistRing($user, $vaultKey, $ring);

        $this->info("Linked {$linked} connection(s) to a new share link, skipped {$skippedAlreadyLinked} already linked, {$skippedUnresolved} unresolved.");

        return self::SUCCESS;
    }
}
