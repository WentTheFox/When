<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Controller;
use App\Services\Account\AccountExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\ZipStream;

/**
 * Streams a zip of everything tied to the caller's account — built and
 * written directly to the HTTP response as it goes (ZipStream defaults to
 * php://output), never buffered whole in memory or written to a real file.
 * See AccountExportService for what's actually in it. Rate-limited to 5/day
 * (see the 'account-data-export' limiter in AppServiceProvider) since it's
 * the heaviest self-service action a caller can trigger.
 */
class AccountExportController extends Controller
{
    use ConfirmsPassword;

    public function store(Request $request, AccountExportService $service): StreamedResponse
    {
        $this->confirmPassword($request);

        $user = $request->user();
        $filename = 'when-export-'.now()->format('Y-m-d').'.zip';

        return response()->streamDownload(function () use ($service, $user, $filename) {
            // Laravel's streamDownload() already sends Content-Type/
            // Content-Disposition — ZipStream must not also try to (it
            // would emit a second, conflicting set of headers).
            $zip = new ZipStream(outputName: $filename, sendHttpHeaders: false);
            $service->build($zip, $user);
            $zip->finish();
        }, $filename, ['Content-Type' => 'application/zip']);
    }
}
