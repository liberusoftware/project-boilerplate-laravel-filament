<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teams') && ! Schema::hasColumn('teams', 'settings')) {
            Schema::table('teams', fn (Blueprint $table) => $table->text('settings')->nullable());
        }
    }

    public function down(): void
    {
        // Preserve encrypted workspace credentials when rolling back application code.
    }
};
