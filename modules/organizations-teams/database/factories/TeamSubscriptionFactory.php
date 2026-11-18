<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Models\TeamSubscription;

class TeamSubscriptionFactory extends Factory
{
    protected $model = TeamSubscription::class;

    public function definition()
    {
        return [
            'team_id' => Team::factory(),
            'stripe_id' => 'sub_'.$this->faker->md5,
            'stripe_status' => 'active',
            'stripe_price' => 'price_'.$this->faker->md5,
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
