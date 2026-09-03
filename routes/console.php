<?php

use App\Jobs\HardDeleteExpiredAccounts;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Second half of self-service account deletion — see
// App\Services\Account\AccountDeletionService's own doc comment.
Schedule::job(new HardDeleteExpiredAccounts)->hourly();
