<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Crypto\AesGcm;
use App\Services\Crypto\Argon2id;
use App\Services\Crypto\KeyRing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * wtf:connections:reimport — the one-shot wipe-and-rebuild command, so a
 * corrected or re-exported (connections.json, highlights.json) pair can
 * always be re-run from a clean slate instead of piling duplicates on top
 * of a previous run (wtf:connections:import has no dedupe-by-name check).
 */
class ReimportSourceAppExportTest extends TestCase
{
    use RefreshDatabase;

    private const PASSPHRASE = 'correct horse battery staple';

    public function test_wipes_existing_data_then_rebuilds_from_both_files(): void
    {
        $user = $this->userWithVault();
        $vaultKey = $this->vaultKey($user);
        $ring = KeyRing::decrypt($vaultKey, $user->key_ring_ciphertext);

        // Pre-existing junk that should be gone afterward.
        [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $staleConnectionId = Str::uuid()->toString());
        $user->update(['key_ring_ciphertext' => KeyRing::encrypt($vaultKey, $ring)]);
        Connection::create([
            'id' => $staleConnectionId,
            'user_id' => $user->id,
            'name_ciphertext' => AesGcm::encrypt($rawKey, 'StaleDuplicate'),
        ]);
        ShareLink::factory()->for($user)->create(['legacy_token' => 'stale-token']);

        $connectionsPath = tempnam(sys_get_temp_dir(), 'wtf-reimport-connections').'.json';
        file_put_contents($connectionsPath, json_encode([
            'sources' => [],
            'attribute_definitions' => [],
            'connections' => [
                ['name' => 'Alice', 'highlight_token_label' => 'Alice', 'edges' => [], 'attribute_values' => []],
            ],
        ]));

        $highlightsPath = tempnam(sys_get_temp_dir(), 'wtf-reimport-highlights').'.json';
        file_put_contents($highlightsPath, json_encode([
            ['token' => 'alice-token', 'label' => 'Alice', 'archived' => false, 'bypass_dnd' => false, 'words' => ['Alice']],
        ]));

        $this->artisan('wtf:connections:reimport', [
            'email' => $user->email,
            'connections' => $connectionsPath,
            'highlights' => $highlightsPath,
        ])
            ->expectsConfirmation(
                "This PERMANENTLY DELETES ALL of {$user->email}'s connections, sources, categories, ".
                'attribute definitions, edges, and share links, then rebuilds them from the two files given. '.
                'This cannot be undone. Continue?',
                'yes',
            )
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->assertExitCode(0);

        // Stale data is gone.
        $this->assertNull(Connection::find($staleConnectionId));
        $this->assertNull(ShareLink::where('legacy_token', 'stale-token')->first());

        // Fresh data from both files exists, correctly tied together.
        $this->assertSame(1, Connection::count());
        $this->assertSame(1, ShareLink::count());

        $connection = Connection::firstOrFail();
        $shareLink = ShareLink::where('legacy_token', 'alice-token')->firstOrFail();
        $this->assertSame($shareLink->id, $connection->share_link_id);

        $ring = KeyRing::decrypt($vaultKey, $user->refresh()->key_ring_ciphertext);
        $this->assertSame('Alice', AesGcm::decrypt(base64_decode($ring[$connection->id], true), $connection->name_ciphertext));
        $this->assertSame('Alice', AesGcm::decrypt(base64_decode($ring[$shareLink->id], true), $shareLink->label_ciphertext));
        $this->assertSame(['Alice'], $shareLink->words->map(fn ($w) => Crypt::decryptString($w->word_ciphertext))->all());
    }

    public function test_aborts_without_changing_anything_when_not_confirmed(): void
    {
        $user = $this->userWithVault();
        $connection = $this->connectionNamed($user, 'Alice');

        $connectionsPath = tempnam(sys_get_temp_dir(), 'wtf-reimport-connections').'.json';
        file_put_contents($connectionsPath, json_encode(['sources' => [], 'attribute_definitions' => [], 'connections' => []]));
        $highlightsPath = tempnam(sys_get_temp_dir(), 'wtf-reimport-highlights').'.json';
        file_put_contents($highlightsPath, json_encode([]));

        $this->artisan('wtf:connections:reimport', [
            'email' => $user->email,
            'connections' => $connectionsPath,
            'highlights' => $highlightsPath,
        ])
            ->expectsConfirmation(
                "This PERMANENTLY DELETES ALL of {$user->email}'s connections, sources, categories, ".
                'attribute definitions, edges, and share links, then rebuilds them from the two files given. '.
                'This cannot be undone. Continue?',
                'no',
            )
            ->assertExitCode(1);

        $this->assertNotNull($connection->fresh());
    }

    public function test_force_skips_the_confirmation_prompt(): void
    {
        $user = $this->userWithVault();

        $connectionsPath = tempnam(sys_get_temp_dir(), 'wtf-reimport-connections').'.json';
        file_put_contents($connectionsPath, json_encode(['sources' => [], 'attribute_definitions' => [], 'connections' => []]));
        $highlightsPath = tempnam(sys_get_temp_dir(), 'wtf-reimport-highlights').'.json';
        file_put_contents($highlightsPath, json_encode([]));

        $this->artisan('wtf:connections:reimport', [
            'email' => $user->email,
            'connections' => $connectionsPath,
            'highlights' => $highlightsPath,
            '--force' => true,
        ])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->assertExitCode(0);
    }

    private function connectionNamed(User $user, string $name): Connection
    {
        $vaultKey = $this->vaultKey($user);
        $ring = KeyRing::decrypt($vaultKey, $user->key_ring_ciphertext);
        [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $connectionId = Str::uuid()->toString());
        $user->update(['key_ring_ciphertext' => KeyRing::encrypt($vaultKey, $ring)]);

        return Connection::create([
            'id' => $connectionId,
            'user_id' => $user->id,
            'name_ciphertext' => AesGcm::encrypt($rawKey, $name),
        ]);
    }

    private function userWithVault(): User
    {
        $salt = base64_encode(random_bytes(16));
        $vaultKey = Argon2id::derive(self::PASSPHRASE, $salt);

        return User::factory()->create([
            'passphrase_salt' => $salt,
            'key_ring_ciphertext' => KeyRing::encrypt($vaultKey, []),
        ]);
    }

    private function vaultKey(User $user): string
    {
        return Argon2id::derive(self::PASSPHRASE, $user->passphrase_salt);
    }
}
