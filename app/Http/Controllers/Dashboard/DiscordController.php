<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DiscordFriend;
use App\Models\User;
use App\Services\Discord\DiscordClient;
use App\Services\Discord\DiscordException;
use App\Services\Discord\DiscordSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The Discord connection: OAuth2 grant (identify + relationships.read), a manual
 * sync, and the friends waiting to be linked. §0.2 tier — see DiscordSyncService.
 * Which custom field holds Discord user ids is chosen with the VRChat settings
 * (VrcxController::update), as it is shared with the ids VRCX learns.
 */
class DiscordController extends Controller
{
    private const STATE_KEY = 'discord_oauth_state';

    public function __construct(private readonly DiscordSyncService $sync) {}

    public function connect(Request $request, DiscordClient $client): RedirectResponse
    {
        if (! DiscordClient::isConfigured()) {
            return redirect()->route('settings.edit', ['tab' => 'discord'])->withErrors(['discord' => 'Discord is not set up on this server.']);
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($client->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $back = redirect()->route('settings.edit', ['tab' => 'discord']);

        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return $back->withErrors(['discord' => 'The Discord sign-in expired. Try connecting again.']);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $back->withErrors(['discord' => 'Discord did not authorize the connection.']);
        }

        try {
            $this->sync->connect($request->user(), $code);
        } catch (DiscordException $e) {
            return $back->withErrors(['discord' => $e->getMessage()]);
        }

        return $back->with('status', 'Discord connected.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->sync->disconnect($request->user());

        return back()->with('status', 'Disconnected from Discord.');
    }

    /** Runs the sync now, in the request, so the owner sees the outcome. */
    public function syncNow(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $ran = $this->sync->sync($user);
        } catch (DiscordException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ran' => $ran, 'relationship_types' => (object) $this->sync->lastTypes] + $this->sync->summary($user->refresh()));
    }

    /**
     * Adds friends and blocked users from a relationships list the owner pasted (the browser has already
     * picked the fields out; every value is checked again here). Only people not tracked at all yet are added.
     */
    public function importRelationships(Request $request): JsonResponse
    {
        $request->validate([
            'entries' => ['required', 'array', 'max:'.DiscordSyncService::IMPORT_MAX],
            'entries.*' => ['array'],
            'entries.*.id' => ['required', 'string', 'max:32'],
            'entries.*.kind' => ['required', 'in:friend,blocked'],
            'entries.*.username' => ['nullable', 'string', 'max:200'],
            'entries.*.global_name' => ['nullable', 'string', 'max:200'],
            'entries.*.nickname' => ['nullable', 'string', 'max:200'],
            'entries.*.avatar' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! $this->sync->isConfigured($user)) {
            return response()->json(['message' => 'Connect Discord and choose the Discord user ID field first.'], 422);
        }

        /** @var list<mixed> $entries */
        $entries = array_values((array) $request->input('entries'));

        return response()->json($this->sync->importRelationships($user, $entries));
    }

    /**
     * Asks Discord about one waiting person and replaces what we hold, when an imported avatar fails to load
     * (see DiscordSyncService::refreshProfile). Needs no connected account: it uses the server's bot token.
     */
    public function refreshFriend(Request $request, string $friend): JsonResponse
    {
        $row = DiscordFriend::where('user_id', $request->user()->id)->where('id', $friend)->firstOrFail();

        if (! DiscordClient::canLookUp()) {
            return response()->json(['message' => 'Looking up Discord users is not set up on this server.'], 422);
        }

        try {
            $this->sync->refreshProfile($row);
        } catch (DiscordException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['friend' => $this->friendPayload($row)]);
    }

    /**
     * Looks up the Discord accounts on one connection by id (see DiscordSyncService::lookup).
     * Needs no connected Discord account: it uses the server's own bot token.
     */
    public function lookup(Request $request, string $connection): JsonResponse
    {
        $connection = $request->user()->connections()->where('id', $connection)->firstOrFail();

        try {
            $profiles = $this->sync->lookup($request->user(), $connection->id);
        } catch (DiscordException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['profiles' => $profiles]);
    }

    /** Current state, for refreshing the page without a reload. */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->sync->summary($request->user()));
    }

    /**
     * The friends waiting for the owner: Discord friends with no connection yet. They
     * stay here, across page loads, until the owner acts on each (a new connection,
     * added to an existing one, or dismissed). The browser does the linking, as a
     * connection's name is encrypted there.
     */
    public function pendingFriends(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->sync->isConfigured($user)) {
            return response()->json(['friends' => []]);
        }

        $friends = DiscordFriend::where('user_id', $user->id)->where('status', DiscordFriend::PENDING)->get()
            // Names are encrypted at rest, so the order is made here rather than by the database.
            ->sortBy(fn (DiscordFriend $f) => mb_strtolower((string) ($f->nickname ?? $f->global_name ?? $f->username ?? $f->discord_user_id)))
            ->map(fn (DiscordFriend $f) => $this->friendPayload($f))->values();

        return response()->json(['friends' => $friends]);
    }

    /**
     * The owner dealt with these friends: `ids` got a connection (created, or added to an
     * existing one); `skipped` are dismissed for good. Anyone not mentioned stays pending.
     */
    public function completeFriends(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'max:500'],
            'ids.*' => ['uuid'],
            'skipped' => ['sometimes', 'array', 'max:500'],
            'skipped.*' => ['uuid'],
        ]);

        $pending = fn () => DiscordFriend::where('user_id', $request->user()->id)->where('status', DiscordFriend::PENDING);

        $done = $pending()->whereIn('id', $data['ids'])->update(['status' => DiscordFriend::CREATED]);
        $pending()->whereIn('id', $data['skipped'] ?? [])->update(['status' => DiscordFriend::DISMISSED]);

        // The friends just linked get their names as highlight tokens at once, not at the next sync.
        $this->sync->addNameTokens($request->user());

        return response()->json(['completed' => $done]);
    }

    /** @return array<string, mixed> */
    private function friendPayload(DiscordFriend $f): array
    {
        return [
            'id' => $f->id,
            'discord_user_id' => $f->discord_user_id,
            'username' => $f->username,
            'global_name' => $f->global_name,
            'nickname' => $f->nickname,
            'picture_url' => $f->avatar_url,
            'blocked' => $f->is_blocked,
            // Null: the profile came from a pasted list and Discord has not confirmed it.
            'synced_at' => $f->synced_at?->toIso8601String(),
        ];
    }
}
