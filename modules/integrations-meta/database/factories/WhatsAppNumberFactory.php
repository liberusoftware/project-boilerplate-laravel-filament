<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Integrations\Meta\Models\WhatsAppNumber;

class WhatsAppNumberFactory extends Factory
{
    protected $model = WhatsAppNumber::class;

    public function definition(): array
    {
        // NOT NULL: number (unique), display_name. is_active defaults true.
        return [
            'number' => '+'.$this->faker->unique()->numerify('###########'),
            'display_name' => $this->faker->name(),
            'is_active' => true,
        ];
    }
}
