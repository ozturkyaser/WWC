<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('maintenance_agent_enabled')->default(true)->after('onboarding_meta');
            $table->string('maintenance_cadence', 20)->default('weekly')->after('maintenance_agent_enabled');
            $table->boolean('maintenance_auto_apply')->default(false)->after('maintenance_cadence');
            $table->timestamp('maintenance_last_run_at')->nullable()->after('maintenance_auto_apply');
            $table->timestamp('maintenance_next_run_at')->nullable()->after('maintenance_last_run_at');
            $table->json('maintenance_agent_meta')->nullable()->after('maintenance_next_run_at');
        });

        Schema::create('maintenance_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('site_id')->index();
            $table->string('trigger', 30)->default('manual'); // manual|daily|weekly|monthly|schedule
            $table->string('status', 40)->default('pending');
            // pending|auditing|planned|dry_running|applying|completed|failed|needs_review
            $table->json('audit')->nullable();
            $table->json('plan')->nullable();
            $table->text('ai_summary')->nullable();
            $table->text('technician_notes')->nullable();
            $table->uuid('dry_run_job_id')->nullable();
            $table->uuid('live_job_id')->nullable();
            $table->uuid('staging_job_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_runs');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'maintenance_agent_enabled',
                'maintenance_cadence',
                'maintenance_auto_apply',
                'maintenance_last_run_at',
                'maintenance_next_run_at',
                'maintenance_agent_meta',
            ]);
        });
    }
};
