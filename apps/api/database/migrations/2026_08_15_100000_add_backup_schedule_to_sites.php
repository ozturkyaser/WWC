<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // {enabled: bool, time: "02:30", weekly_full_day: 0-6, incremental_daily: bool}
            $table->json('backup_schedule')->nullable()->after('maintenance_agent_meta');
            $table->timestamp('backup_last_scheduled_at')->nullable()->after('backup_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['backup_schedule', 'backup_last_scheduled_at']);
        });
    }
};
