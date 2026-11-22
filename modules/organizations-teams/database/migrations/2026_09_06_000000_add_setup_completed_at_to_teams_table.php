<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('teams') || Schema::hasColumn('teams', 'setup_completed_at')) {
            return;
        }

        Schema::table('teams', function (Blueprint $table): void {
            $table->timestamp('setup_completed_at')->nullable()->after('personal_team');
        });
    }

    public function down(): void
    {
        // This status can be owned by the host application; retain it on rollback.
    }
};
