<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\ConnectionEdge;
use App\Models\ConnectionSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feeds the dashboard's connections-network-graph widget. Only ids and
 * color keys ever leave here — never a name — same §0.1 boundary every
 * other connections endpoint respects (the graph draws plain circles the
 * client can't label with plaintext anyway).
 */
class ConnectionsGraphController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user->id;

        $connectionNodes = $user->connections()->get(['id'])
            ->map(fn (Connection $c) => ['id' => $c->id, 'type' => 'connection', 'color_key' => null]);

        // Undirected — connection_edges has no "introduced vs mutual"
        // directionality column (unlike a source link, which is always a
        // "met via" relationship in one direction), so no arrowhead for
        // these when the frontend draws them.
        $connectionEdges = $user->connectionEdges()->get(['from_connection_id', 'to_connection_id'])
            ->map(fn (ConnectionEdge $e) => [
                'from' => $e->from_connection_id,
                'to' => $e->to_connection_id,
                'kind' => 'mutual',
            ]);

        $sources = $user->connectionSources()->with(['category', 'connections'])->get();

        $sourceNodes = $sources->map(fn (ConnectionSource $s) => [
            'id' => $s->id,
            'type' => 'source',
            'color_key' => $s->category?->color_key,
        ]);

        $sourceEdges = $sources->flatMap(fn (ConnectionSource $s) => $s->connections->map(fn (Connection $c) => [
            'from' => $c->id,
            'to' => $s->id,
            'kind' => 'introduced',
        ]));

        return response()->json([
            // Deterministic per user, so the client-side layout simulation
            // produces the same graph on every reload instead of a fresh
            // random scatter each time.
            'seed' => crc32($userId),
            'nodes' => $connectionNodes->concat($sourceNodes)->values(),
            'edges' => $connectionEdges->concat($sourceEdges)->values(),
        ]);
    }
}
