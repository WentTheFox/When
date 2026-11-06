<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\ShareLinkVisit;
use App\Services\ShareLinkIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A connection's one optional share link (share_links.connection_id, unique).
 * There is no standalone share-link management any more: the link is created,
 * regenerated, inspected and removed from its connection, and takes the
 * connection's name — it has no label of its own, so nothing here handles
 * client-vault (§0.1) ciphertext at all.
 *
 * What the link highlights and whether it bypasses DND come from the
 * connection's custom-field values (HighlightWordsResolver /
 * BypassDndResolver), edited through ConnectionController like any other
 * field. Archiving the connection is what archives its link: the link has no flag of its own.
 *
 * A link's content key derives deterministically from its highlight_token
 * (App\Services\Crypto\HighlightTokenKey), so there is nothing to rotate in
 * the old fragment/passphrase sense; regenerateToken() replaces the token
 * wholesale, which invalidates every URL out in the wild by construction.
 */
class ConnectionShareLinkController extends Controller
{
    public function __construct(private readonly ShareLinkIdentity $identities) {}

    /** Enables sharing for the connection. Idempotent: returns the existing link if there already is one. */
    public function store(Request $request, string $connection): JsonResponse
    {
        $connection = $this->findConnection($request, $connection);

        $existing = $connection->shareLink;
        if ($existing !== null) {
            return response()->json($this->serialize($existing));
        }

        $shareLink = $request->user()->shareLinks()->create([
            'connection_id' => $connection->id,
            'highlight_token' => ShareLink::generateHighlightToken(),
        ]);

        return response()->json($this->serialize($shareLink), 201);
    }

    /**
     * Replaces the link's public identifier with a freshly generated
     * highlight_token (see ShareLink::generateHighlightToken()), which also
     * changes the derived content key.
     */
    public function regenerateToken(Request $request, string $connection): JsonResponse
    {
        $shareLink = $this->findShareLink($request, $connection);

        $shareLink->update(['highlight_token' => ShareLink::generateHighlightToken()]);

        ShareLinkCache::where('share_link_id', $shareLink->id)->delete();

        return response()->json($this->serialize($shareLink));
    }

    /** Picks which linked Discord/VRChat account the free page shows ('none' hides it, null is automatic). */
    public function updateIdentity(Request $request, string $connection): JsonResponse
    {
        $shareLink = $this->findShareLink($request, $connection);
        $choice = $request->validate(['identity' => ['nullable', 'string', 'max:200']])['identity'] ?? null;

        if ($choice !== null && ! $this->identities->isValidChoice($shareLink, $choice)) {
            return response()->json(['message' => 'That account is not linked to this connection.'], 422);
        }

        $shareLink->update(['display_identity' => $choice]);

        return response()->json($this->serialize($shareLink));
    }

    /**
     * Disables sharing: a real DELETE, not a soft delete — SoftDeletes on
     * ShareLink exists only for account-wide deletion (see
     * ActivityLocalizationController::destroy's comment). Visits, cache and
     * words cascade with it.
     */
    public function destroy(Request $request, string $connection): JsonResponse
    {
        $this->findShareLink($request, $connection)->forceDelete();

        return response()->json(null, 204);
    }

    /** Paginated visit history for the connection's sharing section. */
    public function visits(Request $request, string $connection): JsonResponse
    {
        $shareLink = $this->findShareLink($request, $connection);

        $visits = $shareLink->visits()
            ->orderByDesc('visited_at')
            ->paginate(10)
            ->through(fn (ShareLinkVisit $visit) => [
                'id' => $visit->id,
                'visited_at' => $visit->visited_at->toIso8601String(),
                'timezone' => $visit->timezone,
                'locale' => $visit->locale,
            ]);

        return response()->json($visits);
    }

    public function destroyVisit(Request $request, string $connection, string $visit): JsonResponse
    {
        $this->findShareLink($request, $connection)->visits()->where('id', $visit)->firstOrFail()->delete();

        return response()->json(null, 204);
    }

    /** Removes every recorded visit for the link in one go. */
    public function destroyVisits(Request $request, string $connection): JsonResponse
    {
        $this->findShareLink($request, $connection)->visits()->delete();

        return response()->json(null, 204);
    }

    /** @return array{id: string, highlight_token: ?string, display_identity: ?string} */
    private function serialize(ShareLink $shareLink): array
    {
        return [
            'id' => $shareLink->id,
            'highlight_token' => $shareLink->highlight_token,
            'display_identity' => $shareLink->display_identity,
        ];
    }

    private function findConnection(Request $request, string $id): Connection
    {
        return $request->user()->connections()->where('id', $id)->firstOrFail();
    }

    private function findShareLink(Request $request, string $connectionId): ShareLink
    {
        return $this->findConnection($request, $connectionId)->shareLink()->firstOrFail();
    }
}
