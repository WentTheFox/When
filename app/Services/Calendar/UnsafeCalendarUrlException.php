<?php

namespace App\Services\Calendar;

/**
 * The calendar URL (or a redirect target) points somewhere the server must not connect to.
 * A CalendarFetchException so every existing catch block handles it; like its parent it
 * carries no URL or host (§0.2).
 */
class UnsafeCalendarUrlException extends CalendarFetchException {}
