<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Deploy-window flag: while set, the app serves reads but rejects writes, so new code can go
 * live before its migrations have been applied (see docs/zero-downtime-deploy.md). Stored as a
 * file so it spans requests and workers and doesn't need the database, which is the thing being
 * migrated. deploy.conf raises it with a plain `touch` of the same path.
 */
class ReadOnlyMode
{
    public const RETRY_AFTER_SECONDS = 5;

    public static function path(): string
    {
        return storage_path('framework/readonly');
    }

    public static function isActive(): bool
    {
        return File::exists(self::path());
    }

    public static function enable(): void
    {
        File::put(self::path(), (string) time());
    }

    public static function disable(): void
    {
        File::delete(self::path());
    }
}
