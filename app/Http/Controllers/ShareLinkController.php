<?php

namespace App\Http\Controllers;

use App\Models\Invite;
use App\Models\ShareLink;
use App\Services\InviteService;
use Illuminate\View\View;

class ShareLinkController extends Controller
{
    public function __construct(private readonly InviteService $invites) {}

    /**
     * STUB pending Stage 6's full public viewer build (scrambled→decrypted
     * transition, month/week/agenda views, etc). For now this just proves
     * out the "viewing a calendar is itself an invite surface" flow from
     * Stage 3: every share-link view carries a "create your own" CTA
     * pre-attributed to the link's owner (§4).
     */
    public function show(ShareLink $shareLink): View
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
