<?php

namespace Tests\Unit;

use App\Services\Calendar\CalendarUrlGuard;
use App\Services\Calendar\UnsafeCalendarUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalendarUrlGuardTest extends TestCase
{
    private function guard(array $ips = ['93.184.216.34'], bool $allowPrivate = false): CalendarUrlGuard
    {
        return new CalendarUrlGuard(fn () => $ips, $allowPrivate);
    }

    public function test_a_public_https_url_passes_and_returns_the_vetted_ips(): void
    {
        $result = $this->guard()->inspect('https://calendar.example.com/a.ics');

        $this->assertSame(['host' => 'calendar.example.com', 'port' => 443, 'ips' => ['93.184.216.34']], $result);
    }

    #[DataProvider('blockedUrls')]
    public function test_it_rejects_dangerous_urls(string $url): void
    {
        $this->expectException(UnsafeCalendarUrlException::class);
        $this->guard()->inspect($url);
    }

    public static function blockedUrls(): array
    {
        return [
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://example.com/'],
            'no host' => ['http:///a.ics'],
            'loopback' => ['http://127.0.0.1/a.ics'],
            'loopback v6' => ['http://[::1]/a.ics'],
            'private 10/8' => ['http://10.1.2.3/a.ics'],
            'private 192.168' => ['http://192.168.0.1/a.ics'],
            'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/'],
            'cgnat' => ['http://100.64.0.1/a.ics'],
            'v4-mapped v6 loopback' => ['http://[::ffff:127.0.0.1]/a.ics'],
            'unique local v6' => ['http://[fd00::1]/a.ics'],
            'decimal ip' => ['http://2130706433/a.ics'],
            'hex ip' => ['http://0x7f.0.0.1/a.ics'],
            'non-standard port' => ['http://calendar.example.com:6379/a.ics'],
        ];
    }

    public function test_a_hostname_resolving_to_a_private_address_is_rejected(): void
    {
        $this->expectException(UnsafeCalendarUrlException::class);
        $this->guard(['93.184.216.34', '10.0.0.5'])->inspect('https://rebind.example.com/a.ics');
    }

    public function test_an_unresolvable_host_is_rejected(): void
    {
        $this->expectException(UnsafeCalendarUrlException::class);
        $this->guard([])->inspect('https://nope.example.com/a.ics');
    }

    public function test_private_hosts_and_ports_are_allowed_when_explicitly_enabled(): void
    {
        $result = $this->guard(['127.0.0.1'], true)->inspect('http://localhost:8000/dev/mock-calendar.ics');

        $this->assertSame(8000, $result['port']);
    }
}
