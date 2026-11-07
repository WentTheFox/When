<?php

namespace App\Services\Calendar;

use Closure;

/**
 * SSRF guard for owner-supplied calendar URLs. The server fetches these on the owner's behalf
 * (§0.2), so without a guard an owner could point it at localhost, the cloud metadata endpoint,
 * or anything else on the internal network.
 *
 * The guard resolves the host itself and returns the vetted IPs so the fetcher can pin the
 * connection to them (CURLOPT_RESOLVE). Validating a hostname and then letting the HTTP client
 * resolve it again would leave a DNS-rebinding window between the two lookups.
 *
 * Never put the URL or host in an exception message (§0.2).
 */
class CalendarUrlGuard
{
    /** Blocked unless $allowPrivateHosts: loopback, private, link-local, CGNAT, reserved, multicast, etc. */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const BLOCKED_V6 = [
        '::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    private readonly bool $allowPrivateHosts;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  host => IP list; injectable so tests need no DNS
     */
    public function __construct(?Closure $resolver = null, ?bool $allowPrivateHosts = null)
    {
        $this->resolver = $resolver ?? $this->resolveViaDns(...);
        $this->allowPrivateHosts = $allowPrivateHosts ?? (bool) config('services.calendar.allow_private_hosts', false);
    }

    /**
     * @return array{host: string, port: int, ips: list<string>}
     *
     * @throws UnsafeCalendarUrlException
     */
    public function inspect(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeCalendarUrlException;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeCalendarUrlException;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! $this->allowPrivateHosts && ! in_array($port, [80, 443], true)) {
            throw new UnsafeCalendarUrlException;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $ips = $this->resolveHost($host);

        if ($ips === []) {
            throw new UnsafeCalendarUrlException;
        }

        // Every address must be public: a host with one public and one private record is rejected,
        // since the client could otherwise be handed the private one.
        if (! $this->allowPrivateHosts) {
            foreach ($ips as $ip) {
                if (! $this->isPublic($ip)) {
                    throw new UnsafeCalendarUrlException;
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /** @throws UnsafeCalendarUrlException */
    public function assertSafe(string $url): void
    {
        $this->inspect($url);
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // Odd numeric forms ("2130706433", "0x7f.1", "017700000001") are IPv4 addresses to
        // inet_aton-style resolvers but fail FILTER_VALIDATE_IP, so refuse them outright.
        if (preg_match('/^[0-9a-fx.]+$/i', $host) === 1 && preg_match('/[0-9]/', $host) === 1 && ! str_contains($host, '-')) {
            if (preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/i', $host) === 1) {
                throw new UnsafeCalendarUrlException;
            }
        }

        return array_values(array_unique(($this->resolver)($host)));
    }

    /** @return list<string> */
    private function resolveViaDns(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    private function isPublic(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d): judge the embedded IPv4 address.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        $ranges = strlen($packed) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6;
        foreach ($ranges as $cidr) {
            if ($this->inRange($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function inRange(string $packedIp, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $packedSubnet = inet_pton($subnet);
        if ($packedSubnet === false) {
            return false;
        }
        $bits = (int) $bits;

        $fullBytes = intdiv($bits, 8);
        if (strncmp($packedIp, $packedSubnet, $fullBytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packedIp[$fullBytes]) & $mask) === (ord($packedSubnet[$fullBytes]) & $mask);
    }
}
