<?php

namespace Tests\Feature;

use App\Models\ShareLink;
use App\Models\User;
use App\Services\Crypto\AesGcm;
use App\Services\Crypto\Argon2id;
use App\Services\Crypto\KeyRing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportShareLinkLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_labels_encrypted_with_the_owners_vault_and_never_stores_plaintext(): void
    {
        $user = User::factory()->create();
        $shareLink = ShareLink::factory()->for($user)->create(['legacy_token' => 'legacy-abc']);

        $inputPath = tempnam(sys_get_temp_dir(), 'wtf-labels');
        file_put_contents($inputPath, json_encode([
            ['token' => 'legacy-abc', 'label' => 'For Mom'],
        ]));

        $this->artisan('wtf:vault:import-labels', ['email' => $user->email, 'input' => $inputPath])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, 'correct horse battery staple')
            ->expectsOutputToContain('Updated 1 label(s), skipped 0.')
            ->assertExitCode(0);

        $shareLink->refresh();
        $user->refresh();

        $this->assertNotNull($shareLink->label_ciphertext);
        $this->assertStringNotContainsString('For Mom', $shareLink->label_ciphertext);
        $this->assertNotNull($user->key_ring_ciphertext);

        // Round-trip through the exact same client-side path the dashboard would use.
        $vaultKey = Argon2id::derive('correct horse battery staple', $user->passphrase_salt);
        $ring = KeyRing::decrypt($vaultKey, $user->key_ring_ciphertext);
        $this->assertArrayHasKey($shareLink->id, $ring);

        $rawKey = base64_decode($ring[$shareLink->id], true);
        $this->assertSame('For Mom', AesGcm::decrypt($rawKey, $shareLink->label_ciphertext));

        unlink($inputPath);
    }

    public function test_skips_a_token_that_does_not_belong_to_the_owner(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        ShareLink::factory()->for($otherUser)->create(['legacy_token' => 'not-mine']);

        $inputPath = tempnam(sys_get_temp_dir(), 'wtf-labels');
        file_put_contents($inputPath, json_encode([
            ['token' => 'not-mine', 'label' => 'Should not apply'],
        ]));

        $this->artisan('wtf:vault:import-labels', ['email' => $user->email, 'input' => $inputPath])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, 'any passphrase')
            ->expectsOutputToContain('Updated 0 label(s), skipped 1.')
            ->assertExitCode(0);

        unlink($inputPath);
    }

    public function test_wrong_passphrase_fails_cleanly_when_a_vault_already_has_data(): void
    {
        $user = User::factory()->create();
        $vaultKey = Argon2id::derive('the real passphrase', $user->passphrase_salt);
        $user->update(['key_ring_ciphertext' => KeyRing::encrypt($vaultKey, ['x' => base64_encode(random_bytes(32))])]);

        $shareLink = ShareLink::factory()->for($user)->create(['legacy_token' => 'legacy-abc']);
        $inputPath = tempnam(sys_get_temp_dir(), 'wtf-labels');
        file_put_contents($inputPath, json_encode([['token' => 'legacy-abc', 'label' => 'Nope']]));

        $this->artisan('wtf:vault:import-labels', ['email' => $user->email, 'input' => $inputPath])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, 'the WRONG passphrase')
            ->expectsOutputToContain('Wrong passphrase')
            ->assertExitCode(1);

        $this->assertNull($shareLink->fresh()->label_ciphertext);

        unlink($inputPath);
    }
}
