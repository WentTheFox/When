<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use App\Services\Crypto\Argon2id;
use App\Services\Crypto\DecryptionFailedException;
use App\Services\Crypto\KeyRing;

/**
 * Shared operator-CLI vault-unlock boilerplate (see ImportShareLinkLabels's
 * doc comment for the full reasoning — this trait just factors that same
 * pattern out for the Connections CLI commands). The passphrase is prompted
 * interactively, never accepted as an argument, and never held past
 * deriving the vault key.
 */
trait UnlocksVault
{
    /** @return array{0: string, 1: array<string, string>}|null [vaultKey, ring], or null on a wrong passphrase */
    protected function unlockVault(User $user): ?array
    {
        $passphrase = $this->secret('Enter the vault passphrase for '.$user->email);

        try {
            $vaultKey = Argon2id::derive($passphrase, $user->passphrase_salt);
            $ring = KeyRing::decrypt($vaultKey, $user->key_ring_ciphertext);

            return [$vaultKey, $ring];
        } catch (DecryptionFailedException) {
            $this->error('Wrong passphrase — could not unlock the vault.');

            return null;
        } finally {
            $passphrase = null; // Never held longer than deriving the key needs.
        }
    }

    /** @param  array<string, string>  $ring */
    protected function persistRing(User $user, string $vaultKey, array $ring): void
    {
        $user->update(['key_ring_ciphertext' => KeyRing::encrypt($vaultKey, $ring)]);
    }

    protected function findUserOrFail(string $email): ?User
    {
        $user = User::whereEmail($email)->first();

        if ($user === null) {
            $this->error("No user found for {$email}.");
        }

        return $user;
    }
}
