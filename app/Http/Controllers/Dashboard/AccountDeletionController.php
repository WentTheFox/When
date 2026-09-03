<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Controller;
use App\Services\Account\AccountDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Deletion is final from the owner's perspective — immediate logout, no
 * "cancel my deletion" flow (a soft-deleted account can no longer log in at
 * all, since SoftDeletes' global scope excludes it from
 * User::whereName()/whereEmail()). The 48h retention before
 * App\Jobs\HardDeleteExpiredAccounts erases everything is purely an internal
 * safety buffer, not a user-facing undo window.
 */
class AccountDeletionController extends Controller
{
    use ConfirmsPassword;

    public function destroy(Request $request, AccountDeletionService $service): RedirectResponse
    {
        $this->confirmPassword($request);

        $service->softDelete($request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', 'Your account has been deleted. It will be permanently erased within 48 hours.');
    }
}
