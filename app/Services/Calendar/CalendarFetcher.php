<?php

namespace App\Services\Calendar;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fetches raw ICS text over HTTP. §0.2: the URL and the response body must
 * NEVER be logged or included in an exception message — Guzzle's own
 * exceptions embed the request URI by default, so those are caught and
 * replaced with a sanitized one rather than left to propagate.
 */
class CalendarFetcher
{
    public function __construct(private readonly Client $client) {}

    public function fetch(string $calendarUrl): string
    {
        try {
            $response = $this->client->get($calendarUrl, [
                'timeout' => 15,
                'headers' => ['Accept' => 'text/calendar, text/plain, */*'],
            ]);
        } catch (GuzzleException) {
            throw new CalendarFetchException;
        }

        if ($response->getStatusCode() >= 400) {
            throw new CalendarFetchException;
        }

        return (string) $response->getBody();
    }
}
