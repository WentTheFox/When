<?php

namespace App\Services;

use App\Models\Invite;
use App\Models\InviteRedemption;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Registration is closed by default (§4) — this is the only way in. Also
 * generates the "create your own" invite attributed to a share link when a
 * viewer clicks through from someone's calendar (source_share_link_id).
 */
class InviteService
{
    public function issue(
        User $inviter,
        ?int $maxUses = null,
        ?Carbon $expiresAt = null,
        ?ShareLink $sourceShareLink = null,
    ): Invite {
        return Invite::create([
            'inviter_user_id' => $inviter->id,
            'code' => $this->generateUniqueCode(),
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
            'source_share_link_id' => $sourceShareLink?->id,
        ]);
    }

    /** Finds a still-usable invite by code, or null if invalid/expired/exhausted. */
    public function findValid(string $code): ?Invite
    {
        $invite = Invite::where('code', $code)->first();

        if (! $invite || $this->isExhausted($invite)) {
            return null;
        }

        if ($invite->expires_at !== null && $invite->expires_at->isPast()) {
            return null;
        }

        return $invite;
    }

    public function isExhausted(Invite $invite): bool
    {
        if ($invite->max_uses === null) {
            return false;
        }

        return $invite->redemptions()->count() >= $invite->max_uses;
    }

    /** Records the inviter → invitee audit trail — no reward/scoring, just provenance. */
    public function redeem(Invite $invite, User $invitee): InviteRedemption
    {
        $redemption = InviteRedemption::create([
            'invite_id' => $invite->id,
            'user_id' => $invitee->id,
            'redeemed_at' => now(),
        ]);

        if ($this->isExhausted($invite)) {
            $invite->update(['used_at' => now()]);
        }

        return $redemption;
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = Str::lower(Str::random(10));
        } while (Invite::where('code', $code)->exists());

        return $code;
    }
}
