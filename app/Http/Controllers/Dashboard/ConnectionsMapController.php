<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Discord\DiscordSyncService;
use App\Services\Telegram\TelegramSyncService;
use App\Services\Vrcx\VrcxSyncService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The explorable connections map. Like the Connections page it hands over only ciphertext names (the browser
 * decrypts them) plus the §0.2-tier linked-account summaries the profile pictures come from.
 */
class ConnectionsMapController extends Controller
{
    public function __construct(private readonly VrcxSyncService $vrcx, private readonly DiscordSyncService $discord, private readonly TelegramSyncService $telegram) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Map', [
            'connections' => $user->connections()->get(['id', 'name_ciphertext', 'preferred_identity'])
                ->map(fn ($c) => ['id' => $c->id, 'name_ciphertext' => $c->name_ciphertext, 'preferred_identity' => $c->preferred_identity]),
            'sources' => $user->connectionSources()->get(['id', 'category_id', 'name_ciphertext', 'icon_ciphertext']),
            // Only the icon: a source without its own inherits its category's.
            'categories' => $user->connectionSourceCategories()->get(['id', 'icon_ciphertext']),
            'vrchat' => $this->vrcx->summary($user),
            'discord' => $this->discord->summary($user),
            'telegram' => $this->telegram->summary($user),
        ]);
    }
}
