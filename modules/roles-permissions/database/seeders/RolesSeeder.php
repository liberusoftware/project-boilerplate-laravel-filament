<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Liberu\Foundation\RolesPermissions\Support\PermissionCatalog;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        // Creates the six global system roles (team_id = null), mints the
        // app-panel permission catalog, and assigns the system-role matrix.
        // Idempotent — see Liberu\Foundation\RolesPermissions\Support\PermissionCatalog.
        PermissionCatalog::sync();
    }
}
