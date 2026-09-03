<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ShareLink;
use App\Models\SleepException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DELETE /dashboard/account — see App\Services\Account\AccountDeletionService
 * for the actual cascade walk and App\Http\Controllers\Dashboard\
 * AccountDeletionController for the confirmation/logout wiring. Deletion is
 * final from the owner's own perspective (immediate logout, no way back in
 * to cancel it) — see the controller's own doc comment.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A soft-deleted row is invisible to ->fresh() (it re-queries through
     * the model's own global SoftDeletes scope, same as any other query) —
     * this reads deleted_at straight off the table instead, so a test can
     * assert on it regardless of whether the row is expected to be hidden.
     */
    private function deletedAtOf(string $table, string $id): ?string
    {
        return DB::table($table)->where('id', $id)->value('deleted_at');
    }

    public function test_guests_cannot_delete_an_account(): void
    {
        $this->delete('/dashboard/account')->assertRedirect('/login');
    }

    public function test_missing_password_is_rejected_and_nothing_is_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/dashboard/account')
            ->assertSessionHasErrors('password');

        $this->assertNull($user->fresh()->deleted_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected_and_nothing_is_deleted(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-verifier')]);

        $this->actingAs($user)
            ->delete('/dashboard/account', ['password' => 'wrong-verifier'])
            ->assertSessionHasErrors('password');

        $this->assertNull($user->fresh()->deleted_at);
    }

    public function test_correct_password_soft_deletes_the_account_and_logs_out_immediately(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-verifier')]);

        $response = $this->actingAs($user)
            ->delete('/dashboard/account', ['password' => 'correct-verifier']);

        $response->assertRedirect('/');
        $this->assertGuest();
        $this->assertNotNull($this->deletedAtOf('users', $user->id));
    }

    public function test_deletion_soft_deletes_owned_share_links_and_connections(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-verifier')]);
        $shareLink = ShareLink::factory()->for($user)->create();
        $connection = Connection::create(['user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        $sleepException = SleepException::create([
            'user_id' => $user->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-02',
        ]);

        $this->actingAs($user)->delete('/dashboard/account', ['password' => 'correct-verifier']);

        $this->assertNotNull($this->deletedAtOf('share_links', $shareLink->id));
        $this->assertNotNull($this->deletedAtOf('connections', $connection->id));
        $this->assertNotNull($this->deletedAtOf('sleep_exceptions', $sleepException->id));
    }

    public function test_deletion_deletes_the_users_sessions_outright(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-verifier')]);
        DB::table('sessions')->insert([
            'id' => 'test-session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('irrelevant'),
            'last_activity' => time(),
        ]);

        $this->actingAs($user)->delete('/dashboard/account', ['password' => 'correct-verifier']);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_a_soft_deleted_account_can_no_longer_log_in(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-verifier'), 'email' => 'deleted@example.com']);

        $this->actingAs($user)->delete('/dashboard/account', ['password' => 'correct-verifier']);

        $response = $this->post('/login', [
            'identifier' => 'deleted@example.com',
            'password' => 'correct-verifier',
        ]);

        $response->assertSessionHasErrors('identifier');
        $this->assertGuest();
    }

    public function test_cannot_delete_another_owners_account(): void
    {
        $owner = User::factory()->create(['password' => bcrypt('correct-verifier')]);
        $stranger = User::factory()->create(['password' => bcrypt('stranger-verifier')]);

        // A stranger confirming with THEIR OWN correct password only ever
        // deletes their own account (ConfirmsPassword checks $request->user(),
        // never a record supplied by the caller) — there is no "delete this
        // other id" surface to even attempt against.
        $this->actingAs($stranger)->delete('/dashboard/account', ['password' => 'stranger-verifier']);

        $this->assertNull($this->deletedAtOf('users', $owner->id));
        $this->assertNotNull($this->deletedAtOf('users', $stranger->id));
    }
}
