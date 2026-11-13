<?php

namespace App\Services\Calendar;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches raw ICS text over HTTP. §0.2: the URL and the response body must
 * NEVER be logged or included in an exception message — Guzzle's own
 * exceptions embed the request URI by default, so those are caught and
 * replaced with a sanitized one rather than left to propagate.
 *
 * SSRF: every hop (the initial URL and each redirect) goes through CalendarUrlGuard,
 * and the connection is pinned to the IPs the guard vetted so the client can't
 * re-resolve to a different address. Redirects are therefore followed here, not by Guzzle.
 */
class CalendarFetcher
{
    public function __construct(
        private readonly Client $client,
        private readonly CalendarUrlGuard $guard,
    ) {}

    private function setting(string $key): int
    {
        return (int) config("services.calendar.$key");
    }

    public function fetch(string $calendarUrl): string
    {
        $url = $calendarUrl;
        $max = $this->setting('max_body_bytes');

        for ($hop = 0; $hop <= $this->setting('max_redirects'); $hop++) {
            $target = $this->guard->inspect($url);

            try {
                $response = $this->client->get($url, [
                    'connect_timeout' => $this->setting('connect_timeout'),
                    'timeout' => $this->setting('timeout'),
                    // Abort as soon as the declared or actually-received size passes the cap, instead of
                    // buffering an unbounded body into memory first. Throwing here surfaces as a GuzzleException.
                    'on_headers' => function (mixed $r) use ($max): void {
                        // (Guzzle's MockHandler can pass a queued response factory instead of a response.)
                        if ($r instanceof ResponseInterface && (int) $r->getHeaderLine('Content-Length') > $max) {
                            throw new CalendarFetchException;
                        }
                    },
                    'progress' => function (int $dlTotal, int $dlNow) use ($max): void {
                        if ($dlNow > $max) {
                            throw new CalendarFetchException;
                        }
                    },
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'headers' => ['Accept' => 'text/calendar, text/plain, */*'],
                    'curl' => [
                        CURLOPT_RESOLVE => CalendarUrlGuard::curlResolveEntries($target),
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    ],
                ]);
            } catch (GuzzleException) {
                throw new CalendarFetchException;
            }

            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
                $url = (string) UriResolver::resolve(
                    Utils::uriFor($url),
                    Utils::uriFor($response->getHeaderLine('Location')),
                );

                continue;
            }

            if ($status >= 300) {
                throw new CalendarFetchException;
            }

            // Final backstop for handlers that don't report progress.
            $body = $response->getBody();
            if (($body->getSize() ?? 0) > $max) {
                throw new CalendarFetchException;
            }

            $contents = $body->read($max + 1);
            if (strlen($contents) > $max) {
                throw new CalendarFetchException;
            }

            return $contents;
        }

        throw new CalendarFetchException;
    }
}
