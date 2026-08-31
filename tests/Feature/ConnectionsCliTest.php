<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionEdge;
use App\Models\ConnectionSource;
use App\Models\ConnectionSourceCategory;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Crypto\AesGcm;
use App\Services\Crypto\Argon2id;
use App\Services\Crypto\KeyRing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stage 7's Connections CLI extension (wtf:connections:*) — same operator-CLI
 * pattern and E2EE boundary as ImportShareLinkLabelsTest: the passphrase is
 * prompted interactively, the vault key is derived locally, and every write
 * is real ciphertext this test can only read back by deriving the same key
 * a browser would.
 */
class ConnectionsCliTest extends TestCase
{
    use RefreshDatabase;

    private const PASSPHRASE = 'correct horse battery staple';

    public function test_add_creates_a_connection_with_source_and_attribute(): void
    {
        $user = $this->userWithVault();
        $vaultKey = $this->vaultKey($user);
        $ring = KeyRing::decrypt($vaultKey, $user->key_ring_ciphertext);

        $definitionId = Str::uuid()->toString();
        $definitionRawKey = random_bytes(32);
        $ring[$definitionId] = base64_encode($definitionRawKey);
        $user->update(['key_ring_ciphertext' => KeyRing::encrypt($vaultKey, $ring)]);

        ConnectionAttributeDefinition::create([
            'id' => $definitionId,
            'user_id' => $user->id,
            'label_ciphertext' => AesGcm::encrypt($definitionRawKey, 'Birthday'),
            'type' => 'date',
        ]);

        $this->artisan('wtf:connections:add', ['email' => $user->email])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->expectsQuestion('Name', 'Alice Example')
            ->expectsQuestion('Notes (optional)', 'Met at a conference')
            ->expectsQuestion('Source (optional, created if it doesn\'t exist)', 'Discord')
            ->expectsQuestion('Attribute "Birthday" (date, optional)', '1990-01-01')
            ->assertExitCode(0);

        $connection = Connection::firstOrFail();
        $source = ConnectionSource::firstOrFail();
        $vaultKey = $this->vaultKey($user);
        $ring = KeyRing::decrypt($vaultKey, $user->refresh()->key_ring_ciphertext);

        $this->assertSame('Alice Example', AesGcm::decrypt(base64_decode($ring[$connection->id], true), $connection->name_ciphertext));
        $this->assertSame('Met at a conference', AesGcm::decrypt(base64_decode($ring[$connection->id], true), $connection->notes_ciphertext));
        $this->assertSame('Discord', AesGcm::decrypt(base64_decode($ring[$source->id], true), $source->name_ciphertext));
        $this->assertSame([$source->id], $connection->sources->pluck('id')->all());

        $value = ConnectionAttributeValue::where('connection_id', $connection->id)->firstOrFail();
        $this->assertSame('1990-01-01', AesGcm::decrypt(base64_decode($ring[$connection->id], true), $value->value_ciphertext));
    }

    public function test_add_fails_cleanly_on_wrong_passphrase(): void
    {
        $user = $this->userWithVault();

        $this->artisan('wtf:connections:add', ['email' => $user->email])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, 'the wrong passphrase')
            ->expectsOutputToContain('Wrong passphrase')
            ->assertExitCode(1);

