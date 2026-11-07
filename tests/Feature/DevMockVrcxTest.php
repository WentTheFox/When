<?php

namespace Tests\Feature;

use App\Services\Calendar\CalendarUrlGuard;
use App\Services\Vrcx\VrcxClient;
use App\Support\DevMockVrchat;
use App\Support\VrchatUserId;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The dev mock VRCX server has to speak exactly the API VrcxClient calls, or the demo account's sync breaks. These
 * run the real client against the mock (Http::fake routes its requests into the app), so they fail if either side drifts.
 */
class DevMockVrcxTest extends TestCase
{
    private const BASE = 'http://when.test/dev/mock-vrcx';

    protected function setUp(): void
    {
        parent::setUp();

        // The mock lives on loopback, which the guard only allows with the dev switch.
        $this->app->bind(CalendarUrlGuard::class, fn () => new CalendarUrlGuard(fn () => ['127.0.0.1'], true));

        Http::fake(function (Request $request) {
            $response = $this->withHeaders($request->headers() === [] ? [] : array_map(fn (array $v) => $v[0], $request->headers()))
                ->json($request->method(), (string) parse_url($request->url(), PHP_URL_PATH), $request->method() === 'GET'
                    ? []
                    : $request->data());

            return Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
        });
    }

    public function test_the_mock_passes_the_clients_compatibility_check_including_friends(): void
    {
        VrcxClient::assertCompatible(self::BASE, ['/api/v1/friends' => 'get']);

        $this->assertTrue(true);
    }

    public function test_the_client_reads_profiles_stays_and_friends_from_the_mock(): void
    {
        $client = new VrcxClient(self::BASE, DevMockVrchat::API_KEY);
        $ids = [DevMockVrchat::userId(1), DevMockVrchat::userId(3), 'usr_ffffffff-ffff-ffff-ffff-ffffffffffff'];

        $users = $client->users($ids);
        $this->assertSame([DevMockVrchat::userId(1), DevMockVrchat::userId(3)], array_keys($users), 'unknown users are left out');
        $this->assertSame(DevMockVrchat::profile(1)['displayName'], $users[DevMockVrchat::userId(1)]['displayName']);
        $this->assertNull($users[DevMockVrchat::userId(1)]['discordId']);
        $this->assertSame(DevMockVrchat::discordId(3), $users[DevMockVrchat::userId(3)]['discordId']);

        $this->assertNull($client->user('usr_ffffffff-ffff-ffff-ffff-ffffffffffff'));
        $this->assertSame(DevMockVrchat::profile(2)['note'], $client->user(DevMockVrchat::userId(2))['note']);

        $stays = $client->timeTogether($ids);
        $this->assertSame($ids, array_keys($stays), 'every requested id is present');
        $this->assertNotEmpty($stays[DevMockVrchat::userId(1)]);
        $this->assertSame([], $stays['usr_ffffffff-ffff-ffff-ffff-ffffffffffff']);
        $this->assertSame(DevMockVrchat::intervals(1, 90)[0]['start'], $stays[DevMockVrchat::userId(1)][0]['start']);

        $friends = $client->friends();
        $this->assertCount(DevMockVrchat::USER_COUNT, $friends);
        $this->assertArrayHasKey(DevMockVrchat::userId(20), $friends);

        $client->ping();
    }

    public function test_the_mock_refuses_a_wrong_api_key(): void
    {
        $this->getJson('/dev/mock-vrcx/api/v1/friends')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/dev/mock-vrcx/api/v1/friends')->assertUnauthorized();
        $this->withToken(DevMockVrchat::API_KEY)->getJson('/dev/mock-vrcx/api/v1/friends')->assertOk();
    }

    public function test_the_avatars_are_served_and_unknown_ones_404(): void
    {
        $this->get('/dev/mock-vrcx/avatar/1.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->get('/dev/mock-vrcx/avatar/999.svg')->assertNotFound();
    }

    public function test_every_mock_user_id_is_a_valid_vrchat_id_and_ids_are_unique(): void
    {
        $ids = array_map(DevMockVrchat::userId(...), range(1, DevMockVrchat::USER_COUNT));

        $this->assertCount(DevMockVrchat::USER_COUNT, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression(VrchatUserId::PATTERN, $id);
        }
    }
}
