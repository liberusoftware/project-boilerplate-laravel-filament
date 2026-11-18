<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Liberu\Foundation\RolesPermissions\Support\PermissionCatalog;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Mints the app-panel permission catalog and assigns the system-role
        // matrix. Idempotent — see Liberu\Foundation\RolesPermissions\Support\PermissionCatalog.
        PermissionCatalog::sync();
    }
}
