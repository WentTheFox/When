<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Calendar\CalendarUrlGuard;
use App\Services\Calendar\UnsafeCalendarUrlException;
use App\Services\Vrcx\VrcxClient;
use App\Services\Vrcx\VrcxException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The owner supplies the VRCX server's URL and the app calls it, so it gets the same SSRF guard as calendar URLs. */
class VrcxSsrfTest extends TestCase
{
    use RefreshDatabase;

    private function resolveEverythingTo(string $ip, bool $allowPrivate = false): void
    {
        $this->app->bind(CalendarUrlGuard::class, fn () => new CalendarUrlGuard(fn () => [$ip], $allowPrivate));
    }

    public function test_settings_reject_a_vrcx_url_that_points_at_a_private_address(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings')
            ->patch('/settings/vrcx', ['vrcx_base_url' => 'http://127.0.0.1:8080'])
            ->assertSessionHasErrors('vrcx_base_url');

        $this->assertNull($user->fresh()->vrcx_base_url);
        Http::assertNothingSent();
    }

    public function test_settings_reject_a_hostname_that_resolves_to_a_private_address(): void
    {
        Http::fake();
        $this->resolveEverythingTo('10.0.0.5');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings')
            ->patch('/settings/vrcx', ['vrcx_base_url' => 'https://vrcx.example.test'])
            ->assertSessionHasErrors('vrcx_base_url');

        Http::assertNothingSent();
    }

    public function test_the_client_never_sends_a_request_to_a_private_address(): void
    {
        Http::fake();
        $this->resolveEverythingTo('169.254.169.254');

        $client = new VrcxClient('https://vrcx.example.test', 'key');

        try {
            $client->users(['usr_00000000-0000-0000-0000-000000000000']);
            $this->fail('Expected the guard to refuse the address.');
        } catch (VrcxException $e) {
            $this->assertStringNotContainsString('vrcx.example.test', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_compatibility_probe_is_guarded_too(): void
    {
        Http::fake();
        $this->resolveEverythingTo('192.168.1.10');

        $this->expectException(VrcxException::class);

        try {
            VrcxClient::assertCompatible('https://vrcx.example.test');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_public_address_is_still_allowed(): void
    {
        Http::fake(['*' => Http::response(['users' => []])]);

        (new VrcxClient('https://vrcx.example.test', 'key'))->users(['usr_00000000-0000-0000-0000-000000000000']);

        Http::assertSentCount(1);
    }

    public function test_the_dev_allowance_lets_a_local_mock_through(): void
    {
        Http::fake(['*' => Http::response(['users' => []])]);
        $this->resolveEverythingTo('127.0.0.1', allowPrivate: true);

        (new VrcxClient('http://when.test/dev/mock-vrcx', 'key'))->users(['usr_00000000-0000-0000-0000-000000000000']);

        Http::assertSentCount(1);
    }

    public function test_an_internet_reachable_server_on_a_port_of_its_own_is_allowed(): void
    {
        Http::fake(['*' => Http::response(['users' => []])]);

        (new VrcxClient('https://vrcx.example.test:8443', 'key'))->users(['usr_00000000-0000-0000-0000-000000000000']);

        Http::assertSentCount(1);
    }

    public function test_a_port_of_its_own_does_not_make_a_private_address_acceptable(): void
    {
        Http::fake();
        $this->resolveEverythingTo('10.0.0.5');

        $this->expectException(VrcxException::class);

        try {
            (new VrcxClient('https://vrcx.example.test:8443', 'key'))->ping();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_settings_accept_a_public_vrcx_url_with_its_own_port(): void
    {
        Http::fake(['*' => Http::response(['openapi' => '3.0.0', 'info' => ['title' => 'VRCX', 'version' => '1'], 'paths' => [
            '/api/v1/users' => ['post' => []], '/api/v1/time-together' => ['post' => []],
        ]])]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/vrcx', ['vrcx_base_url' => 'https://vrcx.example.test:8443'])
            ->assertSessionHasNoErrors();

        $this->assertSame('https://vrcx.example.test:8443', $user->fresh()->vrcx_base_url);
    }

    public function test_the_dev_switch_is_ignored_in_production(): void
    {
        config(['services.calendar.allow_private_hosts' => true]);
        $this->app['env'] = 'production';

        $this->expectException(UnsafeCalendarUrlException::class);

        (new CalendarUrlGuard(fn () => ['127.0.0.1']))->inspect('http://vrcx.example.test/');
    }
}
