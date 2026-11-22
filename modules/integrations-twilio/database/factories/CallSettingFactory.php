<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Integrations\Twilio\Models\CallSetting;

class CallSettingFactory extends Factory
{
    protected $model = CallSetting::class;

    public function definition()
    {
        return [
            'team_id' => Team::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'value' => $this->faker->word(),
            'description' => $this->faker->sentence(),
        ];
    }
}
