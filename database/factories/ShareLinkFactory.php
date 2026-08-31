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
        ];
    }
}
