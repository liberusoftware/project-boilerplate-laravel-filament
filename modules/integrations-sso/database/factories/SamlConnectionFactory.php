<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Integrations\Sso\Models\SamlConnection;

class SamlConnectionFactory extends Factory
{
    protected $model = SamlConnection::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'idp_entity_id' => 'https://idp.example.com/entity',
            'idp_sso_url' => 'https://idp.example.com/sso',
            'idp_x509_cert' => 'MIICertData',
            'enabled' => false,
        ];
    }
}
