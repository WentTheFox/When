<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\TelegramSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background Telegram contacts sync for one owner, queued by TelegramSyncService::maybeQueue when a Connections
 * page load finds the list stale. It only adds people not tracked yet: it never refreshes anyone's name, phone or
 * photo. A failure is logged (message only, never the session, phone or a code) and not retried: the next page
 * load after the cooldown tries again.
 */
class SyncTelegramContacts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(private readonly string $userId) {}

    public function handle(TelegramSyncService $service): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        try {
            $service->sync($user, onlyNew: true);
        } catch (TelegramException $e) {
            Log::warning('Telegram sync failed: '.$e->getMessage());
        }
    }
}
