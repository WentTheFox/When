<?php

namespace Tests\Feature;

use App\Models\ActivityLocalization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CRUD for App\Http\Controllers\Dashboard\ActivityLocalizationController —
 * ActivityLocalizations.vue's own axios-driven per-row save/add/remove
 * flow, independent of the big settings form. No test file existed for
 * this controller before icon_key was added to it.
 */
class ActivityLocalizationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'pattern' => '^host\s+(.+)$',
            'pattern_preview' => null,
            'sort_order' => 0,
            'label' => ['default' => 'Visiting'],
        ], $overrides);
    }

    public function test_an_owner_can_create_a_role_with_an_icon(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/settings/activity-localization', $this->payload([
            'icon_key' => 'house',
        ]));

        $response->assertCreated();
        $role = ActivityLocalization::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('house', $role->icon_key);
    }

    public function test_the_icon_is_optional_on_create(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/settings/activity-localization', $this->payload());

        $response->assertCreated();
        $role = ActivityLocalization::where('user_id', $user->id)->firstOrFail();
        $this->assertNull($role->icon_key);
    }

    /**
     * A label is no longer required — an owner can save an icon-only
     * customization (no wording change at all) with a genuinely empty
     * label. Covers both an entirely-omitted `label` key and an explicit
     * empty object, since the frontend always sends the latter.
     */
    public function test_the_label_is_optional_and_can_be_left_entirely_blank(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/settings/activity-localization', $this->payload([
            'label' => [],
            'icon_key' => 'house',
        ]));

        $response->assertCreated();
        $role = ActivityLocalization::where('user_id', $user->id)->firstOrFail();
        $this->assertNull($role->label);
        $this->assertSame('house', $role->icon_key);
    }

    public function test_a_blank_label_is_also_accepted_when_the_key_is_omitted_entirely(): void
    {
        $user = User::factory()->create();
        $payload = $this->payload(['icon_key' => 'house']);
        unset($payload['label']);

        $response = $this->actingAs($user)->postJson('/settings/activity-localization', $payload);

        $response->assertCreated();
        $role = ActivityLocalization::where('user_id', $user->id)->firstOrFail();
        $this->assertNull($role->label);
    }

    public function test_an_unknown_icon_key_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/settings/activity-localization', $this->payload([
            'icon_key' => 'not-a-real-icon',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('icon_key');
    }

    public function test_an_owner_can_create_a_role_with_a_color(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/settings/activity-localization', $this->payload([
            'color_key' => 'red',
        ]));

        $response->assertCreated();
        $role = ActivityLocalization::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('red', $role->color_key);
    }

    public function test_the_color_is_optional_on_create(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/settings/activity-localization', $this->payload());

        $response->assertCreated();
        $role = ActivityLocalization::where('user_id', $user->id)->firstOrFail();
        $this->assertNull($role->color_key);
    }

    public function test_an_unknown_color_key_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/settings/activity-localization', $this->payload([
            'color_key' => 'not-a-real-color',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('color_key');
    }

    public function test_an_owner_can_clear_a_roles_color_back_to_unset(): void
    {
        $user = User::factory()->create();
        $role = $user->activityLocalizations()->create([
            'id' => (string) Str::uuid(),
            'pattern' => '^host\s+(.+)$',
            'sort_order' => 0,
            'color_key' => 'red',
        ]);
        $role->setLocalizedField('label', ['default' => 'Visiting']);

        $response = $this->actingAs($user)->patchJson("/settings/activity-localization/{$role->id}", $this->payload([
            'color_key' => null,
        ]));

        $response->assertOk();
        $this->assertNull($role->fresh()->color_key);
    }

    public function test_an_owner_can_update_a_roles_icon(): void
    {
        $user = User::factory()->create();
        $role = $user->activityLocalizations()->create([
            'id' => (string) Str::uuid(),
            'pattern' => '^host\s+(.+)$',
            'sort_order' => 0,
        ]);
        $role->setLocalizedField('label', ['default' => 'Visiting']);

        $response = $this->actingAs($user)->patchJson("/settings/activity-localization/{$role->id}", $this->payload([
            'icon_key' => 'star',
        ]));

        $response->assertOk();
        $this->assertSame('star', $role->fresh()->icon_key);
    }

    public function test_an_owner_can_clear_a_roles_icon_back_to_unset(): void
    {
        $user = User::factory()->create();
        $role = $user->activityLocalizations()->create([
            'id' => (string) Str::uuid(),
            'pattern' => '^host\s+(.+)$',
            'sort_order' => 0,
            'icon_key' => 'house',
        ]);
        $role->setLocalizedField('label', ['default' => 'Visiting']);

        $response = $this->actingAs($user)->patchJson("/settings/activity-localization/{$role->id}", $this->payload([
            'icon_key' => null,
        ]));

        $response->assertOk();
        $this->assertNull($role->fresh()->icon_key);
    }

    public function test_an_owner_cannot_update_another_owners_role(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $role = $owner->activityLocalizations()->create([
            'id' => (string) Str::uuid(),
            'pattern' => '^host\s+(.+)$',
            'sort_order' => 0,
        ]);
        $role->setLocalizedField('label', ['default' => 'Visiting']);

        $response = $this->actingAs($intruder)->patchJson("/settings/activity-localization/{$role->id}", $this->payload());

        $response->assertNotFound();
    }

    public function test_an_owner_can_delete_a_role(): void
    {
        $user = User::factory()->create();
        $role = $user->activityLocalizations()->create([
            'id' => (string) Str::uuid(),
            'pattern' => '^host\s+(.+)$',
            'sort_order' => 0,
        ]);
        $role->setLocalizedField('label', ['default' => 'Visiting']);

        $response = $this->actingAs($user)->deleteJson("/settings/activity-localization/{$role->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('activity_localizations', ['id' => $role->id]);
    }
}
