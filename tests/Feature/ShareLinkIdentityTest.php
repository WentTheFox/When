<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionVrchatProfile;
use App\Models\DiscordFriend;
use App\Models\ShareLink;
use App\Models\User;
use App\Support\DiscordUserId;
use App\Support\VrchatUserId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Which linked Discord/VRChat account the free page shows in its header. */
class ShareLinkIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const DISCORD = '100000000000000009';

    private const VRCHAT = 'usr_00000000-0000-0000-0000-000000000001';

    private function link(): array
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'identifier_list',
        ]);
        $user->forceFill(['discord_user_id_definition_id' => $definition->id])->save();

        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        ConnectionAttributeValue::create([
            'connection_id' => $connection->id, 'attribute_definition_id' => $definition->id, 'identifier_list_value' => [self::DISCORD],
        ]);
        DiscordFriend::create([
            'user_id' => $user->id, 'discord_user_id_hash' => DiscordUserId::hash(self::DISCORD), 'discord_user_id' => self::DISCORD,
            'username' => 'maple_sparrow', 'global_name' => 'Maple Sparrow', 'avatar_url' => 'https://cdn.example/d.png', 'status' => DiscordFriend::CREATED,
        ]);
        ConnectionVrchatProfile::create([
            'connection_id' => $connection->id, 'vrchat_user_id_hash' => VrchatUserId::hash(self::VRCHAT), 'vrchat_user_id' => self::VRCHAT,
            'display_name' => 'MapleInVR', 'profile_picture_url' => 'https://cdn.example/v.png',
        ]);

        $shareLink = $user->shareLinks()->create([
            'connection_id' => $connection->id, 'highlight_token' => ShareLink::generateHighlightToken(),
        ]);

        return [$user, $connection, $shareLink];
    }

    private function shownIdentity(ShareLink $shareLink): mixed
    {
        return $this->get("/free/{$shareLink->highlight_token}", ['Accept-Language' => 'en'])
            ->assertInertia(fn ($page) => $page->has('identity'))
            ->viewData('page')['props']['identity'];
    }

    public function test_automatic_shows_the_first_named_account(): void
    {
        [, , $shareLink] = $this->link();

        $identity = $this->shownIdentity($shareLink);

        $this->assertSame('discord', $identity['provider']);
        $this->assertSame('Maple Sparrow', $identity['name']);
        $this->assertSame('https://cdn.example/d.png', $identity['picture_url']);
    }

    public function test_owner_can_pick_the_vrchat_account_or_nobody(): void
    {
        [$user, $connection, $shareLink] = $this->link();
        $url = "/dashboard/connections/{$connection->id}/share-link/identity";

        $this->actingAs($user)->putJson($url, ['identity' => 'vrchat:'.self::VRCHAT])->assertOk();
        $this->assertSame('MapleInVR', $this->shownIdentity($shareLink->refresh())['name']);

        $this->actingAs($user)->putJson($url, ['identity' => 'none'])->assertOk();
        $this->assertNull($this->shownIdentity($shareLink->refresh()));

        $this->actingAs($user)->putJson($url, ['identity' => null])->assertOk();
        $this->assertSame('Maple Sparrow', $this->shownIdentity($shareLink->refresh())['name']);
    }

    public function test_an_account_not_linked_to_the_connection_is_rejected(): void
    {
        [$user, $connection] = $this->link();

        $this->actingAs($user)
            ->putJson("/dashboard/connections/{$connection->id}/share-link/identity", ['identity' => 'discord:999'])
            ->assertStatus(422);
    }

    public function test_another_owner_cannot_change_it(): void
    {
        [, $connection] = $this->link();

        $this->actingAs(User::factory()->create())
            ->putJson("/dashboard/connections/{$connection->id}/share-link/identity", ['identity' => 'none'])
            ->assertNotFound();
    }
}
