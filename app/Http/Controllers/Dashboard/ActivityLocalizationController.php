<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Support\Regex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for {App\Models\ActivityLocalization} — same "own immediate endpoint, not
 * part of the big settings form" shape as SleepExceptionController.
 * Each role is its own record an owner adds/removes one at a time
 * rather than a handful of fields saved together. Nothing here is §0.1
 * client-vault E2EE — pattern/pattern_preview are instead §0.2
 * server-runtime Crypt/APP_KEY ciphertext (see
 * ActivityLocalization::casts()), transparently encrypted/decrypted by
 * the model on every write/read below; label stays genuinely plaintext
 * (a separate localized_texts row, an owner-chosen display string).
 */
class ActivityLocalizationController extends Controller
{
    /** @return array<int, string> */
    private static function localizedTextRules(): array
    {
        return [
            'label' => ['required', 'array'],
            'label.default' => ['required', 'string', 'max:255'],
            // Any other key is a language code an owner typed in freely —
            // deliberately not restricted to a fixed list (see
            // LocalizedTextInput.vue), so every key but 'default' is
            // validated generically via a wildcard rather than named.
            'label.*' => ['string', 'max:255'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid', 'unique:activity_localizations,id'],
            'pattern' => ['required', 'string', 'max:500', Regex::validateSingleCaptureGroup(...)],
            'pattern_preview' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            ...self::localizedTextRules(),
        ]);

        // 'label' isn't a real column (see HasLocalizedFields) — outside
        // $fillable/mass assignment on purpose, same as User::calendar_
        // url_ciphertext, so it's pulled out and saved via its own call.
        $label = $data['label'];
        unset($data['label']);

        $role = $request->user()->activityLocalizations()->create($data);
        $role->setLocalizedField('label', $label);

        return response()->json(['id' => $role->id], 201);
    }

    public function update(Request $request, string $activityLocalization): JsonResponse
    {
        $data = $request->validate([
            'pattern' => ['required', 'string', 'max:500', Regex::validateSingleCaptureGroup(...)],
            'pattern_preview' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            ...self::localizedTextRules(),
        ]);

        $label = $data['label'];
        unset($data['label']);

        $role = $request->user()->activityLocalizations()->where('id', $activityLocalization)->firstOrFail();
        $role->update($data);
        $role->setLocalizedField('label', $label);

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request, string $activityLocalization): JsonResponse
    {
        // forceDelete(), not delete(): this is the existing single-record
        // "delete my own activity localization" action, unrelated to the
        // account-wide soft-delete/48h-purge flow (App\Services\Account\
        // AccountDeletionService) — SoftDeletes on this model exists only
        // to serve that flow, deleting one record here should still be
        // immediate and permanent like it always was.
        $request->user()->activityLocalizations()->where('id', $activityLocalization)->firstOrFail()->forceDelete();

        return response()->json(['status' => 'ok']);
    }
}