        $this->assertSame(0, Connection::count());
    }

    public function test_edit_updates_name_and_leaves_blank_fields_unchanged(): void
    {
        $user = $this->userWithVault();
        $vaultKey = $this->vaultKey($user);
        $ring = KeyRing::decrypt($vaultKey, $user->key_ring_ciphertext);
        [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $connectionId = Str::uuid()->toString());
        $user->update(['key_ring_ciphertext' => KeyRing::encrypt($vaultKey, $ring)]);

        $connection = Connection::create([
            'id' => $connectionId,
            'user_id' => $user->id,
            'name_ciphertext' => AesGcm::encrypt($rawKey, 'Old Name'),
            'notes_ciphertext' => AesGcm::encrypt($rawKey, 'Old Notes'),
        ]);

        $this->artisan('wtf:connections:edit', ['email' => $user->email, 'id' => $connection->id])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->expectsQuestion('Name [Old Name]', 'New Name')
            ->expectsQuestion('Notes [Old Notes]', '') // blank = keep current
            ->assertExitCode(0);

        $connection->refresh();
        $this->assertSame('New Name', AesGcm::decrypt($rawKey, $connection->name_ciphertext));
        $this->assertSame('Old Notes', AesGcm::decrypt($rawKey, $connection->notes_ciphertext));
    }

    public function test_import_from_json_creates_connections_sources_and_attributes(): void
    {
        $user = $this->userWithVault();

        $path = tempnam(sys_get_temp_dir(), 'wtf-connections').'.json';
        file_put_contents($path, json_encode([
            ['name' => 'Bob Builder', 'notes' => 'Contractor', 'source' => 'LinkedIn', 'attributes' => ['Company' => 'Acme']],
            ['name' => 'Carol Coder', 'source' => 'LinkedIn'],
        ]));

        $this->artisan('wtf:connections:import', ['email' => $user->email, 'input' => $path])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->expectsOutputToContain('Imported 2 connection(s).')
            ->assertExitCode(0);

        $this->assertSame(2, Connection::count());
        $this->assertSame(1, ConnectionSource::count()); // shared "LinkedIn" source, created once
        $this->assertSame(1, ConnectionAttributeDefinition::count());

        $vaultKey = $this->vaultKey($user);
        $ring = KeyRing::decrypt($vaultKey, $user->refresh()->key_ring_ciphertext);
        $source = ConnectionSource::firstOrFail();
        $this->assertSame('LinkedIn', AesGcm::decrypt(base64_decode($ring[$source->id], true), $source->name_ciphertext));

        foreach (Connection::all() as $connection) {
            $this->assertSame([$source->id], $connection->sources->pluck('id')->all());
        }
    }

    /**
     * Source-app export shape — {sources, attribute_definitions,
     * connections} — using entirely synthetic names/data, not a real export.
     */
    public function test_import_from_source_app_shape_creates_sources_categories_attributes_and_edges(): void
    {
        $user = $this->userWithVault();

        $path = tempnam(sys_get_temp_dir(), 'wtf-source-app-export').'.json';
        file_put_contents($path, json_encode([
            'sources' => [
                ['name' => 'Widget Meetup', 'category' => 'group'],
            ],
            'attribute_definitions' => [
                ['label' => 'Standing', 'type' => 'radio', 'options' => ['choices' => ['friend', 'acquaintance']], 'sort_order' => 0],
                ['label' => 'Bio', 'type' => 'textarea', 'options' => null, 'sort_order' => 0],
            ],
            'connections' => [
                [
                    'name' => 'TestPersonOne',
                    'archived' => false,
                    'created_at' => '2026-01-15T10:00:00+00:00',
                    'attribute_values' => [
                        ['attribute_label' => 'Standing', 'value' => 'friend'],
                    ],
                    'highlight_token_label' => 'TestPersonOne',
                    'edges' => [
                        ['type' => 'one_way', 'target_kind' => 'source', 'target_name' => 'Widget Meetup'],
                        ['type' => 'bi_directional', 'target_kind' => 'connection', 'target_name' => 'TestPersonTwo'],
                    ],
                ],
                [
                    'name' => 'TestPersonTwo',
                    'archived' => true,
                    'attribute_values' => [],
                    'highlight_token_label' => null,
                    'edges' => [],
                ],
            ],
        ]));

        $this->artisan('wtf:connections:import', ['email' => $user->email, 'input' => $path])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->assertExitCode(0);

        $this->assertSame(2, Connection::count());
        $this->assertSame(1, ConnectionSource::count());
        $this->assertSame(1, ConnectionSourceCategory::count());
        $this->assertSame(2, ConnectionAttributeDefinition::count());
        // one_way + the reverse leg of the bi_directional pair = 2 edges total
        $this->assertSame(2, ConnectionEdge::count());

        $vaultKey = $this->vaultKey($user);
        $ring = KeyRing::decrypt($vaultKey, $user->refresh()->key_ring_ciphertext);

        $one = Connection::all()->first(fn (Connection $c) => AesGcm::decrypt(base64_decode($ring[$c->id], true), $c->name_ciphertext) === 'TestPersonOne');
        $two = Connection::all()->first(fn (Connection $c) => AesGcm::decrypt(base64_decode($ring[$c->id], true), $c->name_ciphertext) === 'TestPersonTwo');

        $this->assertNotNull($one);
        $this->assertNotNull($two);
        $this->assertFalse($one->archived);
        $this->assertTrue($two->archived);
        $this->assertSame('2026-01-15', $one->created_at->toDateString());

        $source = ConnectionSource::firstOrFail();
        $this->assertSame([$source->id], $one->sources->pluck('id')->all());

        $definition = ConnectionAttributeDefinition::firstOrFail(); // Standing (created first)
        $this->assertSame('radio', $definition->type);
        $this->assertSame(
            ['choices' => ['friend', 'acquaintance']],
            json_decode(AesGcm::decrypt(base64_decode($ring[$definition->id], true), $definition->options_ciphertext), true),
        );

        $value = ConnectionAttributeValue::where('connection_id', $one->id)->firstOrFail();
        $this->assertSame('friend', AesGcm::decrypt(base64_decode($ring[$one->id], true), $value->value_ciphertext));

        // bi_directional -> both directions exist between One and Two.
        $this->assertTrue(ConnectionEdge::where('from_connection_id', $one->id)->where('to_connection_id', $two->id)->exists());
        $this->assertTrue(ConnectionEdge::where('from_connection_id', $two->id)->where('to_connection_id', $one->id)->exists());

        // TestPersonOne has a highlight_token_label -> gets a share link,
        // labeled with that name; TestPersonTwo (null label) does not.
        $this->assertSame(1, ShareLink::count());
        $one->refresh();
        $this->assertNotNull($one->share_link_id);
        $shareLink = ShareLink::findOrFail($one->share_link_id);
        $this->assertSame(
            'TestPersonOne',
            AesGcm::decrypt(base64_decode($ring[$shareLink->id], true), $shareLink->label_ciphertext),
        );
        $this->assertNull($two->refresh()->share_link_id);
    }

    /**
     * Simulates the fix-up scenario: a source-app export was imported back
     * when highlight_token_label only guaranteed a bare connection existed,
     * never a share link. wtf:connections:backfill-share-links, given the
     * same file, should tie/create the missing links without duplicating
     * any connection — and running it twice should be a no-op the second
     * time.
     */
    public function test_backfill_share_links_ties_a_link_to_an_already_imported_connection(): void
    {
        $user = $this->userWithVault();
        $vaultKey = $this->vaultKey($user);
        $ring = KeyRing::decrypt($vaultKey, $user->key_ring_ciphertext);

        [$rawKey, $ring] = KeyRing::getOrCreateKey($ring, $connectionId = Str::uuid()->toString());
        $user->update(['key_ring_ciphertext' => KeyRing::encrypt($vaultKey, $ring)]);

        $connection = Connection::create([
            'id' => $connectionId,
            'user_id' => $user->id,
            'name_ciphertext' => AesGcm::encrypt($rawKey, 'TestPersonOne'),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'wtf-backfill').'.json';
        file_put_contents($path, json_encode([
            'connections' => [
                ['name' => 'TestPersonOne', 'highlight_token_label' => 'TestPersonOne'],
                ['name' => 'TestPersonTwo', 'highlight_token_label' => null],
            ],
        ]));

        $this->artisan('wtf:connections:backfill-share-links', ['email' => $user->email, 'input' => $path])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->expectsOutputToContain('Linked 1 connection(s) to a new share link, skipped 0 already linked, 0 unresolved.')
            ->assertExitCode(0);

        $this->assertSame(1, Connection::count()); // no duplicate created
        $this->assertSame(1, ShareLink::count());

        $connection->refresh();
        $this->assertNotNull($connection->share_link_id);

        $ring = KeyRing::decrypt($vaultKey, $user->refresh()->key_ring_ciphertext);
        $shareLink = ShareLink::findOrFail($connection->share_link_id);
        $this->assertSame(
            'TestPersonOne',
            AesGcm::decrypt(base64_decode($ring[$shareLink->id], true), $shareLink->label_ciphertext),
        );

        // Running it again is a no-op — no second link, no duplicate connection.
        $this->artisan('wtf:connections:backfill-share-links', ['email' => $user->email, 'input' => $path])
            ->expectsQuestion('Enter the vault passphrase for '.$user->email, self::PASSPHRASE)
            ->expectsOutputToContain('Linked 0 connection(s) to a new share link, skipped 1 already linked, 0 unresolved.')
            ->assertExitCode(0);

        $this->assertSame(1, Connection::count());
        $this->assertSame(1, ShareLink::count());
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
