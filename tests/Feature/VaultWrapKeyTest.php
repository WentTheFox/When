<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VaultWrapKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/dashboard/vault/wrap-key?create=1')->assertUnauthorized();
    }

    public function test_plain_get_without_a_key_returns_no_content(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard/vault/wrap-key')->assertNoContent();
    }

    public function test_create_mints_a_key_that_is_then_returned_until_it_expires(): void
    {
        $user = User::factory()->create(['vault_lock_timeout_minutes' => 15]);

        $created = $this->actingAs($user)->getJson('/dashboard/vault/wrap-key?create=1')->assertOk();
        $key = $created->json('key');
        $this->assertSame(32, strlen(base64_decode($key, true)));
        $this->assertNotNull($created->json('expires_at'));

        $this->getJson('/dashboard/vault/wrap-key')->assertOk()->assertJsonPath('key', $key);

        $this->travel(14)->minutes();
        $this->getJson('/dashboard/vault/wrap-key')->assertOk()->assertJsonPath('key', $key);

        $this->travel(2)->minutes();
        $this->get('/dashboard/vault/wrap-key')->assertNoContent();
        // Expiry deleted it for good.
        $this->get('/dashboard/vault/wrap-key')->assertNoContent();
    }

    public function test_touch_slides_the_expiry_forward(): void
    {
        $user = User::factory()->create(['vault_lock_timeout_minutes' => 5]);

        $this->actingAs($user)->getJson('/dashboard/vault/wrap-key?create=1')->assertOk();

        $this->travel(4)->minutes();
        $this->postJson('/dashboard/vault/wrap-key/touch')->assertOk();

        $this->travel(4)->minutes();
        $this->getJson('/dashboard/vault/wrap-key')->assertOk();

        $this->travel(2)->minutes();
        $this->post('/dashboard/vault/wrap-key/touch')->assertNoContent();
    }

    public function test_null_timeout_never_expires(): void
    {
        $user = User::factory()->create(['vault_lock_timeout_minutes' => null]);

        $this->actingAs($user)->getJson('/dashboard/vault/wrap-key?create=1')
            ->assertOk()
            ->assertJsonPath('expires_at', null);

        $this->travel(30)->days();
        $this->getJson('/dashboard/vault/wrap-key')->assertOk();
    }

    public function test_delete_forgets_the_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/dashboard/vault/wrap-key?create=1')->assertOk();
        $this->deleteJson('/dashboard/vault/wrap-key')->assertNoContent();
        $this->get('/dashboard/vault/wrap-key')->assertNoContent();
    }

    public function test_logout_destroys_the_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/dashboard/vault/wrap-key?create=1')->assertOk();
        $this->post('/logout');

        $this->actingAs($user)->get('/dashboard/vault/wrap-key')->assertNoContent();
    }

    public function test_default_timeout_is_fifteen_minutes(): void
    {
        $user = User::factory()->create();

        $this->assertSame(15, $user->fresh()->vault_lock_timeout_minutes);
    }

    public function test_settings_accepts_only_known_timeouts_and_null(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings', ['vault_lock_timeout_minutes' => 60])->assertSessionHasNoErrors();
        $this->assertSame(60, $user->fresh()->vault_lock_timeout_minutes);

        $this->patch('/settings', ['vault_lock_timeout_minutes' => 7])->assertSessionHasErrors('vault_lock_timeout_minutes');
        $this->assertSame(60, $user->fresh()->vault_lock_timeout_minutes);

        $this->patch('/settings', ['vault_lock_timeout_minutes' => null])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->vault_lock_timeout_minutes);
    }
}
