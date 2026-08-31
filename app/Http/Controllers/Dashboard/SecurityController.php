<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard-side entry point for account-security settings (currently just
 * two-factor status). TwoFactorController itself already had a working
 * setup/disable flow and route (/two-factor) — the gap this closes is that
 * nothing in the UI ever linked to it, so it was only reachable by typing
 * the URL directly.
 */
class SecurityController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Dashboard/Security', [
            'twoFactorEnabled' => $request->user()->two_factor_confirmed_at !== null,
        ]);
    }
}
