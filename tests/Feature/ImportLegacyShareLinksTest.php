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
 * wtf:import-legacy-share-links — Stage 5's one-time migration command,
 * plus the connection ↔ share-link auto-linking it derives from
 * highlight_words. Same vault-unlock boundary as the Connections CLI:
 * connection names are client-vault E2EE (§0.1), so matching against them
 * needs the interactive passphrase prompt tested here via expectsQuestion.
 */
class ImportLegacyShareLinksTest extends TestCase
{
    use RefreshDatabase;

    private const PASSPHRASE = 'correct horse battery staple';

    public function test_imports_a_share_link_and_its_highlight_words(): void
    {
        $user = $this->userWithVault();
        $path = $this->writeInput([
            ['token' => 'legacy-token-1', 'owner_email' => $user->email, 'bypass_dnd' => true, 'highlight_words' => ['Alice']],
        ]);

        $this->artisan('wtf:import-legacy-share-links', ['input' => $path])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->assertExitCode(0);

        $shareLink = ShareLink::where('legacy_token', 'legacy-token-1')->first();
        $this->assertNotNull($shareLink);
        $this->assertTrue($shareLink->bypass_dnd);
        $this->assertSame(['Alice'], $shareLink->words->map(
            fn ($w) => Crypt::decryptString($w->word_ciphertext),
        )->all());
    }

    public function test_re_running_with_the_same_input_skips_the_already_imported_token(): void
    {
        $user = $this->userWithVault();
        $path = $this->writeInput([
            ['token' => 'legacy-token-1', 'owner_email' => $user->email, 'highlight_words' => []],
        ]);

        $this->artisan('wtf:import-legacy-share-links', ['input' => $path])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->assertExitCode(0);

        $this->assertSame(1, ShareLink::count());

        $this->artisan('wtf:import-legacy-share-links', ['input' => $path])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->expectsOutputToContain('Imported 0 share link(s), skipped 1 already-imported token(s)')
            ->assertExitCode(0);

        $this->assertSame(1, ShareLink::count());
    }

    public function test_a_highlight_word_matching_exactly_one_connection_links_it(): void
    {
        $user = $this->userWithVault();
        $connection = $this->connectionNamed($user, 'Alice');
        $path = $this->writeInput([
            ['token' => 'legacy-token-1', 'owner_email' => $user->email, 'highlight_words' => ['Alice']],
        ]);

        $this->artisan('wtf:import-legacy-share-links', ['input' => $path])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->expectsOutputToContain('linked 1 connection(s)')
            ->assertExitCode(0);

        $shareLink = ShareLink::where('legacy_token', 'legacy-token-1')->first();
        $this->assertSame($shareLink->id, $connection->refresh()->share_link_id);
    }

    public function test_a_highlight_word_matching_two_connections_links_neither(): void
    {
        $user = $this->userWithVault();
        $this->connectionNamed($user, 'Alice');
        $this->connectionNamed($user, 'Alice');
        $path = $this->writeInput([
            ['token' => 'legacy-token-1', 'owner_email' => $user->email, 'highlight_words' => ['Alice']],
        ]);

        $this->artisan('wtf:import-legacy-share-links', ['input' => $path])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->expectsOutputToContain('linked 0 connection(s)')
            ->assertExitCode(0);

        $this->assertSame(0, Connection::whereNotNull('share_link_id')->count());
    }

    public function test_a_connection_already_tied_to_a_link_is_left_alone(): void
    {
        $user = $this->userWithVault();
        $existingLink = ShareLink::factory()->for($user)->create();
        $connection = $this->connectionNamed($user, 'Alice', shareLinkId: $existingLink->id);
        $path = $this->writeInput([
            ['token' => 'legacy-token-1', 'owner_email' => $user->email, 'highlight_words' => ['Alice']],
        ]);

        $this->artisan('wtf:import-legacy-share-links', ['input' => $path])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->expectsOutputToContain('linked 0 connection(s)')
            ->assertExitCode(0);

        $this->assertSame($existingLink->id, $connection->refresh()->share_link_id);
    }

    public function test_a_wrong_passphrase_still_imports_the_link_but_skips_linking(): void
    {
        $user = $this->userWithVault();
        $this->connectionNamed($user, 'Alice');
        $path = $this->writeInput([
            ['token' => 'legacy-token-1', 'owner_email' => $user->email, 'highlight_words' => ['Alice']],
        ]);

        $this->artisan('wtf:import-legacy-share-links', ['input' => $path])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, 'the wrong passphrase')
            ->assertExitCode(0);

        $this->assertSame(1, ShareLink::count());
        $this->assertSame(0, Connection::whereNotNull('share_link_id')->count());
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function writeInput(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wtf-legacy-links-test').'.json';
        file_put_contents($path, json_encode($rows));

        return $path;
    }

    private function connectionNamed(User $user, string $name, ?string $shareLinkId = null): Connection
    {
        $vaultKey = $this->vaultKey($user);
        $ring = KeyRing::decrypt($vaultKey, $user->key_ring_ciphertext);
        [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $connectionId = Str::uuid()->toString());
        $user->update(['key_ring_ciphertext' => KeyRing::encrypt($vaultKey, $ring)]);

        return Connection::create([
            'id' => $connectionId,
            'user_id' => $user->id,
            'name_ciphertext' => AesGcm::encrypt($rawKey, $name),
            'share_link_id' => $shareLinkId,
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
