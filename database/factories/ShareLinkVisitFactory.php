<?php

namespace Database\Factories;

use App\Models\ShareLink;
use App\Models\ShareLinkVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShareLinkVisit>
 */
class ShareLinkVisitFactory extends Factory
{
    protected $model = ShareLinkVisit::class;

    public function definition(): array
    {
        return [
            'share_link_id' => ShareLink::factory(),
            'timezone' => $this->faker->randomElement(['UTC', 'Europe/Budapest', 'America/New_York', 'Asia/Tokyo']),
            'locale' => $this->faker->randomElement(['en-US', 'hu-HU', 'ja-JP', null]),
        ];
    }
}
