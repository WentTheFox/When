<?php

namespace App\Http\Requests;

use App\Support\CalendarParsingMode;
use App\Support\ColorSwatchKey;
use App\Support\IconKey;
use App\Support\NowColorPresetKey;
use App\Support\Regex;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // 'sometimes' on every field here is load-bearing, not decorative:
        // Settings.vue submits 4 independent per-card forms to this same
        // endpoint, each carrying only its own fields, and
        // SettingsController::update() only touches keys actually present
        // in validated() — a field validated only as 'nullable' (without
        // 'sometimes') would still be considered "present" once any other
        // field in the request triggers validation, wiping it whenever a
        // different card is the one being saved.
        return [
            'timezone' => ['sometimes', 'timezone'],
            'week_start' => ['sometimes', 'integer', 'between:0,6'],
            'dnd_event_pattern' => ['sometimes', 'nullable', 'string', 'max:255', Regex::validateCompiles(...)],
            'dnd_event_pattern_preview' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'nap_event_pattern' => ['sometimes', 'nullable', 'string', 'max:255', Regex::validateCompiles(...)],
            'nap_event_pattern_preview' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'work_event_pattern' => ['sometimes', 'nullable', 'string', 'max:255', Regex::validateCompiles(...)],
            'work_event_pattern_preview' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'school_event_pattern' => ['sometimes', 'nullable', 'string', 'max:255', Regex::validateCompiles(...)],
            'school_event_pattern_preview' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'calendar_parsing_mode' => ['sometimes', Rule::enum(CalendarParsingMode::class)],
            'highlight_clause_pattern' => ['sometimes', 'nullable', 'string', 'max:500', Regex::validateSingleCaptureGroup(...)],
            'highlight_clause_pattern_preview' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'highlight_split_pattern' => ['sometimes', 'nullable', 'string', 'max:255', Regex::validateCompiles(...)],
            'highlight_split_pattern_preview' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'activity_clause_pattern' => ['sometimes', 'nullable', 'string', 'max:500', Regex::validateSingleCaptureGroup(...)],
            'activity_clause_pattern_preview' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tentative_pattern' => ['sometimes', 'nullable', 'string', 'max:500', Regex::validateCompiles(...)],
            'tentative_pattern_preview' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'open_end_pattern' => ['sometimes', 'nullable', 'string', 'max:500', Regex::validateCompiles(...)],
            'open_end_pattern_preview' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'open_start_pattern' => ['sometimes', 'nullable', 'string', 'max:500', Regex::validateCompiles(...)],
            'open_start_pattern_preview' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // App\Support\LocalizedText — 'default' stays optional here
            // (unlike ActivityLocalization's own label), since a blank title
            // already falls back to a translated "My Free Time" (see
            // ShareLinkController::resolveTitle and lang/*.json's
            // free.defaultTitle).
            'public_page_title' => ['sometimes', 'nullable', 'array'],
            'public_page_title.default' => ['nullable', 'string', 'max:255'],
            'public_page_title.*' => ['nullable', 'string', 'max:255'],
            'accent_color_key' => ['sometimes', 'nullable', Rule::enum(ColorSwatchKey::class)],
            'secondary_color_key' => ['sometimes', 'nullable', Rule::enum(ColorSwatchKey::class)],
            'sleep_color_key' => ['sometimes', 'nullable', Rule::enum(ColorSwatchKey::class)],
            'busy_color_key' => ['sometimes', 'nullable', Rule::enum(ColorSwatchKey::class)],
            'work_color_key' => ['sometimes', 'nullable', Rule::enum(ColorSwatchKey::class)],
            'school_color_key' => ['sometimes', 'nullable', Rule::enum(ColorSwatchKey::class)],
            'free_color_key' => ['sometimes', 'nullable', Rule::enum(ColorSwatchKey::class)],
            'highlight_color_key' => ['sometimes', 'nullable', Rule::enum(ColorSwatchKey::class)],
            'free_icon_key' => ['sometimes', 'nullable', Rule::enum(IconKey::class)],
            'busy_icon_key' => ['sometimes', 'nullable', Rule::enum(IconKey::class)],
            'work_icon_key' => ['sometimes', 'nullable', Rule::enum(IconKey::class)],
            'school_icon_key' => ['sometimes', 'nullable', Rule::enum(IconKey::class)],
            'sleep_icon_key' => ['sometimes', 'nullable', Rule::enum(IconKey::class)],
            'highlight_icon_key' => ['sometimes', 'nullable', Rule::enum(IconKey::class)],
            'now_color_key' => ['sometimes', 'nullable', Rule::enum(NowColorPresetKey::class)],
            'availability' => ['sometimes', 'nullable', 'array'],
            'availability.*.wake' => ['nullable', 'string'],
            'availability.*.sleep' => ['nullable', 'string'],
        ];
    }
}
