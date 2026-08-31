<?php

namespace App\Services\Calendar;

/**
 * Deliberately carries no message referencing the URL or response body —
 * §0.2 requires those never appear in logs or exception reports.
 */
class CalendarFetchException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Failed to fetch the calendar feed.');
    }
}
