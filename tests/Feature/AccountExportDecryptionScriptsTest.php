<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionEdge;
use App\Models\ConnectionSource;
use App\Models\ConnectionSourceCategory;
use App\Models\ShareLink;
use App\Models\SleepException;
use App\Models\User;
use App\Services\Crypto\AesGcm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

/**
 * Proves the two decrypt scripts bundled into every export
 * (app/Services/Account/scripts/decrypt_export.{py,php}) actually recover
 * every "e2ee"-tier field from a REAL generated export — not just that the
 * export contains plausible-looking ciphertext. This seeds one of every
 * e2ee field the app currently has, with real per-record keys collected
 * into a real key ring (built the same way the browser would, verified
 * cross-language: PHP's sodium_crypto_pwhash and Python's argon2-cffi
 * produce byte-identical Argon2id output for identical parameters).
 *
 * IMPORTANT for future maintainers: CLAUDE.md has a note about keeping
 * this test's fixture list in sync whenever a new e2ee table/column is
 * added to AccountExportService — that's the actual enforcement point for
 * "the export's decrypt scripts still cover everything," since the scripts
 * themselves are fully generic (they don't hardcode any table/field names).
 */
class AccountExportDecryptionScriptsTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_PASSWORD = 'correct horse battery staple';

    /** @var array<string, string> record id => raw 32-byte key */
    private array $keyRing = [];

    private function recordKey(string $recordId): string
    {
        return $this->keyRing[$recordId] ??= random_bytes(32);
    }

    private function encryptFor(string $recordId, string $plaintext): string
    {
        return AesGcm::encrypt($this->recordKey($recordId), $plaintext);
    }

    private function deriveVaultKey(string $password, string $saltB64): string
    {
        return sodium_crypto_pwhash(
            32,
            $password,
            base64_decode($saltB64, true),
            2,
            19456 * 1024,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    /**
     * Seeds a user with one of every "e2ee"-tier field, all encrypted with
     * real per-record keys, all of which are then collected into a real
     * encrypted key ring — exactly the shape AccountExportService exports
     * and the decrypt scripts expect. Returns the plaintext values so the
     * test can assert the decrypted output matches them exactly.
     *
     * @return array{user: User, plaintexts: array<string, string>}
     */
    private function seedEverySoftDeletableE2eeField(): array
    {
        $salt = base64_encode(random_bytes(16));
        $user = User::factory()->create([
            'password' => bcrypt('login-verifier'),
            'passphrase_salt' => $salt,
        ]);

        $plaintexts = [];

        // Every model here uses HasUuids and gets a real primary key only
        // once created — so each is created with a placeholder ciphertext
        // first, then updated with real ciphertext encrypted under a key
        // keyed by that now-known real id (exactly what the browser does:
        // generate the record, THEN create its key-ring entry).
        $category = ConnectionSourceCategory::create(['user_id' => $user->id, 'name_ciphertext' => 'placeholder']);
        $plaintexts['category_name'] = 'Category Name';
        $category->forceFill(['name_ciphertext' => $this->encryptFor($category->id, $plaintexts['category_name'])])->save();

        $source = ConnectionSource::create(['user_id' => $user->id, 'category_id' => $category->id, 'name_ciphertext' => 'placeholder']);
        $plaintexts['source_name'] = 'Source Name';
        $source->forceFill(['name_ciphertext' => $this->encryptFor($source->id, $plaintexts['source_name'])])->save();

        $connection = Connection::create(['user_id' => $user->id, 'name_ciphertext' => 'placeholder']);
        $plaintexts['connection_name'] = 'Connection Name';
        $plaintexts['connection_notes'] = 'Connection Notes';
        $connection->forceFill([
            'name_ciphertext' => $this->encryptFor($connection->id, $plaintexts['connection_name']),
            'notes_ciphertext' => $this->encryptFor($connection->id, $plaintexts['connection_notes']),
        ])->save();
        $connection->sources()->attach($source->id);

        $otherConnection = Connection::create(['user_id' => $user->id, 'name_ciphertext' => 'placeholder']);
        $otherConnection->forceFill(['name_ciphertext' => $this->encryptFor($otherConnection->id, 'Other Connection Name')])->save();

        $definition = ConnectionAttributeDefinition::create([
            'user_id' => $user->id,
            'label_ciphertext' => 'placeholder',
            'type' => 'text',
        ]);
        $plaintexts['definition_label'] = 'Definition Label';
        $plaintexts['definition_options'] = '["A","B"]';
        $definition->forceFill([
            'label_ciphertext' => $this->encryptFor($definition->id, $plaintexts['definition_label']),
            'options_ciphertext' => $this->encryptFor($definition->id, $plaintexts['definition_options']),
        ])->save();

        // Attribute values are keyed by their PARENT connection's id, not
        // their own — this is the one exception the scripts must handle.
        // The connection's id is already known here, so no placeholder step
        // is needed for this one.
        $plaintexts['attribute_value'] = 'Attribute Value';
        ConnectionAttributeValue::create([
            'connection_id' => $connection->id,
            'attribute_definition_id' => $definition->id,
            'value_ciphertext' => $this->encryptFor($connection->id, $plaintexts['attribute_value']),
        ]);

        $edge = ConnectionEdge::create([
            'user_id' => $user->id,
            'from_connection_id' => $connection->id,
            'to_connection_id' => $otherConnection->id,
            'label_ciphertext' => 'placeholder',
        ]);
        $plaintexts['edge_label'] = 'Edge Label';
        $edge->forceFill(['label_ciphertext' => $this->encryptFor($edge->id, $plaintexts['edge_label'])])->save();

        $shareLink = ShareLink::factory()->for($user)->create(['label_ciphertext' => null]);
        $plaintexts['share_link_label'] = 'Share Link Label';
        $shareLink->forceFill(['label_ciphertext' => $this->encryptFor($shareLink->id, $plaintexts['share_link_label'])])->save();

        $sleepException = SleepException::create([
            'user_id' => $user->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-02',
            'label_ciphertext' => 'placeholder',
        ]);
        $plaintexts['sleep_exception_label'] = 'Sleep Exception Label';
        $sleepException->forceFill(['label_ciphertext' => $this->encryptFor($sleepException->id, $plaintexts['sleep_exception_label'])])->save();

        $keyRingJson = json_encode(array_map(fn (string $key) => base64_encode($key), $this->keyRing));
        $user->forceFill([
            'key_ring_ciphertext' => AesGcm::encrypt($this->deriveVaultKey(self::MASTER_PASSWORD, $salt), $keyRingJson),
        ])->save();

        return ['user' => $user, 'plaintexts' => $plaintexts];
    }

    /** @return array{0: string, 1: array<string, mixed>} zip bytes path, decoded decrypted.json contents by relative path */
    private function exportAndExtract(User $user): string
    {
        $response = $this->actingAs($user)
            ->post('/dashboard/account/export', ['password' => 'login-verifier']);
        $response->assertOk();

        $dir = sys_get_temp_dir().'/when-export-scripts-test-'.Str::random(8);
        mkdir($dir);

        $zipPath = $dir.'/export.zip';
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $zip->extractTo($dir);
        $zip->close();

        return $dir;
    }

    /** @param array<string, string> $decryptedFiles relative path (without .decrypted.json) => decoded contents */
    private function assertPlaintextsPresent(array $decryptedFiles, array $plaintexts): void
    {
        $connections = collect($decryptedFiles['connections/connections']['records']);
        $this->assertSame($plaintexts['connection_name'], $connections->firstWhere('name', $plaintexts['connection_name'])['name']);
        $this->assertSame($plaintexts['connection_notes'], $connections->firstWhere('notes', $plaintexts['connection_notes'])['notes']);

        $this->assertSame($plaintexts['source_name'], collect($decryptedFiles['connections/sources']['records'])->first()['name'] ?? null);
        $this->assertContains($plaintexts['category_name'], collect($decryptedFiles['connections/source-categories']['records'])->pluck('name'));
        $this->assertContains($plaintexts['definition_label'], collect($decryptedFiles['connections/attribute-definitions']['records'])->pluck('label'));
        $this->assertContains($plaintexts['definition_options'], collect($decryptedFiles['connections/attribute-definitions']['records'])->pluck('options'));
        $this->assertContains($plaintexts['attribute_value'], collect($decryptedFiles['connections/attribute-values']['records'])->pluck('value'));
        $this->assertContains($plaintexts['edge_label'], collect($decryptedFiles['connections/edges']['records'])->pluck('label'));
        $this->assertContains($plaintexts['share_link_label'], collect($decryptedFiles['share-links/share-links']['records'])->pluck('label'));
        $this->assertContains($plaintexts['sleep_exception_label'], collect($decryptedFiles['availability/sleep-exceptions']['records'])->pluck('label'));
    }

    /** @return array<string, array<string, mixed>> */
    private function readDecryptedFiles(string $dir): array
    {
        $relativePaths = [
            'connections/connections',
            'connections/sources',
            'connections/source-categories',
            'connections/attribute-definitions',
            'connections/attribute-values',
            'connections/edges',
            'share-links/share-links',
            'availability/sleep-exceptions',
        ];

        $decoded = [];
        foreach ($relativePaths as $path) {
            $file = "{$dir}/{$path}.decrypted.json";
            $this->assertFileExists($file, "Expected {$path}.decrypted.json to be written.");
            $decoded[$path] = json_decode(file_get_contents($file), true);
        }

        return $decoded;
    }

    public function test_the_bundled_php_script_decrypts_every_e2ee_field(): void
    {
        ['user' => $user, 'plaintexts' => $plaintexts] = $this->seedEverySoftDeletableE2eeField();
        $dir = $this->exportAndExtract($user);

        $process = new Process(['php', "{$dir}/decrypt_export.php"], $dir, null, self::MASTER_PASSWORD."\n");
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $this->assertPlaintextsPresent($this->readDecryptedFiles($dir), $plaintexts);
    }

    public function test_the_bundled_python_script_decrypts_every_e2ee_field(): void
    {
        $check = new Process(['python3', '-c', 'import argon2, cryptography']);
        $check->run();
        if (! $check->isSuccessful()) {
            $this->markTestSkipped('python3 with argon2-cffi and cryptography not available — see app/Services/Account/scripts/requirements.txt.');
        }

        ['user' => $user, 'plaintexts' => $plaintexts] = $this->seedEverySoftDeletableE2eeField();
        $dir = $this->exportAndExtract($user);

        $process = new Process(['python3', "{$dir}/decrypt_export.py"], $dir, null, self::MASTER_PASSWORD."\n");
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $this->assertPlaintextsPresent($this->readDecryptedFiles($dir), $plaintexts);
    }
}
