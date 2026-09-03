<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Support\Regex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for App\Models\ActivityRole — same "own immediate endpoint, not
 * part of the big settings form" shape as SleepExceptionController, since
 * each role is really its own record an owner adds/removes one at a time
 * rather than a handful of fields saved together. Nothing here is §0.1
 * E2EE (unlike SleepException's own optional note) — pattern/label are
 * both owner-authored, not extracted from calendar content, so there's
 * nothing sensitive for the server to avoid seeing.
 */
class ActivityRoleController extends Controller
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
            'id' => ['required', 'uuid', 'unique:activity_roles,id'],
            'pattern' => ['required', 'string', 'max:500', Regex::validateSingleCaptureGroup(...)],
            'sort_order' => ['required', 'integer', 'min:0'],
            ...self::localizedTextRules(),
        ]);

        // 'label' isn't a real column (see HasLocalizedFields) — outside
        // $fillable/mass assignment on purpose, same as User::calendar_
        // url_ciphertext, so it's pulled out and saved via its own call.
        $label = $data['label'];
        unset($data['label']);

        $role = $request->user()->activityRoles()->create($data);
        $role->setLocalizedField('label', $label);

        return response()->json(['id' => $role->id], 201);
    }

    public function update(Request $request, string $activityRole): JsonResponse
    {
        $data = $request->validate([
            'pattern' => ['required', 'string', 'max:500', Regex::validateSingleCaptureGroup(...)],
            'sort_order' => ['required', 'integer', 'min:0'],
            ...self::localizedTextRules(),
        ]);

        $label = $data['label'];
        unset($data['label']);

        $role = $request->user()->activityRoles()->where('id', $activityRole)->firstOrFail();
        $role->update($data);
        $role->setLocalizedField('label', $label);

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request, string $activityRole): JsonResponse
    {
        $request->user()->activityRoles()->where('id', $activityRole)->firstOrFail()->delete();

        return response()->json(['status' => 'ok']);
    }
}
