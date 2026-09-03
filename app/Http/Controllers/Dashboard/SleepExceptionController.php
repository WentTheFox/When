<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * label_ciphertext is client-vault E2EE (§0.1/§0.3, see the migration's doc
 * comment) — this controller only ever stores ciphertext the client already
 * produced. start_date/end_date stay plaintext since §5.1's recompute needs
 * them to suppress the default sleep block.
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
        // forceDelete(), not delete() — see ActivityRoleController::destroy's
        // comment: SoftDeletes on this model exists only for account-wide
        // deletion, not this single-record user action.
        $request->user()->sleepExceptions()->where('id', $sleepException)->firstOrFail()->forceDelete();

        return response()->json(['status' => 'ok']);
    }
}
