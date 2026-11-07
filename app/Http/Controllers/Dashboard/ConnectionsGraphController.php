<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\ConnectionAttributeValue;
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

        // Connection-to-connection edges are E2EE now (relationship fields hold the related ids under each
        // connection's own key), so the server cannot draw them: it hands over the ciphertext of the fields
        // the owner chose for the graph and the browser decrypts and draws the undirected edges.
        $types = $user->graphFields()->pluck('connection_attribute_definitions.type', 'connection_attribute_definitions.id');
        $fieldValues = ConnectionAttributeValue::whereIn('attribute_definition_id', $types->keys())
            ->whereIn('connection_id', $user->connections()->select('id'))
            ->whereNotNull('value_ciphertext')
            ->get(['connection_id', 'attribute_definition_id', 'value_ciphertext'])
            ->map(fn (ConnectionAttributeValue $v) => [
                'connection_id' => $v->connection_id,
                'attribute_definition_id' => $v->attribute_definition_id,
                'type' => $types[$v->attribute_definition_id],
                'value_ciphertext' => $v->value_ciphertext,
            ])
            ->values();

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
            'edges' => $sourceEdges->values(),
            'field_values' => $fieldValues,
        ]);
    }
}
