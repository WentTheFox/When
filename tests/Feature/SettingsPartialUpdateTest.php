<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settings.vue is split into 4 independent useForm() instances (calendar,
 * public page, availability, event matching), each PATCHing /settings with
 * only its own fields — see Settings.vue's own header comment. Regression
 * coverage for the bug this split introduced: SettingsController::update()
 * used to default every field absent from the request to null, so saving
 * one card silently wiped every other card's settings. It must now only
 * ever touch fields actually present in a given request.
 */
class SettingsPartialUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function fullyConfiguredUser(): User
    {
        return User::factory()->create([
            'timezone' => 'UTC',
            'week_start' => 1,
            'calendar_parsing_mode' => 'full_detail',
            'dnd_event_pattern' => '^dnd$',
            'nap_event_pattern' => '^nap$',
            'work_event_pattern' => '^work$',
            'school_event_pattern' => '(school|class)',
            'highlight_clause_pattern' => '\b(?:with|w\/)\s+(.+)$',
            'highlight_split_pattern' => ',|&',
            'activity_clause_pattern' => '\b(?:at|@)\s+(.+)$',
            'tentative_pattern' => '\?$',
            'open_end_pattern' => '\+$',
            'open_start_pattern' => '^\+',
            'accent_color_key' => 'blue',
            'secondary_color_key' => 'purple',
            'sleep_color_key' => 'slate',
            'busy_color_key' => 'red',
            'work_color_key' => 'orange',
            'school_color_key' => 'gold',
            'free_color_key' => 'green',
            'highlight_color_key' => 'pink',
            'free_icon_key' => 'circle-check',
            'busy_icon_key' => 'circle-xmark',
            'work_icon_key' => 'briefcase',
            'school_icon_key' => 'graduation-cap',
            'sleep_icon_key' => 'bed',
            'highlight_icon_key' => 'star',
            'now_color_key' => 'blue',
        ]);
    }

    /** Everything except timezone/week_start/calendar_parsing_mode — callers that themselves change those three assert them separately. */
    private function assertUnrelatedSettingsUntouched(User $before, User $after): void
    {
        $this->assertSame($before->dnd_event_pattern, $after->dnd_event_pattern);
        $this->assertSame($before->nap_event_pattern, $after->nap_event_pattern);
        $this->assertSame($before->work_event_pattern, $after->work_event_pattern);
        $this->assertSame($before->school_event_pattern, $after->school_event_pattern);
        $this->assertSame($before->highlight_clause_pattern, $after->highlight_clause_pattern);
        $this->assertSame($before->highlight_split_pattern, $after->highlight_split_pattern);
        $this->assertSame($before->activity_clause_pattern, $after->activity_clause_pattern);
        $this->assertSame($before->tentative_pattern, $after->tentative_pattern);
        $this->assertSame($before->open_end_pattern, $after->open_end_pattern);
        $this->assertSame($before->open_start_pattern, $after->open_start_pattern);
        $this->assertSame($before->accent_color_key, $after->accent_color_key);
        $this->assertSame($before->secondary_color_key, $after->secondary_color_key);
        $this->assertSame($before->sleep_color_key, $after->sleep_color_key);
        $this->assertSame($before->busy_color_key, $after->busy_color_key);
        $this->assertSame($before->work_color_key, $after->work_color_key);
        $this->assertSame($before->school_color_key, $after->school_color_key);
        $this->assertSame($before->free_color_key, $after->free_color_key);
        $this->assertSame($before->highlight_color_key, $after->highlight_color_key);
        $this->assertSame($before->free_icon_key, $after->free_icon_key);
        $this->assertSame($before->busy_icon_key, $after->busy_icon_key);
        $this->assertSame($before->work_icon_key, $after->work_icon_key);
        $this->assertSame($before->school_icon_key, $after->school_icon_key);
        $this->assertSame($before->sleep_icon_key, $after->sleep_icon_key);
        $this->assertSame($before->highlight_icon_key, $after->highlight_icon_key);
        $this->assertSame($before->now_color_key, $after->now_color_key);
    }

    public function test_saving_the_event_matching_card_alone_does_not_wipe_the_other_cards(): void
    {
        $user = $this->fullyConfiguredUser();
        $before = $user->fresh();

        // Exactly what SettingsEventMatchingCard's own useForm() submits —
        // no timezone/color/icon/availability fields at all.
        $response = $this->actingAs($user)->patch('/settings', [
            'dnd_event_pattern' => '^do not disturb$',
            'nap_event_pattern' => '^nap$',
            'work_event_pattern' => '^work$',
            'school_event_pattern' => '(school|class)',
            'highlight_clause_pattern' => '\b(?:with|w\/)\s+(.+)$',
            'highlight_split_pattern' => ',|&',
            'activity_clause_pattern' => '\b(?:at|@)\s+(.+)$',
            'tentative_pattern' => '\?$',
            'open_end_pattern' => '\+$',
            'open_start_pattern' => '^\+',
        ]);

        $response->assertSessionHasNoErrors();
        $after = $user->fresh();
        $this->assertSame('^do not disturb$', $after->dnd_event_pattern);
        $this->assertSame($before->timezone, $after->timezone);
        $this->assertSame($before->week_start, $after->week_start);
        $this->assertSame($before->calendar_parsing_mode, $after->calendar_parsing_mode);
        $this->assertSame($before->accent_color_key, $after->accent_color_key);
        $this->assertSame($before->free_icon_key, $after->free_icon_key);
        $this->assertSame($before->now_color_key, $after->now_color_key);
    }

    public function test_saving_the_calendar_card_alone_does_not_wipe_the_other_cards(): void
    {
        $user = $this->fullyConfiguredUser();
        $before = $user->fresh();

        // Exactly what SettingsCalendarCard's own calendarSettingsForm submits.
        $response = $this->actingAs($user)->patch('/settings', [
            'timezone' => 'America/New_York',
            'week_start' => 0,
            'calendar_parsing_mode' => 'free_busy_only',
        ]);

        $response->assertSessionHasNoErrors();
        $after = $user->fresh();
        $this->assertSame('America/New_York', $after->timezone);
        $this->assertSame(0, $after->week_start);
        $this->assertSame('free_busy_only', $after->calendar_parsing_mode);
        $this->assertUnrelatedSettingsUntouched($before, $after);
    }

    public function test_saving_the_public_page_card_alone_does_not_wipe_the_other_cards(): void
    {
        $user = $this->fullyConfiguredUser();
        $before = $user->fresh();

        // Exactly what SettingsPublicPageCard's own publicPageSettingsForm submits.
        $response = $this->actingAs($user)->patch('/settings', [
            'public_page_title' => ['default' => "Jane's Free Time"],
            'accent_color_key' => 'green',
            'secondary_color_key' => 'slate',
            'free_color_key' => 'blue',
            'busy_color_key' => 'purple',
            'work_color_key' => 'red',
            'school_color_key' => 'orange',
            'sleep_color_key' => 'pink',
            'highlight_color_key' => 'gold',
            'free_icon_key' => 'star',
            'busy_icon_key' => 'bed',
            'work_icon_key' => 'circle-xmark',
            'school_icon_key' => 'circle-check',
            'sleep_icon_key' => 'briefcase',
            'highlight_icon_key' => 'graduation-cap',
            'now_color_key' => 'red',
        ]);

        $response->assertSessionHasNoErrors();
        $after = $user->fresh();
        $this->assertSame(['default' => "Jane's Free Time"], $after->public_page_title);
        $this->assertSame('green', $after->accent_color_key);
        $this->assertSame($before->timezone, $after->timezone);
        $this->assertSame($before->dnd_event_pattern, $after->dnd_event_pattern);
        $this->assertSame($before->highlight_clause_pattern, $after->highlight_clause_pattern);
    }

    public function test_saving_the_availability_card_alone_does_not_wipe_the_other_cards(): void
    {
        $user = $this->fullyConfiguredUser();
        $user->setWeeklyAvailability([0 => ['wake' => '07:00', 'sleep' => '23:00']]);
        $before = $user->fresh();

        // Exactly what SettingsAvailabilityCard's own submit() transforms its
        // form into — a weekday-keyed object, nothing else.
        $response = $this->actingAs($user)->patch('/settings', [
            'availability' => [
                0 => ['wake' => '08:00', 'sleep' => '22:00'],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $after = $user->fresh();
        $this->assertSame('08:00', $after->weeklyAvailability()[0]['wake']);
        $this->assertUnrelatedSettingsUntouched($before, $after);
    }

    public function test_a_pattern_can_still_be_explicitly_cleared_within_its_own_card(): void
    {
        $user = $this->fullyConfiguredUser();

        $response = $this->actingAs($user)->patch('/settings', [
            'dnd_event_pattern' => null,
            'nap_event_pattern' => '^nap$',
            'work_event_pattern' => '^work$',
            'school_event_pattern' => '(school|class)',
            'highlight_clause_pattern' => '\b(?:with|w\/)\s+(.+)$',
            'highlight_split_pattern' => ',|&',
            'activity_clause_pattern' => '\b(?:at|@)\s+(.+)$',
            'tentative_pattern' => '\?$',
            'open_end_pattern' => '\+$',
            'open_start_pattern' => '^\+',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->dnd_event_pattern);
    }

    public function test_the_public_page_title_can_still_be_explicitly_cleared_within_its_own_card(): void
    {
        $user = $this->fullyConfiguredUser();
        $user->setLocalizedField('public_page_title', ['default' => 'Something']);

        $response = $this->actingAs($user)->patch('/settings', [
            'public_page_title' => null,
            'accent_color_key' => 'green',
            'secondary_color_key' => 'slate',
            'free_color_key' => 'blue',
            'busy_color_key' => 'purple',
            'work_color_key' => 'red',
            'school_color_key' => 'orange',
            'sleep_color_key' => 'pink',
            'highlight_color_key' => 'gold',
            'free_icon_key' => 'star',
            'busy_icon_key' => 'bed',
            'work_icon_key' => 'circle-xmark',
            'school_icon_key' => 'circle-check',
            'sleep_icon_key' => 'briefcase',
            'highlight_icon_key' => 'graduation-cap',
            'now_color_key' => 'red',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->public_page_title);
    }
}
