<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use App\Services\Crypto\Argon2id;
use App\Services\Crypto\DecryptionFailedException;
use App\Services\Crypto\KeyRing;

/**
 * Shared operator-CLI vault-unlock boilerplate, used by every command that
 * needs to read or write client-vault E2EE data (§0.1/§0.3) from the
 * command line — Connections CRM records, share-link labels. Meant to be
 * run by whoever operates the deployment, on the machine hosting it, never
 * owner-facing.
 *
 * Respects the same E2EE boundary the browser does: the passphrase is
 * prompted interactively here (never accepted as a CLI argument, never
 * logged), the vault key is derived locally via Argon2id.php (libsodium,
 * proven to match resources/js/crypto/argon2.ts byte-for-byte), and every
 * record a command using this trait touches is encrypted/decrypted from
 * that process's own memory (this process is the "client" here, the same
 * as a browser would be) before it ever touches the database. The server
 * process itself never holds a passphrase or a vault key beyond a single
 * command's own lifetime, and the passphrase itself is dropped the moment
 * the key is derived from it (see the `finally` block below).
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
