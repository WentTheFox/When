<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Discord\DiscordException;
use App\Services\Discord\DiscordSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background Discord friends sync for one owner, queued by
 * DiscordSyncService::maybeQueue when a Connections page load finds the list
 * stale. It only adds people not tracked yet (new friends, newly blocked accounts):
 * it never refreshes anyone's name or avatar. A failure is logged (message only, never a token) and not retried: the
 * next page load after the cooldown tries again.
 */
class SyncDiscordFriends implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(private readonly string $userId) {}

    public function handle(DiscordSyncService $service): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        try {
            $service->sync($user, onlyNew: true);
        } catch (DiscordException $e) {
            Log::warning('Discord sync failed: '.$e->getMessage());
        }
    }
}
