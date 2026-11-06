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
use Illuminate\Support\Facades\Log;
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
            // Same kind of field for Discord user ids (optional); it can't be the VRChat one, as a field holds one kind of id.
            'discord_user_id_definition_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('connection_attribute_definitions', 'id')
                ->where('user_id', $request->user()->id)->whereIn('type', ['identifier', 'identifier_list'])->whereNull('deleted_at')],
        ]);

        /** @var User $user */
        $user = $request->user();
        $before = [$user->vrcx_base_url, $user->vrcx_api_key, $user->vrchat_user_id_definition_id, $user->discord_user_id_definition_id];

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

        $discordFieldId = array_key_exists('discord_user_id_definition_id', $data) ? $data['discord_user_id_definition_id'] : $user->discord_user_id_definition_id;

        if ($discordFieldId !== null && $discordFieldId === $fieldId) {
            throw ValidationException::withMessages([
                array_key_exists('vrchat_user_id_definition_id', $data) && ! array_key_exists('discord_user_id_definition_id', $data)
                    ? 'vrchat_user_id_definition_id'
                    : 'discord_user_id_definition_id' => 'The VRChat and Discord user ID fields must be different fields: one field holds one kind of ID.',
            ]);
        }

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
        $user->discord_user_id_definition_id = $discordFieldId;
        if (array_key_exists('vrcx_friends_source_id', $data)) {
            $user->vrcx_friends_source_id = $data['vrcx_friends_source_id'];
        }

        // A different server, key or field means whatever was synced no longer reflects the settings.
        if ($before !== [$user->vrcx_base_url, $user->vrcx_api_key, $user->vrchat_user_id_definition_id, $user->discord_user_id_definition_id]) {
            $user->vrcx_synced_at = null;
        }

        $user->save();

        // A newly chosen Discord field is filled from the Discord ids already learned, at once (no VRCX round trip).
        $discordFilled = $user->discord_user_id_definition_id !== null && $user->discord_user_id_definition_id !== $before[3]
            ? $this->sync->backfillDiscordField($user)
            : 0;

        return back()->with('status', 'VRChat settings saved.'.($discordFilled > 0 ? " Added Discord IDs to {$discordFilled} connection".($discordFilled === 1 ? '' : 's').'.' : ''));
    }

    /** Forgets the server and key (the field choice stays). Cached profiles and stays are kept. */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['vrcx_base_url' => null, 'vrcx_api_key' => null, 'vrcx_synced_at' => null])->save();

        return back()->with('status', 'Disconnected from VRCX.');
    }

    /**
     * The friends waiting for the owner: VRChat friends that have no connection
     * yet. They stay here, across page loads, until the owner acts on each (a new
     * connection, added to an existing one, or dismissed): nothing is created
     * behind their back, and a connection's name is encrypted by the browser, so
     * the browser does the creating when told to.
     */
    public function pendingFriends(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->vrcx_auto_create_friends || $user->vrchat_user_id_definition_id === null) {
            return response()->json(['friends' => [], 'source_id' => null]);
        }

        $friends = VrchatFriend::where('user_id', $user->id)->where('status', VrchatFriend::PENDING)->get()
            // Names are encrypted at rest, so the order is made here rather than by the database.
            ->sortBy(fn (VrchatFriend $f) => mb_strtolower((string) ($f->display_name ?? $f->vrchat_user_id)))
            ->map(fn (VrchatFriend $f) => [
                'id' => $f->id,
                'vrchat_user_id' => $f->vrchat_user_id,
                'display_name' => $f->display_name,
                'picture_url' => $f->profile_picture_url,
            ])->values();

        return response()->json(['friends' => $friends, 'source_id' => $user->vrcx_friends_source_id]);
    }

    /**
     * The owner dealt with these friends: `ids` got a connection (created, or added
     * to an existing one); `skipped` are dismissed for good. Anyone not mentioned
     * stays pending.
     */
    public function completeFriends(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'max:500'],
            'ids.*' => ['uuid'],
            'skipped' => ['sometimes', 'array', 'max:500'],
            'skipped.*' => ['uuid'],
        ]);

        $pending = fn () => VrchatFriend::where('user_id', $request->user()->id)->where('status', VrchatFriend::PENDING);

        $done = $pending()->whereIn('id', $data['ids'])->update(['status' => VrchatFriend::CREATED]);
        $pending()->whereIn('id', $data['skipped'] ?? [])->update(['status' => VrchatFriend::DISMISSED]);

        return response()->json(['completed' => $done]);
    }

    /**
     * The browser appended these VRChat notes to the connections' (end-to-end
     * encrypted) notes. Settles each one whose note is still the text that was merged;
     * the server then forgets the note itself and keeps only a hash of it.
     */
    public function notesImported(Request $request): JsonResponse
    {
        $data = $request->validate([
            'imported' => ['required', 'array', 'max:500'],
            'imported.*.connection_id' => ['required', 'uuid'],
            'imported.*.vrchat_user_id' => ['required', 'string', 'max:100'],
            'imported.*.note' => ['required', 'string', 'max:20000'],
        ]);

        return response()->json(['settled' => $this->sync->markNotesImported($request->user(), $data['imported'])]);
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

        return response()->json(['ran' => $ran, 'warnings' => $this->sync->warnings()] + $this->sync->summary($user->refresh()));
    }

    /** Syncs just this connection's VRChat accounts, in the request. */
    public function syncConnection(Request $request, string $connection): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $connection = $user->connections()->where('id', $connection)->firstOrFail();

        try {
            $this->sync->refreshConnection($user, $connection);
        } catch (VrcxException $e) {
            // The connection's own id only (never a VRChat id): a failure here leaves the owner just the message in their browser otherwise.
            Log::warning("VRCX sync of connection {$connection->id} failed: ".$e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ran' => true, 'warnings' => $this->sync->warnings()] + $this->sync->summary($user->refresh()));
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
