<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UnlocksVault;
use App\Models\ConnectionAttributeValue;
use App\Services\Crypto\AesGcm;
use Illuminate\Console\Command;

/**
 * Operator CLI (Stage 7's Connections CLI extension — see UnlocksVault's
 * doc comment for why this exists and how it respects the E2EE boundary):
 * single-record interactive edit. Get the id from `wtf:connections:list`,
 * since names are client-vault E2EE and can't otherwise be searched from
 * the CLI. Leaving a prompt blank keeps the field's current value.
 */
class EditConnection extends Command
{
    use UnlocksVault;

    protected $signature = 'wtf:connections:edit {email : Owner email} {id : Connection id, from wtf:connections:list}';

    protected $description = 'Operator CLI: interactively edit one connection via the owner\'s vault';

    public function handle(): int
    {
        $user = $this->findUserOrFail($this->argument('email'));

        if ($user === null) {
            return self::FAILURE;
        }

        $connection = $user->connections()->where('id', $this->argument('id'))->first();

        if ($connection === null) {
            $this->error('No connection with that id for this owner.');

            return self::FAILURE;
        }

        [$vaultKey, $ring] = $this->unlockVault($user) ?? [null, null];

        if ($vaultKey === null) {
            return self::FAILURE;
        }

        $rawKeyBase64 = $ring[$connection->id] ?? null;

        if ($rawKeyBase64 === null) {
            $this->error('No key found in the ring for this connection — it may have been created by a different vault.');

            return self::FAILURE;
        }

        $rawKey = base64_decode($rawKeyBase64, true);

        $currentName = AesGcm::decrypt($rawKey, $connection->name_ciphertext);
        $currentNotes = $connection->notes_ciphertext ? AesGcm::decrypt($rawKey, $connection->notes_ciphertext) : '';

        $name = $this->ask("Name [{$currentName}]") ?: $currentName;
        $notes = $this->ask('Notes ['.($currentNotes !== '' ? $currentNotes : '(none)').']');
        $notes = $notes === null || $notes === '' ? $currentNotes : $notes;

        $connection->update([
            'name_ciphertext' => AesGcm::encrypt($rawKey, $name),
            'notes_ciphertext' => $notes !== '' ? AesGcm::encrypt($rawKey, $notes) : null,
        ]);

        foreach ($user->connectionAttributeDefinitions()->get() as $definition) {
            $defKey = $ring[$definition->id] ?? null;
            $label = $defKey ? AesGcm::decrypt(base64_decode($defKey, true), $definition->label_ciphertext) : $definition->id;

            $existing = ConnectionAttributeValue::where('connection_id', $connection->id)
                ->where('attribute_definition_id', $definition->id)
                ->first();
            $currentValue = $existing ? AesGcm::decrypt($rawKey, $existing->value_ciphertext) : '';

            $value = $this->ask("Attribute \"{$label}\" [".($currentValue !== '' ? $currentValue : '(none)').']');
            $value = $value === null || $value === '' ? $currentValue : $value;

            if ($value === '') {
                $existing?->delete();

                continue;
            }

            ConnectionAttributeValue::updateOrCreate(
                ['connection_id' => $connection->id, 'attribute_definition_id' => $definition->id],
                ['value_ciphertext' => AesGcm::encrypt($rawKey, $value)],
            );
        }

        $this->info("Updated connection {$connection->id}.");

        return self::SUCCESS;
    }
}
