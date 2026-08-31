<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettingsCalendarUrlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Shown back to the owner verbatim now — this is §0.2 server-runtime
     * tier (Crypt/APP_KEY), not §0.1 client-vault E2EE, so the server can
     * already decrypt it on every recompute regardless; there's no
     * confidentiality benefit to hiding it from the owner's own settings
     * page, only confusion (an empty-looking input despite a "Configured"
     * badge).
     */
    public function test_the_settings_page_returns_the_owners_own_calendar_url_in_plaintext(): void
    {
        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://calendar.example.com/secret-9182734.ics'),
        ]);

        $this->actingAs($user)
            ->get('/settings')
            ->assertInertia(fn (Assert $page) => $page->where('calendarUrl', 'https://calendar.example.com/secret-9182734.ics'));
    }

    public function test_the_settings_page_returns_null_when_no_calendar_url_is_set(): void
    {
        $user = User::factory()->create(['calendar_url_ciphertext' => null]);

        $this->actingAs($user)
            ->get('/settings')
            ->assertInertia(fn (Assert $page) => $page->where('calendarUrl', null));
    }

    /**
     * Regression test: calendar_url used to be validated/saved inside the
     * same request as every other setting, so a pending, not-yet-previewed
     * URL made the *entire* save fail — timezone included, with no obvious
     * connection between the two in the resulting error.
     */
    public function test_saving_other_settings_never_touches_or_is_blocked_by_the_calendar_url(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC', 'calendar_url_ciphertext' => null]);

        $response = $this->actingAs($user)->patch('/settings', [
            'timezone' => 'America/New_York',
            'week_start' => 1,
            'calendar_parsing_mode' => 'auto',
        ]);

        $response->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame('America/New_York', $user->timezone);
        $this->assertNull($user->calendar_url_ciphertext);
    }

    public function test_the_main_settings_form_no_longer_accepts_a_calendar_url_field(): void
    {
        $user = User::factory()->create(['calendar_url_ciphertext' => null]);

        $this->actingAs($user)->patch('/settings', [
            'timezone' => 'UTC',
            'week_start' => 1,
            'calendar_parsing_mode' => 'auto',
            'calendar_url' => 'https://example.com/calendar.ics',
            'calendar_url_preview_confirmed' => true,
        ]);

        $this->assertNull($user->fresh()->calendar_url_ciphertext);
    }

    public function test_updating_the_calendar_url_requires_preview_confirmation(): void
    {
        $user = User::factory()->create(['calendar_url_ciphertext' => null]);

        $response = $this->actingAs($user)->patch('/settings/calendar-url', [
            'calendar_url' => 'https://example.com/calendar.ics',
        ]);

        $response->assertSessionHasErrors('calendar_url_preview_confirmed');
        $this->assertNull($user->fresh()->calendar_url_ciphertext);
    }

    public function test_a_confirmed_calendar_url_saves_via_its_own_endpoint(): void
    {
        $user = User::factory()->create(['calendar_url_ciphertext' => null]);

        $response = $this->actingAs($user)->patch('/settings/calendar-url', [
            'calendar_url' => 'https://example.com/calendar.ics',
            'calendar_url_preview_confirmed' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertNotNull($user->calendar_url_ciphertext);
        $this->assertSame('https://example.com/calendar.ics', Crypt::decryptString($user->calendar_url_ciphertext));
    }
}
