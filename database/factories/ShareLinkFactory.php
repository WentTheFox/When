<?php

namespace Database\Factories;

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
            'label_ciphertext' => null,
            'archived' => false,
            'bypass_dnd' => false,
            // Every real share link gets one at creation (see
            // ShareLinkManagementController::store()) — a factory-made one
            // should too, so a test resolving `/free/{link->highlight_token}`
            // works without every call site overriding this explicitly.
            'highlight_token' => ShareLink::generateHighlightToken(),
        ];
    }
}
