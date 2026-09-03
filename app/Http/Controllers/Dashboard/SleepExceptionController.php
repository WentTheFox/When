<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * label_ciphertext is client-vault E2EE (§0.1/§0.3, see the migration's doc
 * comment) — this controller only ever stores ciphertext the client already
 * produced. start_date/end_date are §0.2 server-runtime Crypt/APP_KEY
 * ciphertext instead (see SleepException::casts()) — this controller still
 * only ever handles their plaintext 'Y-m-d' form; the model's 'encrypted'
 * cast transparently encrypts on write/decrypts on read, so §5.1's
 * recompute (which needs the plain dates to suppress the default sleep
 * block) is unaffected.
 */
class SleepExceptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid', 'unique:sleep_exceptions,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'label_ciphertext' => ['nullable', 'string'],
        ]);

        $exception = $request->user()->sleepExceptions()->create($data);

        return response()->json(['id' => $exception->id], 201);
    }

    public function destroy(Request $request, string $sleepException): JsonResponse
    {
        // forceDelete(), not delete() — see ActivityLocalizationController::destroy's
        // comment: SoftDeletes on this model exists only for account-wide
        // deletion, not this single-record user action.
        $request->user()->sleepExceptions()->where('id', $sleepException)->firstOrFail()->forceDelete();

        return response()->json(['status' => 'ok']);
    }
}
