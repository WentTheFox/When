<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\IconKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same curated-KEY-not-arbitrary-value scheme as *_color_key (see
 * IconKey's own doc comment) — every *_icon_key must be one of
 * IconKey's cases, validated with Rule::enum(), the same way
 * ColorSwatchKey already is.
 */
class SettingsIconKeyValidationTest extends TestCase
{
    use RefreshDatabase;

    private function baseSettings(): array
    {
        return [
            'timezone' => 'UTC',
            'week_start' => 1,
            'calendar_parsing_mode' => 'full_detail',
        ];
    }

    public function test_a_valid_icon_key_is_accepted(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/settings', [
            ...$this->baseSettings(),
            'sleep_icon_key' => 'bed',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('bed', $user->fresh()->sleep_icon_key);
    }

    public function test_an_unknown_icon_key_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/settings', [
            ...$this->baseSettings(),
            'sleep_icon_key' => 'not-a-real-icon',
        ]);

        $response->assertSessionHasErrors('sleep_icon_key');
    }

    public function test_every_icon_slot_is_validated(): void
    {
        $user = User::factory()->create();

        foreach (['free_icon_key', 'busy_icon_key', 'work_icon_key', 'sleep_icon_key', 'highlight_icon_key'] as $field) {
            $response = $this->actingAs($user)->patch('/settings', [
                ...$this->baseSettings(),
                $field => 'bogus',
            ]);

            $response->assertSessionHasErrors($field);
        }
    }

    public function test_a_blank_icon_key_is_accepted_and_falls_back_to_the_default(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/settings', [
            ...$this->baseSettings(),
            'sleep_icon_key' => '',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->sleep_icon_key);
    }

    public function test_every_icon_palette_key_is_individually_accepted(): void
    {
        $user = User::factory()->create();

        foreach (IconKey::cases() as $key) {
            $response = $this->actingAs($user)->patch('/settings', [
                ...$this->baseSettings(),
                'work_icon_key' => $key->value,
            ]);

            $response->assertSessionHasNoErrors();
        }
    }
}
