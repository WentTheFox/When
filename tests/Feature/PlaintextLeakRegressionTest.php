<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionEdge;
use App\Models\ConnectionSource;
use App\Models\ConnectionSourceCategory;
use App\Models\ShareLink;
use App\Models\ShareLinkWord;
use App\Models\SleepException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Turns PLAN.md §0's crypto guarantees into a regression test: seeds fixture
 * data through the same paths the app uses, then inspects the raw database
 * (bypassing Eloquent casts/mutators) for anything that shouldn't be there.
 *
 * - calendar_url / highlight words (§0.2): encrypted at rest with the
 *   runtime-only key (Crypt/APP_KEY). Never plaintext in any column.
 * - Connections CRM (§0.1): the server never sees plaintext at all, so
 *   fixtures here simulate opaque client-produced ciphertext — the
 *   assertion is that the known plaintext never appears anywhere in the
 *   dump. A share link's content key is never generated or stored at all
 *   (it derives deterministically from the link's own highlight_token, see
 *   HighlightTokenKey), so there's no separate key-secrecy assertion to
 *   make for it here.
 */
class PlaintextLeakRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const USER_NAME = 'PlaintextSentinelUserName_AliceExampleton';

    private const USER_EMAIL = 'plaintext-sentinel-alice@example.com';

    private const CALENDAR_URL = 'https://calendar.example.com/secret-feed-9182734.ics';

    private const HIGHLIGHT_WORD = 'PlaintextSentinelWord_CoffeeWithAlice';

    private const CONNECTION_NAME = 'PlaintextSentinelName_AliceExampleton';

    private const CONNECTION_NOTES = 'PlaintextSentinelNotes_MetAtConference2026';

    private const SLEEP_LABEL = 'PlaintextSentinelLabel_OnVacationInJapan';

    private const SHARE_LINK_LABEL = 'PlaintextSentinelLabel_ForMomOnly';

    private const ATTRIBUTE_LABEL = 'PlaintextSentinelAttr_Birthday';

    private const ATTRIBUTE_VALUE = 'PlaintextSentinelValue_19900101';

    private const EDGE_LABEL = 'PlaintextSentinelEdge_SiblingOf';

    private const SOURCE_NAME = 'PlaintextSentinelSource_MetOnDiscord';

    private const CATEGORY_NAME = 'PlaintextSentinelCategory_SocialMedia';

    public function test_no_known_plaintext_survives_in_any_stored_column(): void
    {
        $user = User::factory()->create([
            'name' => self::USER_NAME,
            'email' => self::USER_EMAIL,
            'calendar_url_ciphertext' => Crypt::encryptString(self::CALENDAR_URL),
        ]);

        SleepException::create([
            'user_id' => $user->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-07',
            // Client-side E2EE (§0.1): a real client encrypts this plaintext
            // with a key the server never has. Simulated the same way here —
            // random per-field key, immediately discarded — so this test
            // actually exercises "does the sentinel plaintext leak," not a
            // vacuous check against unrelated random bytes.
            'label_ciphertext' => $this->fakeClientCiphertext(self::SLEEP_LABEL),
        ]);

        $category = ConnectionSourceCategory::create([
            'user_id' => $user->id,
            'name_ciphertext' => $this->fakeClientCiphertext(self::CATEGORY_NAME),
        ]);

        $source = ConnectionSource::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'name_ciphertext' => $this->fakeClientCiphertext(self::SOURCE_NAME),
        ]);

        $shareLink = ShareLink::create([
            'user_id' => $user->id,
            'label_ciphertext' => $this->fakeClientCiphertext(self::SHARE_LINK_LABEL),
        ]);

        // Highlight words are matched server-side against ICS titles during
        // recompute (§5.1), so they're encrypted at rest with the runtime
        // key — same tier as calendar_url, not the client vault key.
        ShareLinkWord::create([
            'share_link_id' => $shareLink->id,
            'word_ciphertext' => Crypt::encryptString(self::HIGHLIGHT_WORD),
        ]);

        $connection = Connection::create([
            'user_id' => $user->id,
            'source_id' => $source->id,
            'name_ciphertext' => $this->fakeClientCiphertext(self::CONNECTION_NAME),
            'notes_ciphertext' => $this->fakeClientCiphertext(self::CONNECTION_NOTES),
            'share_link_id' => $shareLink->id,
        ]);

        $otherConnection = Connection::create([
            'user_id' => $user->id,
            'name_ciphertext' => $this->fakeClientCiphertext('PlaintextSentinelName_OtherConnection'),
        ]);

        $attributeDefinition = ConnectionAttributeDefinition::create([
            'user_id' => $user->id,
            'label_ciphertext' => $this->fakeClientCiphertext(self::ATTRIBUTE_LABEL),
            'type' => 'date',
        ]);

        ConnectionAttributeValue::create([
            'connection_id' => $connection->id,
            'attribute_definition_id' => $attributeDefinition->id,
            'value_ciphertext' => $this->fakeClientCiphertext(self::ATTRIBUTE_VALUE),
        ]);

        ConnectionEdge::create([
            'user_id' => $user->id,
            'from_connection_id' => $connection->id,
            'to_connection_id' => $otherConnection->id,
            'label_ciphertext' => $this->fakeClientCiphertext(self::EDGE_LABEL),
        ]);

        $dump = $this->dumpEntireDatabaseAsString();

        $knownPlaintextSecrets = [
            self::USER_NAME,
            self::USER_EMAIL,
            self::CALENDAR_URL,
            self::HIGHLIGHT_WORD,
            self::CONNECTION_NAME,
            self::CONNECTION_NOTES,
            self::SLEEP_LABEL,
            self::SHARE_LINK_LABEL,
            self::ATTRIBUTE_LABEL,
            self::ATTRIBUTE_VALUE,
            self::EDGE_LABEL,
            self::SOURCE_NAME,
            self::CATEGORY_NAME,
        ];

        foreach ($knownPlaintextSecrets as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $dump,
                "Found plaintext secret \"{$secret}\" in the raw database dump — a ciphertext column is storing plaintext."
            );
        }
    }

    public function test_name_and_email_columns_round_trip_via_runtime_key_only(): void
    {
        $user = User::factory()->create([
            'name' => self::USER_NAME,
            'email' => self::USER_EMAIL,
        ]);

        $row = DB::table('users')->where('id', $user->id)->first();

        $this->assertStringNotContainsString(self::USER_NAME, $row->name);
        $this->assertStringNotContainsString(self::USER_EMAIL, $row->email);
        $this->assertSame(self::USER_NAME, Crypt::decryptString($row->name));
        $this->assertSame(self::USER_EMAIL, Crypt::decryptString($row->email));

        // The accessor decrypts transparently...
        $this->assertSame(self::USER_NAME, $user->refresh()->name);
        $this->assertSame(self::USER_EMAIL, $user->email);

        // ...and the deterministic hash (not the ciphertext) is what makes
        // lookup and uniqueness possible against an encrypted column.
        $this->assertSame(User::hashEmail(self::USER_EMAIL), $row->email_hash);
        $this->assertTrue(User::whereEmail(self::USER_EMAIL)->first()->is($user));
        $this->assertTrue(User::whereEmail(strtoupper(self::USER_EMAIL))->exists());
    }

    public function test_calendar_url_column_round_trips_via_runtime_key_only(): void
    {
        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString(self::CALENDAR_URL),
        ]);

        $rawColumn = DB::table('users')->where('id', $user->id)->value('calendar_url_ciphertext');

        $this->assertStringNotContainsString(self::CALENDAR_URL, $rawColumn);
        $this->assertSame(self::CALENDAR_URL, Crypt::decryptString($rawColumn));
    }

    /**
     * Stands in for real client-side WebCrypto AES-256-GCM encryption: the
     * key is random and immediately discarded, so the server (and this test
     * harness, playing the server's role) never has it either — matching
     * §0.1's guarantee. Using a real cipher on the real plaintext, rather
     * than unrelated random bytes, is what makes the leak assertions below
     * meaningful instead of vacuously true.
     */
    private function fakeClientCiphertext(string $plaintext): string
    {
        $key = random_bytes(32);
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv.$tag.$ciphertext);
    }

    private function dumpEntireDatabaseAsString(): string
    {
        $tables = [
            'users', 'sleep_exceptions', 'connection_source_categories',
            'connection_sources', 'share_links', 'share_link_words',
            'share_link_cache', 'connections', 'connection_attribute_definitions',
            'connection_attribute_values', 'connection_edges', 'invites',
            'invite_redemptions',
        ];

        $chunks = [];

        foreach ($tables as $table) {
            foreach (DB::table($table)->get() as $row) {
                $chunks[] = json_encode((array) $row);
            }
        }

        return implode("\n", $chunks);
    }
}
