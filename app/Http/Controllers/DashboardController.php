<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Index', [
            'userName' => $user->name,
            'shareLinkCount' => $user->shareLinks()->where('archived', false)->count(),
            'connectionCount' => $user->connections()->count(),
            'hasCalendarUrl' => $user->calendar_url_ciphertext !== null,
        ]);
    }
}
