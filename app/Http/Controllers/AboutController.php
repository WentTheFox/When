<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The about/security content (see this page's own SecurityCardBody
 * section, folded in alongside the welcome copy) lives at exactly one URL,
 * `/about` — SiteFooter.vue links there from every page, dashboard
 * included. `/` is a pure dispatcher, never rendering that content itself:
 * a logged-in visitor goes straight to their dashboard, everyone else to
 * `/about`.
 */
class AboutController extends Controller
{
    public function redirectHome(Request $request): RedirectResponse
    {
        return redirect($request->user() !== null ? '/dashboard' : '/about');
    }

    public function show(): Response
    {
        return Inertia::render('About');
    }
}
