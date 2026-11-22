<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sso_connections')) {
            return;
        }

        Schema::table('sso_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('sso_connections', 'role_mappings')) {
                $table->json('role_mappings')->nullable()->after('token_auth_method');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sso_connections')) {
            return;
        }

        Schema::table('sso_connections', function (Blueprint $table): void {
            if (Schema::hasColumn('sso_connections', 'role_mappings')) {
                $table->dropColumn('role_mappings');
            }
        });
    }
};
