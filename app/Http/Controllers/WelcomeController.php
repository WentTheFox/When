<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect('/dashboard');
        }

        return Inertia::render('Welcome');
    }
}
