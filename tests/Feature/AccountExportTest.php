<?php

namespace Tests\Feature;

use App\Models\ActivityRole;
use App\Models\CalendarDetection;
use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionEdge;
use App\Models\ConnectionSource;
use App\Models\ConnectionSourceCategory;
use App\Models\Invite;
use App\Models\InviteRedemption;
use App\Models\ShareLink;
use App\Models\ShareLinkWord;
use App\Models\SleepException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;
use ZipArchive;

/**
 * POST /dashboard/account/export — see App\Services\Account\AccountExportService
 * for what's actually in the zip and App\Http\Controllers\Dashboard\
 * AccountExportController for the streaming/confirmation wiring.
 */
class AccountExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_export(): void
    {
        $this->post('/dashboard/account/export')->assertRedirect('/login');
    }

    public function test_missing_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/dashboard/account/export')
            ->assertSessionHasErrors('password');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-verifier')]);

        $this->actingAs($user)
            ->post('/dashboard/account/export', ['password' => 'wrong-verifier'])
            ->assertSessionHasErrors('password');
    }

    public function test_correct_password_streams_a_zip(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-verifier')]);

        $response = $this->actingAs($user)
            ->post('/dashboard/account/export', ['password' => 'correct-verifier']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.zip', $response->headers->get('Content-Disposition'));
    }

    public function test_zip_contains_expected_files_and_correct_tiers(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('correct-verifier'),
            'name' => 'Export Fox',
        ]);

        $shareLink = ShareLink::factory()->for($user)->create(['label_ciphertext' => 'opaque-label']);
        ShareLinkWord::create(['share_link_id' => $shareLink->id, 'word_ciphertext' => Crypt::encryptString('dinner')]);

        SleepException::create([
            'user_id' => $user->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-02',
            'label_ciphertext' => 'opaque-sleep-label',
        ]);

        $activityRole = ActivityRole::create([
            'user_id' => $user->id,
            'pattern' => '^host\s+(.+)$',
            'sort_order' => 0,
        ]);
        // label isn't mass-assignable — see HasLocalizedFields' own doc
        // comment; set it directly instead.
        $activityRole->setLocalizedField('label', ['default' => 'Visiting']);

        $category = ConnectionSourceCategory::create(['user_id' => $user->id, 'name_ciphertext' => 'opaque-category']);
        $source = ConnectionSource::create(['user_id' => $user->id, 'category_id' => $category->id, 'name_ciphertext' => 'opaque-source']);
        $connection = Connection::create(['user_id' => $user->id, 'name_ciphertext' => 'opaque-name', 'notes_ciphertext' => 'opaque-notes']);
        $connection->sources()->attach($source->id);

        $definition = ConnectionAttributeDefinition::create(['user_id' => $user->id, 'label_ciphertext' => 'opaque-attr-label', 'type' => 'text']);
        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $definition->id, 'value_ciphertext' => 'opaque-attr-value']);

        $other = Connection::create(['user_id' => $user->id, 'name_ciphertext' => 'opaque-name-2']);
        ConnectionEdge::create(['user_id' => $user->id, 'from_connection_id' => $connection->id, 'to_connection_id' => $other->id, 'label_ciphertext' => 'opaque-edge-label']);

        $invite = Invite::create(['inviter_user_id' => $user->id, 'code' => 'INVITE1']);
        $invitee = User::factory()->create();
        InviteRedemption::create(['invite_id' => $invite->id, 'user_id' => $invitee->id, 'redeemed_at' => now()]);

        CalendarDetection::create(['user_id' => $user->id, 'detected_mode' => 'full_detail', 'fetched_at' => now()]);

        $response = $this->actingAs($user)
            ->post('/dashboard/account/export', ['password' => 'correct-verifier']);

        $response->assertOk();

        $zip = $this->openZip($response->streamedContent());

        $expectedEntries = [
            'README.txt',
            'decrypt_export.py',
            'decrypt_export.php',
            'requirements.txt',
            'account/profile.json',
            'account/security.json',
            'account/calendar-url.json',
            'account/key-parameters.json',
            'account/invites-issued.json',
            'account/invite-redemptions.json',
            'account/calendar-detections.json',
            'availability/sleep-exceptions.json',
            'availability/activity-roles.json',
            'share-links/share-links.json',
            'share-links/share-link-words.json',
            'share-links/share-link-cache-note.txt',
            'connections/connections.json',
            'connections/sources.json',
            'connections/source-categories.json',
            'connections/attribute-definitions.json',
            'connections/attribute-values.json',
            'connections/edges.json',
            'connections/source-links.json',
        ];

        foreach ($expectedEntries as $entry) {
            $this->assertNotFalse($zip->locateName($entry), "Zip is missing expected entry: {$entry}");
        }

        $profile = json_decode($zip->getFromName('account/profile.json'), true);
        $this->assertSame('server-decrypted', $profile['tier']);
        $this->assertSame('Export Fox', $profile['records'][0]['name']);

        $connections = json_decode($zip->getFromName('connections/connections.json'), true);
        $this->assertSame('e2ee', $connections['tier']);
        $exportedConnection = collect($connections['records'])->firstWhere('id', $connection->id);
        $this->assertSame('opaque-name', $exportedConnection['name_ciphertext']);
        $this->assertSame($connection->id, $exportedConnection['key_ring_id']);
        $this->assertArrayNotHasKey('source_id', $exportedConnection);

        $attributeValues = json_decode($zip->getFromName('connections/attribute-values.json'), true);
        $exportedValue = $attributeValues['records'][0];
        $this->assertSame($connection->id, $exportedValue['key_ring_id'], 'attribute value key_ring_id must be the parent connection id');

        $shareLinkWords = json_decode($zip->getFromName('share-links/share-link-words.json'), true);
        $this->assertSame('server-decrypted', $shareLinkWords['tier']);
        $this->assertSame('dinner', $shareLinkWords['records'][0]['word']);

        $shareLinks = json_decode($zip->getFromName('share-links/share-links.json'), true);
        $this->assertSame('e2ee', $shareLinks['tier']);
        $this->assertSame('opaque-label', $shareLinks['records'][0]['label_ciphertext']);

        $zip->close();
    }

    public function test_a_sixth_export_request_in_one_day_is_throttled(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-verifier')]);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)
                ->post('/dashboard/account/export', ['password' => 'correct-verifier'])
                ->assertOk();
        }

        $this->actingAs($user)
            ->post('/dashboard/account/export', ['password' => 'correct-verifier'])
            ->assertStatus(429);
    }

    private function openZip(string $bytes): ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'when-export-test-');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $zip->open($path);

        return $zip;
    }
}
