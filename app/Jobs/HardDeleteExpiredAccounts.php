<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Account\AccountDeletionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled hourly (routes/console.php) — the second half of self-service
 * account deletion (App\Services\Account\AccountDeletionService::softDelete()
 * runs synchronously at deletion time; this permanently erases what's been
 * soft-deleted for at least 48h). Hourly is comfortably tight relative to
 * that 48h SLA, so a missed/delayed run is never a real problem.
 */
class HardDeleteExpiredAccounts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AccountDeletionService $service): void
    {
        User::onlyTrashed()
            ->where('deleted_at', '<=', now()->subHours(48))
            ->pluck('id')
            ->each(fn (string $id) => $service->hardDelete($id));
    }
}
