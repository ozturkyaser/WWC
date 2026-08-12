<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('maintenance_tier')->nullable()->after('monthly_budget_cents');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->string('onboarding_status')->nullable()->after('status');
            // pending|awaiting_pair|awaiting_backup|awaiting_staging|done|failed
            $table->json('onboarding_meta')->nullable()->after('onboarding_status');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('maintenance_tier');
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['onboarding_status', 'onboarding_meta']);
        });
    }
};
