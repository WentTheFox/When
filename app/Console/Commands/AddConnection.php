<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UnlocksVault;
use App\Models\Connection;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionSource;
use App\Models\User;
use App\Services\Crypto\AesGcm;
use App\Services\Crypto\KeyRing;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Operator CLI (Stage 7's Connections CLI extension — see ImportShareLinkLabels's
 * doc comment for why this exists and how it respects the E2EE boundary):
 * single-record interactive add. Every _ciphertext field is encrypted here,
 * client-side from this process's point of view, with a fresh per-record
 * key added to the owner's ring — exactly what a browser would do.
 */
class AddConnection extends Command
{
    use UnlocksVault;

    protected $signature = 'wtf:connections:add {email : Owner email}';

    protected $description = 'Operator CLI: interactively add one connection via the owner\'s vault';

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

        $name = $this->ask('Name');

        if (empty($name)) {
            $this->error('Name is required.');

            return self::FAILURE;
        }

        $notes = $this->ask('Notes (optional)');
        $sourceName = $this->ask('Source (optional, created if it doesn\'t exist)');

        $sourceId = null;

        if (! empty($sourceName)) {
            [$sourceId, $ring] = $this->findOrCreateSource($user, $ring, $sourceName);
        }

        $connectionId = (string) Str::uuid();
        [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $connectionId);

        $connection = Connection::create([
            'id' => $connectionId,
            'user_id' => $user->id,
            'name_ciphertext' => AesGcm::encrypt($rawKey, $name),
            'notes_ciphertext' => $notes ? AesGcm::encrypt($rawKey, $notes) : null,
        ]);

        if ($sourceId !== null) {
            $connection->sources()->attach($sourceId);
        }

        $definitions = $user->connectionAttributeDefinitions()->get();

        foreach ($definitions as $definition) {
            $defKey = $ring[$definition->id] ?? null;
            $label = $defKey ? AesGcm::decrypt(base64_decode($defKey, true), $definition->label_ciphertext) : $definition->id;

            $value = $this->ask("Attribute \"{$label}\" ({$definition->type}, optional)");

            if (! empty($value)) {
                ConnectionAttributeValue::create([
                    'connection_id' => $connection->id,
                    'attribute_definition_id' => $definition->id,
                    'value_ciphertext' => AesGcm::encrypt($rawKey, $value),
                ]);
            }
        }

        $this->persistRing($user, $vaultKey, $ring);

        $this->info("Created connection {$connection->id}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $ring
     * @return array{0: string, 1: array<string, string>} [sourceId, updatedRing]
     */
    private function findOrCreateSource(User $user, array $ring, string $name): array
    {
        foreach ($user->connectionSources()->get() as $source) {
            $key = $ring[$source->id] ?? null;

            if ($key && AesGcm::decrypt(base64_decode($key, true), $source->name_ciphertext) === $name) {
                return [$source->id, $ring];
            }
        }

        $sourceId = (string) Str::uuid();
        [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $sourceId);

        ConnectionSource::create([
            'id' => $sourceId,
            'user_id' => $user->id,
            'name_ciphertext' => AesGcm::encrypt($rawKey, $name),
        ]);

        return [$sourceId, $ring];
    }
}
