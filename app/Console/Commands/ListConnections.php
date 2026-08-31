<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UnlocksVault;
use App\Services\Crypto\AesGcm;
use Illuminate\Console\Command;

/**
 * Operator CLI (Stage 7's Connections CLI extension — see ImportShareLinkLabels's
 * doc comment for why this exists and how it respects the E2EE boundary).
 * Mainly useful to get a connection's id for `wtf:connections:edit`, since
 * names are client-vault E2EE and can't otherwise be searched from the CLI.
 */
class ListConnections extends Command
{
    use UnlocksVault;

    protected $signature = 'wtf:connections:list {email : Owner email}';

    protected $description = 'Operator CLI: list an owner\'s connections (id + decrypted name) via their vault';

    public function handle(): int
    {
        $user = $this->findUserOrFail($this->argument('email'));

        if ($user === null) {
            return self::FAILURE;
        }

        [$vaultKey, $ring] = $this->unlockVault($user) ?? [null, null];

        if ($vaultKey === null) {
            return self::FAILURE;
        }

        $connections = $user->connections()->get();

        if ($connections->isEmpty()) {
            $this->info('No connections.');

            return self::SUCCESS;
        }

        $rows = $connections->map(function ($connection) use ($ring) {
            $key = $ring[$connection->id] ?? null;
            $name = $key
                ? AesGcm::decrypt(base64_decode($key, true), $connection->name_ciphertext)
                : '(no key in ring)';

            return [$connection->id, $name];
        });

        $this->table(['ID', 'Name'], $rows);

        return self::SUCCESS;
    }
}
