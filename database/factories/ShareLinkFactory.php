<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShareLink>
 */
class ShareLinkFactory extends Factory
{
    protected $model = ShareLink::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Every share link belongs to a connection (of the same user).
            'connection_id' => fn (array $attributes) => Connection::factory()->create(['user_id' => $attributes['user_id']])->id,
            'archived' => false,
            // Every real share link gets one at creation (see
            // ConnectionShareLinkController::store()) — a factory-made one
            // should too, so a test resolving `/free/{link->highlight_token}`
            // works without every call site overriding this explicitly.
            'highlight_token' => ShareLink::generateHighlightToken(),
        ];
    }
}
