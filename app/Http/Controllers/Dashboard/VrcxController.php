<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VrchatFriend;
use App\Services\Vrcx\VrcxClient;
use App\Services\Vrcx\VrcxException;
use App\Services\Vrcx\VrcxSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The VRChat (VRCX) connection: this owner's server URL and API key, which
 * custom field holds VRChat user ids, and a manual sync. §0.2 tier — see
 * VrcxSyncService. The API key is shown back to its owner in Settings, like the
 * calendar URL (the server can decrypt it either way); it is just never exported.
 */
class VrcxController extends Controller
{
    /** How long a claim that is never reported on holds a friend back from other tabs. */
    private const CLAIM_MINUTES = 3;

    public function __construct(private readonly VrcxSyncService $sync) {}

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'vrcx_base_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            // Blank keeps the stored key; it is replaced only by a new value.
            'vrcx_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'vrcx_auto_create_friends' => ['sometimes', 'boolean'],
            // The source every connection made from a new friend is tied to; one of this owner's own, or none.
            'vrcx_friends_source_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('connection_sources', 'id')
                ->where('user_id', $request->user()->id)->whereNull('deleted_at')],
            // One of this owner's own identifier / identifier-list fields; null unselects.
            'vrchat_user_id_definition_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('connection_attribute_definitions', 'id')
                ->where('user_id', $request->user()->id)->whereIn('type', ['identifier', 'identifier_list'])->whereNull('deleted_at')],
        ]);

        /** @var User $user */
        $user = $request->user();
        $before = [$user->vrcx_base_url, $user->vrcx_api_key, $user->vrchat_user_id_definition_id];

        // Nothing on $user changes until every check has passed.
        $newUrl = $user->vrcx_base_url;
        $urlChanged = false;

        if (array_key_exists('vrcx_base_url', $data)) {
            $baseUrl = $data['vrcx_base_url'] === null || $data['vrcx_base_url'] === ''
                ? null
                : VrcxClient::normalizeBaseUrl($data['vrcx_base_url']);
            $urlChanged = $baseUrl !== null && $baseUrl !== $user->vrcx_base_url;
            $newUrl = $baseUrl;
        }

        $newKey = ($data['vrcx_api_key'] ?? '') !== '' ? $data['vrcx_api_key'] : $user->vrcx_api_key;

        $wasAutoCreating = (bool) $user->vrcx_auto_create_friends;
        $autoCreate = array_key_exists('vrcx_auto_create_friends', $data) ? (bool) $data['vrcx_auto_create_friends'] : $wasAutoCreating;
        $fieldId = array_key_exists('vrchat_user_id_definition_id', $data) ? $data['vrchat_user_id_definition_id'] : $user->vrchat_user_id_definition_id;

        if ($autoCreate && $fieldId === null) {
            throw ValidationException::withMessages(['vrcx_auto_create_friends' => 'Choose the VRChat user ID field first: it is where each new connection stores its friend\'s ID.']);
        }

        // Only a new address, or a newly enabled feature, is probed; saving other fields never depends on VRCX being up.
        $enabling = $autoCreate && ! $wasAutoCreating;
        if (($urlChanged || $enabling) && $newUrl !== null) {
            try {
                VrcxClient::assertCompatible($newUrl, $autoCreate ? ['/api/v1/friends' => 'get'] : []);
            } catch (VrcxException $e) {
                throw ValidationException::withMessages([$urlChanged ? 'vrcx_base_url' : 'vrcx_auto_create_friends' => $e->getMessage()]);
            }
        }

        $user->vrcx_base_url = $newUrl;
        $user->vrcx_api_key = $newKey;
        $user->vrcx_auto_create_friends = $autoCreate;
        $user->vrchat_user_id_definition_id = $fieldId;
        if (array_key_exists('vrcx_friends_source_id', $data)) {
            $user->vrcx_friends_source_id = $data['vrcx_friends_source_id'];
        }

        // A different server, key or field means whatever was synced no longer reflects the settings.
        if ($before !== [$user->vrcx_base_url, $user->vrcx_api_key, $user->vrchat_user_id_definition_id]) {
            $user->vrcx_synced_at = null;
        }

        $user->save();

        return back()->with('status', 'VRChat settings saved.');
    }

    /** Forgets the server and key (the field choice stays). Cached profiles and stays are kept. */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['vrcx_base_url' => null, 'vrcx_api_key' => null, 'vrcx_synced_at' => null])->save();

        return back()->with('status', 'Disconnected from VRCX.');
    }

    /**
     * Hands the browser the friends it should create connections for. The
     * browser must do it: a connection's name is encrypted with a key only the
     * unlocked vault holds. A claim only covers the moments the browser is actually
     * acting on a friend (so a second open tab doesn't make duplicates); the
     * browser hands back whatever it did not finish (see completeFriends'
     * `released`), and a claim it never reports on lapses after a few minutes. A
     * friend nobody has dealt with is therefore offered again on every page load.
     * `exclude` lists the friends this page load has already been offered, so it
     * isn't handed the same ones twice.
     */
    public function claimFriends(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exclude' => ['sometimes', 'array', 'max:1000'],
            'exclude.*' => ['uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->vrcx_auto_create_friends || $user->vrchat_user_id_definition_id === null) {
            return response()->json(['friends' => []]);
        }

        $friends = DB::transaction(function () use ($user, $data) {
            $rows = VrchatFriend::where('user_id', $user->id)
                ->where('status', VrchatFriend::PENDING)
                ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(self::CLAIM_MINUTES)))
                ->whereNotIn('id', $data['exclude'] ?? [])
                ->orderBy('created_at')
                ->limit(50)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $row->update(['claimed_at' => now()]);
            }

            return $rows;
        });

        return response()->json(['friends' => $friends->map(fn ($f) => [
            'id' => $f->id,
            'vrchat_user_id' => $f->vrchat_user_id,
            'display_name' => $f->display_name,
        ])->values(), 'source_id' => $user->vrcx_friends_source_id]);
    }

    /**
     * The browser dealt with these friends: `ids` got a connection (created, or
     * linked to an existing one); `skipped` are left out for good; `released` were
     * not finished (they await a decision, or failed) and go back to being offered
     * on the next page load.
     */
    public function completeFriends(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'max:50'],
            'ids.*' => ['uuid'],
            'skipped' => ['sometimes', 'array', 'max:50'],
            'skipped.*' => ['uuid'],
            'released' => ['sometimes', 'array', 'max:50'],
            'released.*' => ['uuid'],
        ]);

        $pending = fn () => VrchatFriend::where('user_id', $request->user()->id)->where('status', VrchatFriend::PENDING);

        $done = $pending()->whereIn('id', $data['ids'])->update(['status' => VrchatFriend::CREATED, 'claimed_at' => null]);
        $pending()->whereIn('id', $data['skipped'] ?? [])->update(['status' => VrchatFriend::DISMISSED, 'claimed_at' => null]);

        $pending()->whereIn('id', $data['released'] ?? [])->update(['claimed_at' => null]);

        return response()->json(['completed' => $done]);
    }

    /** Runs the sync now, in the request, so the owner sees the outcome. */
    public function syncNow(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $ran = $this->sync->sync($user);
        } catch (VrcxException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ran' => $ran] + $this->sync->summary($user->refresh()));
    }

    /** Current profiles and stats, for refreshing the Connections page without a reload. */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->sync->summary($request->user()));
    }

    /** Recent shared-instance intervals with one connection, newest first. */
    public function stays(Request $request, string $connection): JsonResponse
    {
        $connection = $request->user()->connections()->where('id', $connection)->firstOrFail();

        $stays = $connection->vrchatStays()->get()
            ->map(fn ($stay) => $stay->payload)
            ->sortByDesc('start')
            ->take(200)
            ->values();

        return response()->json(['stays' => $stays]);
    }
}
