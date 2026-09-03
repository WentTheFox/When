<?php

namespace Tests\Unit\Jobs;

use App\Jobs\HardDeleteExpiredAccounts;
use App\Models\User;
use App\Services\Account\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scheduled hourly (routes/console.php) against a 48h soft-delete grace
 * period — see App\Services\Account\AccountDeletionService's own doc
 * comment for why hourly is comfortably tight relative to that window.
 */
class HardDeleteExpiredAccountsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * deleted_at is deliberately not in User::$fillable (see
     * AccountDeletionService::softDelete()'s own comment on this exact
     * trap) — forceFill() bypasses that mass-assignment guard, unlike a
     * plain update()/fill(), which would silently no-op here.
     */
    private function softDeleteAt(User $user, \DateTimeInterface $when): void
    {
        $user->forceFill(['deleted_at' => $when])->save();
    }

    public function test_a_user_soft_deleted_less_than_48_hours_ago_is_untouched(): void
    {
        $user = User::factory()->create();
        $this->softDeleteAt($user, now()->subHours(47));

        (new HardDeleteExpiredAccounts)->handle(app(AccountDeletionService::class));

        $this->assertNotNull(User::withTrashed()->find($user->id));
    }

    public function test_a_user_soft_deleted_at_least_48_hours_ago_is_hard_deleted(): void
    {
        $user = User::factory()->create();
        $this->softDeleteAt($user, now()->subHours(48));

        (new HardDeleteExpiredAccounts)->handle(app(AccountDeletionService::class));

        $this->assertNull(User::withTrashed()->find($user->id));
    }

    public function test_a_user_that_is_not_soft_deleted_at_all_is_untouched(): void
    {
        $user = User::factory()->create();

        (new HardDeleteExpiredAccounts)->handle(app(AccountDeletionService::class));

        $this->assertNotNull(User::find($user->id));
    }

    public function test_multiple_expired_accounts_are_all_purged_in_one_run(): void
    {
        $first = User::factory()->create();
        $this->softDeleteAt($first, now()->subHours(72));
        $second = User::factory()->create();
        $this->softDeleteAt($second, now()->subHours(49));
        $stillWithinGrace = User::factory()->create();
        $this->softDeleteAt($stillWithinGrace, now()->subHours(1));

        (new HardDeleteExpiredAccounts)->handle(app(AccountDeletionService::class));

        $this->assertNull(User::withTrashed()->find($first->id));
        $this->assertNull(User::withTrashed()->find($second->id));
        $this->assertNotNull(User::withTrashed()->find($stillWithinGrace->id));
    }
}
