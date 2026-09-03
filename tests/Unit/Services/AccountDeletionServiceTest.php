<?php

namespace Tests\Unit\Services;

use App\Models\ActivityLocalization;
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
use App\Models\ShareLinkCache;
use App\Models\ShareLinkWord;
use App\Models\SleepException;
use App\Models\User;
use App\Services\Account\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Directly exercises AccountDeletionService against a fully-seeded user
 * (one row in every table that hangs off users, per the model in
 * database/migrations/*_add_soft_deletes_to_account_owned_tables.php) — the
 * place most likely to catch a table the cascade walk forgot.
 */
class AccountDeletionServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A soft-deleted row is invisible to ->fresh() (it re-queries through
     * the model's own global SoftDeletes scope, same as any other query) —
     * this reads deleted_at straight off the table instead.
     */
    private function deletedAtOf(string $table, string $id): ?string
    {
        return DB::table($table)->where('id', $id)->value('deleted_at');
    }

    private function seedFullGraph(User $user): array
    {
        $shareLink = ShareLink::factory()->for($user)->create();
        ShareLinkWord::create(['share_link_id' => $shareLink->id, 'word_ciphertext' => 'opaque']);
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'opaque',
            'computed_range_start' => now(),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now(),
        ]);

        $sleepException = SleepException::create([
            'user_id' => $user->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-02',
        ]);

        $activityLocalization = ActivityLocalization::create([
            'user_id' => $user->id,
            'pattern' => '^host\s+(.+)$',
            'sort_order' => 0,
        ]);
        // label isn't mass-assignable — see HasLocalizedFields' own doc
        // comment; set it directly instead.
        $activityLocalization->setLocalizedField('label', ['default' => 'Visiting']);

        $category = ConnectionSourceCategory::create(['user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        $source = ConnectionSource::create(['user_id' => $user->id, 'category_id' => $category->id, 'name_ciphertext' => 'opaque']);
        $connection = Connection::create(['user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        $connection->sources()->attach($source->id);

        $definition = ConnectionAttributeDefinition::create(['user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'text']);
        $attributeValue = ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $definition->id, 'value_ciphertext' => 'opaque']);

        $otherConnection = Connection::create(['user_id' => $user->id, 'name_ciphertext' => 'opaque-2']);
        $edge = ConnectionEdge::create(['user_id' => $user->id, 'from_connection_id' => $connection->id, 'to_connection_id' => $otherConnection->id]);

        $invitee = User::factory()->create();
        $invite = Invite::create(['inviter_user_id' => $user->id, 'code' => 'SEED-'.$user->id]);
        $redemption = InviteRedemption::create(['invite_id' => $invite->id, 'user_id' => $invitee->id, 'redeemed_at' => now()]);

        $detection = CalendarDetection::create(['user_id' => $user->id, 'detected_mode' => 'full_detail', 'fetched_at' => now()]);

        DB::table('sessions')->insert([
            'id' => 'seed-session-'.$user->id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('irrelevant'),
            'last_activity' => time(),
        ]);

        return compact(
            'shareLink', 'sleepException', 'activityLocalization', 'category', 'source',
            'connection', 'definition', 'attributeValue', 'otherConnection', 'edge',
            'invite', 'redemption', 'detection',
        );
    }

    public function test_soft_delete_touches_every_table_in_the_graph(): void
    {
        $user = User::factory()->create();
        $rows = $this->seedFullGraph($user);

        app(AccountDeletionService::class)->softDelete($user);

        $this->assertNotNull($this->deletedAtOf('users', $user->id));
        $this->assertNotNull($this->deletedAtOf('share_links', $rows['shareLink']->id));
        $this->assertNotNull($this->deletedAtOf('sleep_exceptions', $rows['sleepException']->id));
        $this->assertNotNull($this->deletedAtOf('activity_localizations', $rows['activityLocalization']->id));
        $this->assertNotNull($this->deletedAtOf('connection_source_categories', $rows['category']->id));
        $this->assertNotNull($this->deletedAtOf('connection_sources', $rows['source']->id));
        $this->assertNotNull($this->deletedAtOf('connections', $rows['connection']->id));
        $this->assertNotNull($this->deletedAtOf('connections', $rows['otherConnection']->id));
        $this->assertNotNull($this->deletedAtOf('connection_attribute_definitions', $rows['definition']->id));
        $this->assertNotNull($this->deletedAtOf('connection_edges', $rows['edge']->id));
        $this->assertNotNull($this->deletedAtOf('invites', $rows['invite']->id));
        $this->assertNotNull($this->deletedAtOf('invite_redemptions', $rows['redemption']->id));
        $this->assertNotNull($this->deletedAtOf('calendar_detections', $rows['detection']->id));

        // Deleted outright, no grace period, never had a deleted_at column.
        $this->assertSame(0, ShareLinkCache::where('share_link_id', $rows['shareLink']->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());

        // No FK/deleted_at of their own — reached only through their
        // now-soft-deleted parents, still physically present.
        $this->assertNotNull(ConnectionAttributeValue::find($rows['attributeValue']->id));
    }

    public function test_hard_delete_removes_the_user_row_and_cascades(): void
    {
        $user = User::factory()->create();
        $rows = $this->seedFullGraph($user);

        $service = app(AccountDeletionService::class);
        $service->softDelete($user);
        $service->hardDelete($user->id);

        $this->assertNull(User::withTrashed()->find($user->id));
        $this->assertNull(ShareLink::withTrashed()->find($rows['shareLink']->id));
        $this->assertNull(Connection::withTrashed()->find($rows['connection']->id));
        $this->assertSame(0, ConnectionAttributeValue::where('id', $rows['attributeValue']->id)->count());
        $this->assertSame(
            0,
            DB::table('connection_source_links')->where('connection_id', $rows['connection']->id)->count(),
        );
    }

    public function test_hard_delete_cleans_up_localized_texts_which_have_no_fk(): void
    {
        $user = User::factory()->create();
        $activityLocalization = ActivityLocalization::create([
            'user_id' => $user->id,
            'pattern' => '^host\s+(.+)$',
            'sort_order' => 0,
        ]);
        // label isn't mass-assignable (HasLocalizedFields' documented
        // "call setLocalizedField() directly" pattern — passing it via
        // create() silently no-ops).
        $activityLocalization->setLocalizedField('label', ['default' => 'Visiting']);
        $user->setLocalizedField('public_page_title', ['default' => 'My calendar']);

        $userTextCount = DB::table('localized_texts')
            ->where(['localizable_type' => User::class, 'localizable_id' => $user->id])
            ->count();
        $roleTextCount = DB::table('localized_texts')
            ->where(['localizable_type' => ActivityLocalization::class, 'localizable_id' => $activityLocalization->id])
            ->count();
        $this->assertGreaterThan(0, $userTextCount);
        $this->assertGreaterThan(0, $roleTextCount);

        $service = app(AccountDeletionService::class);
        $service->softDelete($user);
        $service->hardDelete($user->id);

        $this->assertSame(
            0,
            DB::table('localized_texts')->where(['localizable_type' => User::class, 'localizable_id' => $user->id])->count(),
        );
        $this->assertSame(
            0,
            DB::table('localized_texts')->where(['localizable_type' => ActivityLocalization::class, 'localizable_id' => $activityLocalization->id])->count(),
        );
    }

    public function test_hard_delete_is_a_no_op_for_a_user_that_is_not_soft_deleted(): void
    {
        $user = User::factory()->create();

        app(AccountDeletionService::class)->hardDelete($user->id);

        $this->assertNotNull(User::find($user->id));
    }
}
