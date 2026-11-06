<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Vrcx\VrcxException;
use App\Services\Vrcx\VrcxSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background VRCX sync for one owner, queued by VrcxSyncService::maybeQueue
 * when a Connections page load finds their data stale. A failure is logged
 * (message only, never the key or URL) and not retried: the next page load
 * after the cooldown tries again.
 */
class SyncVrcxData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(private readonly string $userId) {}

    public function handle(VrcxSyncService $service): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        try {
            $service->sync($user);

            foreach ($service->warnings() as $warning) {
                Log::warning('VRCX sync: '.$warning);
            }
        } catch (VrcxException $e) {
            Log::warning('VRCX sync failed: '.$e->getMessage());
        }
    }
}
