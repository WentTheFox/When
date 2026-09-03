<?php

namespace App\Http\Controllers;

use App\Models\Invite;
use App\Services\InviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class InviteController extends Controller
{
    public function __construct(private readonly InviteService $invites) {}

    public function index(Request $request): Response
    {
        $invites = $request->user()
            ->invitesIssued()
            ->with('redemptions.user')
            ->latest()
            ->get()
            ->map(fn (Invite $invite) => [
                'id' => $invite->id,
                'code' => $invite->code,
                'redemption_count' => $invite->redemptions->count(),
                'max_uses' => $invite->max_uses,
                'expires_at' => $invite->expires_at?->toDateString(),
                'source' => $invite->source_share_link_id ? 'share-link CTA' : 'manual',
            ]);

        return Inertia::render('Invites/Index', ['invites' => $invites]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_in_days' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->invites->issue(
            inviter: $request->user(),
            maxUses: $data['max_uses'] ?? null,
            expiresAt: isset($data['expires_in_days'])
                ? Carbon::now()->addDays($data['expires_in_days'])
                : null,
        );

        return redirect()->route('invites.index');
    }

    public function destroy(Request $request, string $invite): RedirectResponse
    {
        // forceDelete(), not delete() — see ActivityRoleController::destroy's
        // comment: SoftDeletes on this model exists only for account-wide
        // deletion, not this single-record user action.
        $request->user()
            ->invitesIssued()
            ->where('id', $invite)
            ->firstOrFail()
            ->forceDelete();

        return redirect()->route('invites.index');
    }
}
