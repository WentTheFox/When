<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TelegramFriend;
use App\Models\User;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\TelegramSyncService;
use App\Support\FieldFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Telegram connection (optional: only when the operator set up the sidecar): a three-step login (phone, the
 * code Telegram sends, and a two-step verification password if the account has one), a manual sync, and the
 * contacts waiting to be linked. §0.2 tier — see TelegramSyncService.
 */
class TelegramController extends Controller
{
    public function __construct(private readonly TelegramSyncService $sync) {}

    public function startLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            // International format; spaces, dashes and brackets are what people type.
            'phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-()]{6,24}$/'],
        ]);

        $phone = '+'.ltrim(preg_replace('/\D+/', '', $data['phone']) ?? '', '+');

        return $this->attempt(fn () => $this->sync->startLogin($request->user(), $phone), ['code_sent' => true]);
    }

    public function confirmCode(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9\s\-]{3,12}$/']]);
        $code = preg_replace('/\D+/', '', $data['code']) ?? '';

        try {
            $needsPassword = $this->sync->confirmCode($request->user(), $code);
        } catch (TelegramException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['needs_password' => $needsPassword] + $this->sync->summary($request->user()->refresh()));
    }

    public function confirmPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:256']]);

        try {
            $this->sync->confirmPassword($request->user(), $data['password']);
        } catch (TelegramException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['needs_password' => false] + $this->sync->summary($request->user()->refresh()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->sync->disconnect($request->user());

        return back()->with('status', 'Disconnected from Telegram.');
    }

    /** Chooses the custom field that holds Telegram user ids (null unselects). */
    public function updateField(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'telegram_user_id_definition_id' => ['present', 'nullable', 'uuid', Rule::exists('connection_attribute_definitions', 'id')
                ->where('user_id', $user->id)->whereIn('type', FieldFeatures::BINDINGS['telegram_user_id']['types'])->whereNull('deleted_at')],
        ]);

        $fieldId = $data['telegram_user_id_definition_id'];

        // One field holds one kind of id.
        if ($fieldId !== null && in_array($fieldId, [$user->vrchat_user_id_definition_id, $user->discord_user_id_definition_id], true)) {
            throw ValidationException::withMessages(['telegram_user_id_definition_id' => 'The Telegram user ID field must be a different field from the VRChat and Discord ones: one field holds one kind of ID.']);
        }

        $user->telegram_user_id_definition_id = $fieldId;
        $user->save();

        return back()->with('status', 'Telegram settings saved.');
    }

    /** Runs the sync now, in the request, so the owner sees the outcome. */
    public function syncNow(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $ran = $this->sync->sync($user);
        } catch (TelegramException $e) {
            // A revoked session was just forgotten: tell the page so it shows the login again.
            return response()->json(['message' => $e->getMessage(), 'reauth' => $e->authRevoked], 422);
        }

        return response()->json(['ran' => $ran] + $this->sync->summary($user->refresh()));
    }

    /** Current state, for refreshing the page without a reload. */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->sync->summary($request->user()));
    }

    /**
     * The contacts waiting for the owner: Telegram contacts with no connection yet. They stay here, across page
     * loads, until the owner acts on each. The browser does the linking, as a connection's name is encrypted there.
     */
    public function pendingFriends(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->sync->isConfigured($user)) {
            return response()->json(['friends' => []]);
        }

        $friends = TelegramFriend::where('user_id', $user->id)->where('status', TelegramFriend::PENDING)->get()
            // Names are encrypted at rest, so the order is made here rather than by the database.
            ->sortBy(fn (TelegramFriend $f) => mb_strtolower((string) ($f->displayName() ?? $f->username ?? $f->telegram_user_id)))
            ->map(fn (TelegramFriend $f) => $this->friendPayload($f))->values();

        return response()->json(['friends' => $friends]);
    }

    /**
     * The owner dealt with these contacts: `ids` got a connection (created, or added to an existing one);
     * `skipped` are dismissed for good. Anyone not mentioned stays pending.
     */
    public function completeFriends(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'max:500'],
            'ids.*' => ['uuid'],
            'skipped' => ['sometimes', 'array', 'max:500'],
            'skipped.*' => ['uuid'],
        ]);

        $pending = fn () => TelegramFriend::where('user_id', $request->user()->id)->where('status', TelegramFriend::PENDING);

        $done = $pending()->whereIn('id', $data['ids'])->update(['status' => TelegramFriend::CREATED]);
        $pending()->whereIn('id', $data['skipped'] ?? [])->update(['status' => TelegramFriend::DISMISSED]);

        // The contacts just linked get their names as highlight tokens at once, not at the next sync.
        $this->sync->addNameTokens($request->user());

        return response()->json(['completed' => $done]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function attempt(callable $action, array $extra): JsonResponse
    {
        try {
            $action();
        } catch (TelegramException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($extra);
    }

    /** @return array<string, mixed> */
    private function friendPayload(TelegramFriend $f): array
    {
        return [
            'id' => $f->id,
            'telegram_user_id' => $f->telegram_user_id,
            'username' => $f->username,
            'name' => $f->displayName(),
            'phone' => $f->phone,
            'picture_url' => $f->avatar_url,
            'mutual' => $f->is_mutual,
            'premium' => $f->is_premium,
        ];
    }
}
