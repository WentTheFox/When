<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConnectionsMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get('/dashboard/map')->assertRedirect();
    }

    public function test_the_map_only_gets_the_owners_connections_as_ciphertext(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $mine = Connection::create(['id' => Str::uuid(), 'user_id' => $owner->id, 'name_ciphertext' => 'cipher-mine']);
        Connection::create(['id' => Str::uuid(), 'user_id' => $other->id, 'name_ciphertext' => 'cipher-theirs']);

        $this->actingAs($owner)->get('/dashboard/map')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('connections', 1)
            ->where('connections.0.id', (string) $mine->id)
            ->where('connections.0.name_ciphertext', 'cipher-mine')
            ->has('sources', 0)
            ->has('categories', 0)
            ->has('discord')->has('vrchat')->has('telegram'));
    }
}
