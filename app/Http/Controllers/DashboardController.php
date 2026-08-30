<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * STUB pending Stage 7's full dashboard build (settings, share-link
     * management, Connections CRM, invite management UI). For now this is
     * just the post-login landing page auth flows redirect to.
     */
    public function index(Request $request): View
    {
        return view('dashboard.index', ['user' => $request->user()]);
    }
}
