<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ConnectionEdge;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Knows" edges between two of the owner's connections. They are undirected
 * and carry no data of their own: at most one edge exists per pair of
 * connections, whichever side it was created from (enforced by the
 * connection_edges_pair_unique index; creating one that already exists
 * returns the existing edge).
 */
class ConnectionEdgeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_connection_id' => ['required', 'uuid', 'exists:connections,id'],
            'to_connection_id' => ['required', 'uuid', 'exists:connections,id', 'different:from_connection_id'],
        ]);

        $this->assertOwned($request, $data['from_connection_id']);
        $this->assertOwned($request, $data['to_connection_id']);

        $existing = $this->findBetween($request, $data['from_connection_id'], $data['to_connection_id']);
        if ($existing !== null) {
            return response()->json($this->serialize($existing));
        }

        try {
            $edge = $request->user()->connectionEdges()->create($data);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with an identical request; the other one won.
            $edge = $this->findBetween($request, $data['from_connection_id'], $data['to_connection_id']);

            return response()->json($this->serialize($edge));
        }

        return response()->json($this->serialize($edge), 201);
    }

    public function destroy(Request $request, string $edge): JsonResponse
    {
        // forceDelete(), not delete() — see ActivityLocalizationController::destroy's
        // comment: SoftDeletes on this model exists only for account-wide
        // deletion, not this single-record user action.
        $request->user()->connectionEdges()->where('id', $edge)->firstOrFail()->forceDelete();

        return response()->json(['status' => 'ok']);
    }

    private function findBetween(Request $request, string $a, string $b): ?ConnectionEdge
    {
        return $request->user()->connectionEdges()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('from_connection_id', $a)->where('to_connection_id', $b))
                ->orWhere(fn ($q) => $q->where('from_connection_id', $b)->where('to_connection_id', $a)))
            ->first();
    }

    /** @return array{id: string, from_connection_id: string, to_connection_id: string} */
    private function serialize(ConnectionEdge $edge): array
    {
        return [
            'id' => $edge->id,
            'from_connection_id' => $edge->from_connection_id,
            'to_connection_id' => $edge->to_connection_id,
        ];
    }

    private function assertOwned(Request $request, string $connectionId): void
    {
        $request->user()->connections()->where('id', $connectionId)->firstOrFail();
    }
}
