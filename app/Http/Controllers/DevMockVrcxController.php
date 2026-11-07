<?php

namespace App\Http\Controllers;

use App\Support\DevMockVrchat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A fake VRCX headless server's external API for local development and demos
 * (ConnectionsDemoSeeder points the demo account's VRCX URL at it), so the whole VRCX sync
 * (profiles, time together, friends, notes, Discord ids) runs without a real VRCX server or VRChat account.
 *
 * Answers from DevMockVrchat's invented accounts, accepts only DevMockVrchat::API_KEY, and is
 * only registered outside production (routes/web.php). Mirrors the parts of the real API
 * App\Services\Vrcx\VrcxClient calls.
 */
class DevMockVrcxController extends Controller
{
    public function openapi(): JsonResponse
    {
        return response()->json([
            'openapi' => '3.0.3',
            'info' => ['title' => 'VRCX Headless External API (dev mock)', 'version' => '1'],
            'paths' => [
                '/api/v1/users' => ['post' => new \stdClass],
                '/api/v1/users/{userId}' => ['get' => new \stdClass],
                '/api/v1/time-together' => ['get' => new \stdClass, 'post' => new \stdClass],
                '/api/v1/friends' => ['get' => new \stdClass],
            ],
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }

        $users = [];
        foreach ((array) $request->input('ids', []) as $id) {
            $n = is_string($id) ? DevMockVrchat::numberOf($id) : null;

            if ($n !== null) {
                $users[$id] = DevMockVrchat::profile($n);
            }
        }

        return response()->json(['users' => (object) $users]);
    }

    public function user(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }

        $n = DevMockVrchat::numberOf($id);

        return $n === null
            ? response()->json(['ok' => false, 'error' => 'User not found'], 404)
            : response()->json(['user' => DevMockVrchat::profile($n)]);
    }

    public function timeTogether(Request $request): JsonResponse
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }

        $days = max(1, (int) $request->input('days', 90));
        $limit = max(1, min(500, (int) $request->input('limit', 500)));
        $offset = max(0, (int) $request->input('offset', 0));

        $users = [];
        foreach ((array) $request->input('ids', []) as $id) {
            $n = is_string($id) ? DevMockVrchat::numberOf($id) : null;

            $users[$id] = ['results' => $n === null ? [] : array_slice(DevMockVrchat::intervals($n, $days), $offset, $limit)];
        }

        return response()->json(['users' => (object) $users]);
    }

    public function friends(Request $request): JsonResponse
    {
        if ($denied = $this->denied($request)) {
            return $denied;
        }

        $limit = max(1, min(500, (int) $request->query('limit', 500)));
        $offset = max(0, (int) $request->query('offset', 0));

        $all = [];
        for ($n = 1; $n <= DevMockVrchat::USER_COUNT; $n++) {
            $profile = DevMockVrchat::profile($n);
            $all[] = ['id' => DevMockVrchat::userId($n), 'displayName' => $profile['displayName'], 'profilePictureUrl' => $profile['profilePictureUrl']];
        }

        return response()->json(['results' => array_slice($all, $offset, $limit)]);
    }

    public function avatar(int $n): Response
    {
        abort_unless($n >= 1 && $n <= DevMockVrchat::USER_COUNT, 404);

        return response(DevMockVrchat::avatarSvg($n), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** 401 unless the request carries the mock's API key as a bearer token. */
    private function denied(Request $request): ?JsonResponse
    {
        return hash_equals(DevMockVrchat::API_KEY, (string) $request->bearerToken())
            ? null
            : response()->json(['ok' => false, 'error' => 'Invalid API key'], 401);
    }
}
