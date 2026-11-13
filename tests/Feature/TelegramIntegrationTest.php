<?php

namespace Tests\Feature;

use App\Jobs\SyncTelegramContacts;
use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\TelegramFriend;
use App\Models\User;
use App\Services\Account\AccountExportService;
use App\Services\Telegram\TelegramSyncService;
use App\Support\FieldFeatures;
use App\Support\TelegramUserId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipStream\ZipStream;

/** The optional Telegram contacts import: login through the sidecar, session handling, contact sync, and the linking endpoints. */
class TelegramIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = '1000000001';

    private const LINKED = '1000000002';

    private const NEW = '1000000003';

    private const SESSION = 'session-string-secret-xyz';

    private const PHOTO = 'data:image/jpeg;base64,/9j/4AAQ';

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real sidecar: an unfaked request fails the test instead of going out.
        Http::preventStrayRequests();

        config(['services.telegram.sidecar_url' => 'http://sidecar.test:8080', 'services.telegram.sidecar_token' => 'sidecar-token']);
    }

    private function owner(bool $connected = true): User
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'identifier_list',
        ]);
        $user->forceFill(['telegram_user_id_definition_id' => $definition->id])->save();

        if ($connected) {
            $user->forceFill(['telegram_session' => self::SESSION, 'telegram_account_name' => 'Owner'])->save();
        }

        return $user->refresh();
    }

    private function connectionWith(User $user, string $telegramId): Connection
    {
        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        ConnectionAttributeValue::create([
            'connection_id' => $connection->id, 'attribute_definition_id' => $user->telegram_user_id_definition_id, 'identifier_list_value' => [$telegramId],
        ]);

        return $connection;
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $extra  Further sidecar routes to fake.
     */
    private function fakeSidecar(array $ids, array $extra = []): Factory
    {
        $contacts = [];
        foreach ($ids as $id) {
            $contacts[$id] = [
                'username' => 'user'.substr($id, -2), 'first_name' => 'First'.substr($id, -2), 'last_name' => $id === self::NEW ? null : 'Last',
                'phone' => '+3069000000'.substr($id, -2), 'mutual' => $id !== self::NEW, 'premium' => false, 'bot' => false, 'has_photo' => true,
            ];
        }
        $contacts['1000000099'] = ['username' => 'somebot', 'first_name' => 'Bot', 'last_name' => null, 'phone' => null, 'mutual' => false, 'premium' => false, 'bot' => true, 'has_photo' => false];

        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake($extra + [
            'sidecar.test:8080/v1/contacts' => Http::response(['contacts' => $contacts, 'session' => 'rotated-session']),
            'sidecar.test:8080/v1/photos' => Http::response(['photos' => array_fill_keys($ids, self::PHOTO) + ['1000000555' => self::PHOTO, '1000000556' => 'javascript:alert(1)'], 'session' => 'rotated-session-2']),
        ]);

        return $factory;
    }

    /** @return array<string, string> */
    private function statuses(User $user): array
    {
        return TelegramFriend::where('user_id', $user->id)->get()->mapWithKeys(fn ($f) => [$f->telegram_user_id => $f->status])->sortKeys()->all();
    }

    public function test_telegram_is_off_without_a_sidecar_and_nothing_is_called(): void
    {
        config(['services.telegram.sidecar_url' => null]);
        $user = $this->owner(connected: false);

        $this->assertFalse(app(TelegramSyncService::class)->summary($user)['available']);
        $this->actingAs($user)->postJson('/settings/telegram/login/start', ['phone' => '+30 690 000 0000'])
            ->assertStatus(422)->assertJsonPath('message', 'Telegram is not set up on this server.');
        $this->actingAs($user)->get(route('settings.edit'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_the_login_runs_in_three_steps_and_the_session_is_stored_encrypted_and_never_returned(): void
    {
        $user = $this->owner(connected: false);
        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake([
            'sidecar.test:8080/v1/login/start' => Http::response(['session' => 'temp-session', 'phone_code_hash' => 'hash123']),
            'sidecar.test:8080/v1/login/code' => Http::response(['needs_password' => true, 'session' => 'half-session']),
            'sidecar.test:8080/v1/login/password' => Http::response(['session' => self::SESSION, 'account_name' => 'Fox Owner', 'user_id' => '42']),
        ]);

        $this->actingAs($user)->postJson('/settings/telegram/login/start', ['phone' => '+30 (690) 000-0000'])
            ->assertOk()->assertJson(['code_sent' => true]);
        $factory->assertSent(fn (Request $r) => str_ends_with($r->url(), '/login/start') && $r['phone'] === '+306900000000' && $r->hasHeader('Authorization', 'Bearer sidecar-token'));

        $this->actingAs($user)->postJson('/settings/telegram/login/code', ['code' => '12-345'])
            ->assertOk()->assertJson(['needs_password' => true, 'connected' => false]);
        $factory->assertSent(fn (Request $r) => str_ends_with($r->url(), '/login/code') && $r['code'] === '12345' && $r['phone_code_hash'] === 'hash123' && $r['session'] === 'temp-session');

        $response = $this->actingAs($user)->postJson('/settings/telegram/login/password', ['password' => 'two-step-pw'])
            ->assertOk()->assertJson(['needs_password' => false, 'connected' => true, 'account_name' => 'Fox Owner']);
        $factory->assertSent(fn (Request $r) => str_ends_with($r->url(), '/login/password') && $r['session'] === 'half-session');

        $this->assertStringNotContainsString(self::SESSION, $response->getContent());
        $raw = DB::table('users')->where('id', $user->id)->value('telegram_session');
        $this->assertNotSame(self::SESSION, $raw);
        $this->assertSame(self::SESSION, Crypt::decryptString($raw));
        $this->assertNull(Cache::get("telegram-login:{$user->id}"));
    }

    public function test_a_login_without_two_step_verification_finishes_at_the_code(): void
    {
        $user = $this->owner(connected: false);
        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake([
            'sidecar.test:8080/v1/login/start' => Http::response(['session' => 'temp-session', 'phone_code_hash' => 'hash123']),
            'sidecar.test:8080/v1/login/code' => Http::response(['needs_password' => false, 'session' => self::SESSION, 'account_name' => 'Owner', 'user_id' => '42']),
        ]);

        $this->actingAs($user)->postJson('/settings/telegram/login/start', ['phone' => '+306900000000'])->assertOk();
        $this->actingAs($user)->postJson('/settings/telegram/login/code', ['code' => '12345'])
            ->assertOk()->assertJson(['needs_password' => false, 'connected' => true]);

        $this->assertSame(self::SESSION, $user->refresh()->telegram_session);
    }

    public function test_a_wrong_code_shows_the_sidecars_message_and_keeps_the_login_open(): void
    {
        $user = $this->owner(connected: false);
        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake([
            'sidecar.test:8080/v1/login/start' => Http::response(['session' => 'temp-session', 'phone_code_hash' => 'hash123']),
            'sidecar.test:8080/v1/login/code' => Http::response(['code' => 'invalid_code', 'message' => 'That code is not right.'], 422),
        ]);

        $this->actingAs($user)->postJson('/settings/telegram/login/start', ['phone' => '+306900000000'])->assertOk();
        $this->actingAs($user)->postJson('/settings/telegram/login/code', ['code' => '99999'])
            ->assertStatus(422)->assertJsonPath('message', 'That code is not right.');

        $this->assertNull($user->refresh()->telegram_session);
        $this->assertNotNull(Cache::get("telegram-login:{$user->id}"));
    }

    public function test_a_code_without_a_started_login_is_refused(): void
    {
        $user = $this->owner(connected: false);

        $this->actingAs($user)->postJson('/settings/telegram/login/code', ['code' => '12345'])
            ->assertStatus(422)->assertJsonPath('message', 'That login expired. Start again to get a new code.');
        Http::assertNothingSent();
    }

    public function test_a_bad_phone_number_is_rejected_before_the_sidecar_is_asked(): void
    {
        $this->actingAs($this->owner(connected: false))->postJson('/settings/telegram/login/start', ['phone' => 'not a phone'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_the_first_sync_makes_contacts_pending_unless_they_already_have_a_connection_and_skips_bots(): void
    {
        $user = $this->owner();
        $this->connectionWith($user, self::LINKED);
        $this->fakeSidecar([self::OLD, self::LINKED]);

        $this->assertTrue(app(TelegramSyncService::class)->sync($user));

        $this->assertSame([self::OLD => 'pending', self::LINKED => 'created'], $this->statuses($user));
        $user->refresh();
        $this->assertNotNull($user->telegram_synced_at);
        // The session the sidecar hands back replaces the stored one (it may rotate).
        $this->assertSame('rotated-session-2', $user->telegram_session);

        $old = TelegramFriend::where('user_id', $user->id)->get()->firstWhere('telegram_user_id', self::OLD);
        $this->assertSame('First01 Last', $old->displayName());
        $this->assertSame('+306900000001', $old->phone);
        $this->assertTrue($old->is_mutual);
        $this->assertSame(self::PHOTO, $old->avatar_url);
    }

    public function test_a_photo_that_is_not_an_inline_jpeg_or_was_not_asked_for_is_never_stored(): void
    {
        $user = $this->owner();
        // The sidecar answers with a script URL for the contact we asked about and a valid photo for one we did not ask for.
        $this->fakeSidecar([self::OLD], ['sidecar.test:8080/v1/photos' => Http::response([
            'photos' => [self::OLD => 'javascript:alert(1)', self::NEW => self::PHOTO], 'session' => 'rotated-session-2',
        ])]);

        app(TelegramSyncService::class)->sync($user);

        $this->assertSame([self::OLD], TelegramFriend::where('user_id', $user->id)->get()->pluck('telegram_user_id')->all());
        $this->assertNull(TelegramFriend::where('user_id', $user->id)->first()->avatar_url);
    }

    public function test_a_failing_photo_fetch_does_not_fail_the_sync(): void
    {
        $user = $this->owner();
        $this->fakeSidecar([self::OLD], ['sidecar.test:8080/v1/photos' => Http::response(['code' => 'telegram_error', 'message' => 'x'], 502)]);

        $this->assertTrue(app(TelegramSyncService::class)->sync($user));

        $this->assertSame([self::OLD => 'pending'], $this->statuses($user));
        $this->assertNotNull($user->refresh()->telegram_synced_at);
        $this->assertSame('rotated-session', $user->telegram_session);
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $hidden  contacts Telegram will not hand a photo for (privacy settings): checked, but nothing comes back
     */
    private function fakeBigAddressBook(array $ids, array $hidden = []): Factory
    {
        $contacts = [];
        foreach ($ids as $id) {
            $contacts[$id] = ['username' => 'u'.$id, 'first_name' => 'N'.$id, 'last_name' => null, 'phone' => null, 'mutual' => true, 'premium' => false, 'bot' => false, 'has_photo' => true];
        }

        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake([
            'sidecar.test:8080/v1/contacts' => Http::response(['contacts' => $contacts, 'session' => 'rotated-session']),
            // Answers for exactly the ids it is asked about, like the real sidecar: a hidden photo is checked but absent.
            'sidecar.test:8080/v1/photos' => fn (Request $r) => Http::response([
                'photos' => collect($r['ids'])->reject(fn ($id) => in_array($id, $hidden, true))->mapWithKeys(fn ($id) => [$id => self::PHOTO])->all(),
                'checked' => array_values($r['ids']),
                'session' => 'rotated-session-2',
            ]),
        ]);

        return $factory;
    }

    public function test_a_big_address_book_gets_every_photo_in_one_sync_not_just_the_first_batch(): void
    {
        $user = $this->owner();
        $ids = array_map(fn ($n) => (string) (2000000000 + $n), range(1, 70));
        $factory = $this->fakeBigAddressBook($ids);

        app(TelegramSyncService::class)->sync($user);

        $this->assertSame(70, TelegramFriend::where('user_id', $user->id)->whereNotNull('avatar_url')->count());
        $factory->assertSentCount(1 + 3); // one contact list, then photos in batches of at most 30
    }

    public function test_contacts_whose_photo_cannot_be_had_do_not_starve_the_ones_behind_them(): void
    {
        $user = $this->owner();
        $ids = array_map(fn ($n) => (string) (2000000000 + $n), range(1, 70));
        // The first 45 hide their photo: with a cap and no memory of having asked, they would hog every batch forever.
        $this->fakeBigAddressBook($ids, hidden: array_slice($ids, 0, 45));

        app(TelegramSyncService::class)->sync($user);

        $this->assertSame(25, TelegramFriend::where('user_id', $user->id)->whereNotNull('avatar_url')->count());

        // Asked once, not again on every sync: the next sync does not ask Telegram about them a second time.
        $factory = $this->fakeBigAddressBook($ids, hidden: array_slice($ids, 0, 45));
        app(TelegramSyncService::class)->sync($user->refresh());
        $factory->assertSentCount(1);
    }

    public function test_a_background_sync_fills_in_missing_photos_of_contacts_it_already_tracks(): void
    {
        $user = $this->owner();
        $this->fakeSidecar([self::OLD]);
        app(TelegramSyncService::class)->sync($user);
        // A row from before photos were tracked properly: no photo, and nothing remembered about having one.
        TelegramFriend::where('user_id', $user->id)->update(['avatar_url' => null, 'has_photo' => false, 'photo_checked_at' => null]);

        $this->fakeSidecar([self::OLD]);
        app(TelegramSyncService::class)->sync($user->refresh(), onlyNew: true);

        $this->assertSame(self::PHOTO, TelegramFriend::where('user_id', $user->id)->first()->avatar_url);
    }

    public function test_a_flood_wait_during_photos_keeps_what_arrived_and_stops_asking(): void
    {
        $user = $this->owner();
        $ids = array_map(fn ($n) => (string) (2000000000 + $n), range(1, 70));
        $contacts = array_fill_keys($ids, ['username' => null, 'first_name' => 'N', 'last_name' => null, 'phone' => null, 'mutual' => true, 'premium' => false, 'bot' => false, 'has_photo' => true]);
        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake([
            'sidecar.test:8080/v1/contacts' => Http::response(['contacts' => $contacts, 'session' => 'rotated-session']),
            // Ten photos arrive, then Telegram says slow down: the rest were not checked.
            'sidecar.test:8080/v1/photos' => fn (Request $r) => Http::response([
                'photos' => collect(array_slice($r['ids'], 0, 10))->mapWithKeys(fn ($id) => [$id => self::PHOTO])->all(),
                'checked' => array_slice($r['ids'], 0, 10), 'retry_after' => 120, 'session' => 'rotated-session-2',
            ]),
        ]);

        app(TelegramSyncService::class)->sync($user);

        $this->assertSame(10, TelegramFriend::where('user_id', $user->id)->whereNotNull('avatar_url')->count());
        $factory->assertSentCount(2); // contacts + ONE photos call: it did not hammer Telegram through the flood wait
        $this->assertSame(60, app(TelegramSyncService::class)->summary($user->refresh())['photos_pending']);
        $this->assertNotNull($user->telegram_synced_at);
    }

    public function test_the_summary_says_how_many_photos_are_still_to_come(): void
    {
        $user = $this->owner();
        $this->fakeSidecar([self::OLD, self::NEW], ['sidecar.test:8080/v1/photos' => Http::response(['photos' => [], 'checked' => [], 'retry_after' => 60, 'session' => 's'])]);

        app(TelegramSyncService::class)->sync($user);

        $this->assertSame(2, app(TelegramSyncService::class)->summary($user->refresh())['photos_pending']);
    }

    public function test_the_stored_fields_are_encrypted_at_rest(): void
    {
        $user = $this->owner();
        $this->fakeSidecar([self::OLD]);
        app(TelegramSyncService::class)->sync($user);

        $row = (array) DB::table('telegram_friends')->where('user_id', $user->id)->first();

        foreach (['telegram_user_id', 'username', 'first_name', 'last_name', 'phone', 'avatar_url'] as $column) {
            $this->assertNotNull($row[$column]);
            $this->assertNotSame(self::OLD, $row[$column]);
            $this->assertStringNotContainsString('First01', $row[$column]);
            $this->assertStringNotContainsString('+30690', $row[$column]);
        }
        $this->assertSame(TelegramUserId::hash(self::OLD), $row['telegram_user_id_hash']);
    }

    public function test_a_background_sync_only_adds_new_contacts_and_leaves_existing_rows_alone(): void
    {
        $user = $this->owner();
        $this->fakeSidecar([self::OLD]);
        app(TelegramSyncService::class)->sync($user);
        TelegramFriend::where('user_id', $user->id)->update(['status' => 'dismissed']);
        $dismissed = TelegramFriend::where('user_id', $user->id)->first();
        $dismissed->first_name = 'Renamed by owner data';
        $dismissed->save();

        $this->fakeSidecar([self::OLD, self::NEW]);
        app(TelegramSyncService::class)->sync($user->refresh(), onlyNew: true);

        $this->assertSame([self::OLD => 'dismissed', self::NEW => 'pending'], $this->statuses($user));
        $this->assertSame('Renamed by owner data', $dismissed->refresh()->first_name);
    }

    public function test_a_full_sync_refreshes_names_but_a_dismissed_contact_stays_dismissed(): void
    {
        $user = $this->owner();
        $this->fakeSidecar([self::OLD]);
        app(TelegramSyncService::class)->sync($user);
        TelegramFriend::where('user_id', $user->id)->update(['status' => 'dismissed', 'first_name' => Crypt::encryptString('Stale')]);

        $this->fakeSidecar([self::OLD]);
        app(TelegramSyncService::class)->sync($user->refresh());

        $row = TelegramFriend::where('user_id', $user->id)->first();
        $this->assertSame('dismissed', $row->status);
        $this->assertSame('First01', $row->first_name);
    }

    public function test_a_revoked_session_is_forgotten_so_the_owner_logs_in_again(): void
    {
        $user = $this->owner();
        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake(['sidecar.test:8080/v1/contacts' => Http::response(['code' => 'auth_revoked', 'message' => 'The Telegram session is no longer valid.'], 401)]);

        $this->actingAs($user)->postJson('/settings/telegram/sync')
            ->assertStatus(422)->assertJson(['reauth' => true]);

        $user->refresh();
        $this->assertNull($user->telegram_session);
        $this->assertNull($user->telegram_account_name);
    }

    public function test_a_flood_wait_tells_the_owner_how_long_and_keeps_the_session(): void
    {
        $user = $this->owner();
        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake(['sidecar.test:8080/v1/contacts' => Http::response(['code' => 'flood_wait', 'message' => 'slow', 'retry_after' => 300], 429)]);

        $this->actingAs($user)->postJson('/settings/telegram/sync')
            ->assertStatus(422)->assertJsonPath('message', 'Telegram asked us to slow down. Try again in 5 minutes.');

        $this->assertSame(self::SESSION, $user->refresh()->telegram_session);
    }

    public function test_an_unreachable_sidecar_is_reported_without_leaking_the_session(): void
    {
        $user = $this->owner();
        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake(['sidecar.test:8080/v1/contacts' => fn () => throw new ConnectionException('cURL error 7 for http://sidecar.test:8080')]);

        $response = $this->actingAs($user)->postJson('/settings/telegram/sync')->assertStatus(422);

        $this->assertSame('The Telegram service could not be reached. Try again in a moment.', $response->json('message'));
        $this->assertStringNotContainsString(self::SESSION, $response->getContent());
        $this->assertSame(self::SESSION, $user->refresh()->telegram_session);
    }

    public function test_sync_needs_the_field_chosen(): void
    {
        $user = $this->owner();
        $user->forceFill(['telegram_user_id_definition_id' => null])->save();

        $this->actingAs($user)->postJson('/settings/telegram/sync')
            ->assertStatus(422)->assertJsonPath('message', 'Choose the Telegram user ID field in Settings first.');
        Http::assertNothingSent();
    }

    public function test_a_stale_list_queues_a_background_sync_once_and_a_fresh_or_unconfigured_one_does_not(): void
    {
        Queue::fake();
        $service = app(TelegramSyncService::class);

        $stale = $this->owner();
        $service->maybeQueue($stale);
        $service->maybeQueue($stale); // within the cooldown
        Queue::assertPushed(SyncTelegramContacts::class, 1);

        Queue::fake();
        $fresh = $this->owner();
        $fresh->forceFill(['telegram_synced_at' => now()->subMinutes(5)])->save();
        $service->maybeQueue($fresh);
        $service->maybeQueue($this->owner(connected: false));
        config(['services.telegram.sidecar_url' => null]);
        $service->maybeQueue($this->owner());
        Queue::assertNothingPushed();
    }

    public function test_the_connections_page_queues_the_sync(): void
    {
        Queue::fake();
        $user = $this->owner();

        $this->actingAs($user)->get(route('dashboard.connections'))->assertOk();

        Queue::assertPushed(SyncTelegramContacts::class);
    }

    public function test_pending_contacts_are_listed_and_completing_links_or_dismisses_them(): void
    {
        $user = $this->owner();
        $this->fakeSidecar([self::OLD, self::NEW]);
        app(TelegramSyncService::class)->sync($user);

        $friends = $this->actingAs($user)->getJson(route('dashboard.telegram.friends'))->assertOk()->json('friends');
        $this->assertSame([self::OLD, self::NEW], collect($friends)->pluck('telegram_user_id')->sort()->values()->all());
        $this->assertSame('First01 Last', collect($friends)->firstWhere('telegram_user_id', self::OLD)['name']);

        $ids = collect($friends)->pluck('id', 'telegram_user_id');
        $this->actingAs($user)->postJson(route('dashboard.telegram.friends.complete'), ['ids' => [$ids[self::OLD]], 'skipped' => [$ids[self::NEW]]])
            ->assertOk()->assertJson(['completed' => 1]);

        $this->assertSame([self::OLD => 'created', self::NEW => 'dismissed'], $this->statuses($user));
        $this->actingAs($user)->getJson(route('dashboard.telegram.friends'))->assertJson(['friends' => []]);
    }

    public function test_one_owner_cannot_complete_anothers_contacts(): void
    {
        $owner = $this->owner();
        $this->fakeSidecar([self::OLD]);
        app(TelegramSyncService::class)->sync($owner);
        $id = TelegramFriend::where('user_id', $owner->id)->value('id');

        $this->actingAs($this->owner())->postJson(route('dashboard.telegram.friends.complete'), ['ids' => [$id]])->assertOk()->assertJson(['completed' => 0]);

        $this->assertSame([self::OLD => 'pending'], $this->statuses($owner));
    }

    public function test_the_field_must_be_an_own_identifier_field_and_different_from_the_discord_and_vrchat_ones(): void
    {
        $user = $this->owner();
        $other = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'identifier_list',
        ]);
        $text = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'text',
        ]);
        $user->forceFill(['discord_user_id_definition_id' => $other->id])->save();

        $this->actingAs($user)->put('/settings/telegram', ['telegram_user_id_definition_id' => $other->id])->assertSessionHasErrors('telegram_user_id_definition_id');
        $this->actingAs($user)->put('/settings/telegram', ['telegram_user_id_definition_id' => $text->id])->assertSessionHasErrors('telegram_user_id_definition_id');
        $this->actingAs($user)->put('/settings/telegram', ['telegram_user_id_definition_id' => (string) Str::uuid()])->assertSessionHasErrors('telegram_user_id_definition_id');

        $this->actingAs($user)->put('/settings/telegram', ['telegram_user_id_definition_id' => null])->assertSessionDoesntHaveErrors();
        $this->assertNull($user->refresh()->telegram_user_id_definition_id);
    }

    public function test_the_chosen_field_cannot_be_deleted_while_selected(): void
    {
        $user = $this->owner();

        $this->assertArrayHasKey($user->telegram_user_id_definition_id, FieldFeatures::forUser($user));
    }

    public function test_disconnecting_logs_out_at_telegram_forgets_the_session_and_keeps_the_contacts(): void
    {
        $user = $this->owner();
        $factory = $this->fakeSidecar([self::OLD], ['sidecar.test:8080/v1/logout' => Http::response(['ok' => true])]);
        app(TelegramSyncService::class)->sync($user);

        $this->actingAs($user->refresh())->delete('/settings/telegram')->assertRedirect();

        $factory->assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/logout') && $r['session'] === 'rotated-session-2');
        $user->refresh();
        $this->assertNull($user->telegram_session);
        $this->assertSame([self::OLD => 'pending'], $this->statuses($user));
    }

    public function test_the_summary_gives_each_connection_its_linked_contacts(): void
    {
        $user = $this->owner();
        $linked = $this->connectionWith($user, self::LINKED);
        $stranger = $this->connectionWith($user, '1000000077'); // not one of the contacts
        $this->fakeSidecar([self::OLD, self::LINKED]);
        app(TelegramSyncService::class)->sync($user);

        $summary = app(TelegramSyncService::class)->summary($user->refresh());

        $this->assertTrue($summary['available'] && $summary['connected'] && $summary['configured']);
        $this->assertSame(1, $summary['pending_friends']);
        $this->assertEqualsCanonicalizing([$linked->id, $stranger->id], array_keys($summary['profiles']));
        $this->assertSame('First02 Last', $summary['profiles'][$linked->id][0]['name']);
        $this->assertFalse($summary['profiles'][$stranger->id][0]['known']);
    }

    public function test_the_export_includes_contacts_and_the_account_name_but_never_the_session(): void
    {
        $user = $this->owner();
        $this->fakeSidecar([self::OLD]);
        app(TelegramSyncService::class)->sync($user);

        $files = [];
        $zip = \Mockery::mock(ZipStream::class);
        $zip->shouldReceive('addFile')->andReturnUsing(function (string $name, string $data) use (&$files) {
            $files[$name] = $data;
        });
        app(AccountExportService::class)->build($zip, $user->refresh());

        $contacts = json_decode($files['connections/telegram-friends.json'], true);
        $this->assertSame('server-decrypted', $contacts['tier']);
        $this->assertSame(self::OLD, $contacts['records'][0]['telegram_user_id']);
        $this->assertSame('+306900000001', $contacts['records'][0]['phone']);
        $profile = json_decode($files['account/profile.json'], true)['records'][0];
        $this->assertSame('Owner', $profile['telegram_account_name']);
        $this->assertTrue($profile['telegram_connected']);

        foreach ($files as $contents) {
            $this->assertStringNotContainsString(self::SESSION, $contents);
            $this->assertStringNotContainsString('rotated-session', $contents);
        }
    }

    public function test_the_session_never_appears_in_the_settings_page_props(): void
    {
        $user = $this->owner();

        $props = $this->actingAs($user)->get(route('settings.edit'))->assertOk()->viewData('page')['props'];

        $this->assertSame(['available' => true, 'connected' => true, 'account_name' => 'Owner', 'synced_at' => null, 'user_id_definition_id' => $user->telegram_user_id_definition_id], $props['telegram']);
        $this->assertStringNotContainsString(self::SESSION, json_encode($props));
    }
}
