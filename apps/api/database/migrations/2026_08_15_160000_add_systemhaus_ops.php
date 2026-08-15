<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('monitor')->nullable();
            $table->timestamp('freeze_until')->nullable();
            $table->string('freeze_reason')->nullable();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('alert_settings')->nullable();
            $table->json('hardening_templates')->nullable();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->date('contract_until')->nullable();
            $table->unsignedSmallInteger('sla_response_hours')->nullable();
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('project_id')->nullable();
            $table->uuid('site_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->unsignedInteger('minutes');
            $table->string('description')->nullable();
            $table->boolean('billable')->default(true);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['project_id', 'occurred_at']);
        });

        Schema::create('organization_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('email');
            $table->string('role')->default('technician');
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invites');
        Schema::dropIfExists('time_entries');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['phone', 'notes', 'contract_until', 'sla_response_hours']);
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['alert_settings', 'hardening_templates']);
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['monitor', 'freeze_until', 'freeze_reason']);
        });
    }
};
