<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Support\SortOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saves the order of one of the Connections page's master lists. The client
 * sends the ids in the order it wants (names are E2EE, so only it can sort
 * them alphabetically); the page then renders from that order on every load.
 */
class ListOrderController extends Controller
{
    public const TABLES = [
        'connections' => 'connections',
        'sources' => 'connection_sources',
        'categories' => 'connection_source_categories',
        'attributes' => 'connection_attribute_definitions',
    ];

    public function __invoke(Request $request, string $kind): JsonResponse
    {
        abort_unless(isset(self::TABLES[$kind]), 404);

        $data = $request->validate([
            'ids' => ['required', 'array', 'max:5000'],
            'ids.*' => ['uuid', 'distinct'],
        ]);

        $updated = SortOrder::apply(self::TABLES[$kind], $request->user()->id, $data['ids']);

        return response()->json(['updated' => $updated]);
    }
}
