<?php

namespace App\Http\Controllers;

use App\Models\Invite;
use App\Models\ShareLink;
use App\Services\InviteService;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ShareLinkController extends Controller
{
    public function __construct(private readonly InviteService $invites) {}

    /**
     * Handles both the current share-link id shape (UUID) and legacy
     * pre-migration tokens (§0.5/Stage 5) under one route and one path
     * shape — no redirect involved for either. A migrated link's content
     * key is derived deterministically from the token itself (§0.5,
     * App\Services\Crypto\LegacyShareLinkKey) rather than delivered via a
     * fragment, so there's nothing to add to the URL and nowhere to
     * redirect to: the token the visitor already has is everything both
     * the client and this server-side view need. WentTheNuxt's (the
     * sibling repo's) entire role in this migration is a blanket
     * same-path domain redirect; it needs no per-token data from this app
     * at all, since the token in the URL never changes between the two
     * apps.
     *
     * STUB pending Stage 6's full public viewer build (scrambled→decrypted
     * transition, month/week/agenda views, etc) for the actual rendering
     * path.
     */
    public function show(string $token): View
    {
        if (Str::isUuid($token)) {
            $shareLink = ShareLink::find($token);

            if ($shareLink !== null) {
                return $this->renderStub($shareLink);
            }
        }

        $shareLink = ShareLink::where('legacy_token', $token)->first();

        if ($shareLink === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $this->renderStub($shareLink);
    }

    private function renderStub(ShareLink $shareLink): View
    {
        $invite = Invite::where('source_share_link_id', $shareLink->id)
            ->whereNull('max_uses')
            ->whereNull('expires_at')
            ->first();

        if (! $invite) {
            $invite = $this->invites->issue(
                inviter: $shareLink->user,
                sourceShareLink: $shareLink,
            );
        }

        return view('share-links.show', [
            'shareLink' => $shareLink,
            'inviteCode' => $invite->code,
        ]);
    }
}
