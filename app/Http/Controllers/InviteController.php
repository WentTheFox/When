<?php

namespace App\Http\Controllers;

use App\Services\InviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class InviteController extends Controller
{
    public function __construct(private readonly InviteService $invites) {}

    public function index(Request $request): View
    {
        $invites = $request->user()
            ->invitesIssued()
            ->with('redemptions.user')
            ->latest()
            ->get();

        return view('invites.index', ['invites' => $invites]);
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
        $request->user()
            ->invitesIssued()
            ->where('id', $invite)
            ->firstOrFail()
            ->delete();

        return redirect()->route('invites.index');
    }
}
