<?php

namespace App\Services\Account;

use App\Models\ActivityLocalization;
use App\Models\CalendarDetection;
use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionEdge;
use App\Models\ConnectionSource;
use App\Models\ConnectionSourceCategory;
use App\Models\Invite;
use App\Models\InviteRedemption;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\ShareLinkWord;
use App\Models\SleepException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The cascade walker behind self-service account deletion. Postgres has no
 * "soft cascade" — a soft delete only ever touches the one row it's called
 * on — so softDelete() explicitly walks the full FK-to-user graph. Bulk
 * `update(['deleted_at' => ...])` throughout (not per-model `delete()`) to
 * skip per-row event overhead; this app has no model events on these tables
 * that a bulk update would skip anything meaningful for.
 *
 * hardDelete(), 48h later (App\Jobs\HardDeleteExpiredAccounts), only needs
 * to delete the `users` row itself — every child table's FK already has
 * cascadeOnDelete() at the DB layer (verified against every migration in
 * database/migrations/) — except localized_texts, which is polymorphic with
 * no FK constraint, so it's cleaned up explicitly there too.
 */
class AccountDeletionService
{
    public function softDelete(User $user): void
    {
        // Many sequential bulk updates across many tables below — wrapped so
        // a failure partway through (a query error, a killed worker) can
        // never leave the account half soft-deleted, visible in some tables
        // and hidden in others.
        DB::transaction(function () use ($user) {
            $now = now();

            SleepException::where('user_id', $user->id)->update(['deleted_at' => $now]);

            $activityLocalizationIds = ActivityLocalization::where('user_id', $user->id)->pluck('id');
            ActivityLocalization::whereIn('id', $activityLocalizationIds)->update(['deleted_at' => $now]);
            $user->localizedTexts()->update(['deleted_at' => $now]);
            ActivityLocalization::whereIn('id', $activityLocalizationIds)
                ->get()
                ->each(fn (ActivityLocalization $role) => $role->localizedTexts()->update(['deleted_at' => $now]));

            $shareLinkIds = ShareLink::where('user_id', $user->id)->pluck('id');
            ShareLink::whereIn('id', $shareLinkIds)->update(['deleted_at' => $now]);
            ShareLinkWord::whereIn('share_link_id', $shareLinkIds)->update(['deleted_at' => $now]);
            // A purely derived/re-computable artifact, not independently
            // meaningful user data — deleted outright rather than soft-deleted.
            ShareLinkCache::whereIn('share_link_id', $shareLinkIds)->delete();

            Connection::where('user_id', $user->id)->update(['deleted_at' => $now]);
            ConnectionSource::where('user_id', $user->id)->update(['deleted_at' => $now]);
            ConnectionSourceCategory::where('user_id', $user->id)->update(['deleted_at' => $now]);
            ConnectionAttributeDefinition::where('user_id', $user->id)->update(['deleted_at' => $now]);
            ConnectionEdge::where('user_id', $user->id)->update(['deleted_at' => $now]);

            $issuedInviteIds = Invite::where('inviter_user_id', $user->id)->pluck('id');
            Invite::whereIn('id', $issuedInviteIds)->update(['deleted_at' => $now]);
            // Both directions: redemptions of invites THIS user issued, and
            // redemptions where this user was themselves the one redeeming
            // someone else's invite. Without the first, a redemption row for
            // an invite this user issued would stay fully visible for the
            // whole 48h grace window even though its own invite is already
            // hidden.
            InviteRedemption::whereIn('invite_id', $issuedInviteIds)
                ->orWhere('user_id', $user->id)
                ->update(['deleted_at' => $now]);
            CalendarDetection::where('user_id', $user->id)->update(['deleted_at' => $now]);

            // A query-builder update(), not $user->update(): the latter
            // routes through fill()/mass-assignment protection, and
            // deleted_at is deliberately not in User::$fillable (same
            // "outside fill()" pattern as calendar_url_ciphertext) — it
            // would silently no-op the one update that matters most here.
            User::whereKey($user->id)->update(['deleted_at' => $now]);

            // No FK constraint on sessions — must be explicit. No grace
            // period: a pending-deletion account should not stay logged in
            // anywhere for the 48h window.
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }

    public function hardDelete(string $userId): void
    {
        // Same reasoning as softDelete(): the explicit localized_texts purge
        // below and the users-row forceDelete() must land together, or a
        // crash between them would leave orphaned localized_texts rows with
        // nothing left to ever clean them up.
        DB::transaction(function () use ($userId) {
            $user = User::onlyTrashed()->find($userId);

            if ($user === null) {
                return;
            }

            // localized_texts has no FK constraint (polymorphic) — the
            // users row's own cascadeOnDelete() below won't reach it, so
            // it's cleaned up explicitly first, for the user itself and
            // every one of its (already soft-deleted) activity localizations.
            $activityLocalizationIds = ActivityLocalization::withTrashed()->where('user_id', $userId)->pluck('id');
            DB::table('localized_texts')
                ->where(function ($query) use ($userId, $activityLocalizationIds) {
                    $query->where(['localizable_type' => User::class, 'localizable_id' => $userId])
                        ->orWhere(function ($query) use ($activityLocalizationIds) {
                            $query->where('localizable_type', ActivityLocalization::class)
                                ->whereIn('localizable_id', $activityLocalizationIds);
                        });
                })
                ->delete();

            // Every other child table's FK has cascadeOnDelete() at the DB
            // layer, so this one delete removes the rest of the graph in
            // one statement.
            $user->forceDelete();
        });
    }
}
