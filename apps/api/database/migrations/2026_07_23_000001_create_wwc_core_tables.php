<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('billing_profile')->nullable();
            $table->string('patchstack_api_key')->nullable();
            $table->unsignedTinyInteger('billing_day')->default(1);
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('technician'); // owner|admin|technician
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->text('address')->nullable();
            $table->string('vat_id')->nullable();
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('client_id');
            $table->string('name');
            $table->json('scope')->nullable();
            $table->unsignedInteger('monthly_budget_cents')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('client_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('name');
            $table->string('url');
            $table->string('status')->default('pending'); // pending|online|offline|error
            $table->string('wp_version')->nullable();
            $table->string('php_version')->nullable();
            $table->string('agent_version')->nullable();
            $table->text('hmac_secret_encrypted')->nullable();
            $table->text('hmac_secret_previous_encrypted')->nullable();
            $table->string('key_id')->nullable();
            $table->json('ip_allowlist')->nullable();
            $table->json('health')->nullable();
            $table->json('inventory')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });

        Schema::create('pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->uuid('site_id');
            $table->string('code', 32)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });

        Schema::create('site_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('site_id');
            $table->string('type');
            $table->string('severity')->default('info'); // info|warning|critical
            $table->string('title');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['site_id', 'occurred_at']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });

        Schema::create('agent_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('site_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('command');
            $table->json('payload')->nullable();
            $table->string('status')->default('pending'); // pending|running|completed|failed
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('site_id')->nullable();
            $table->string('action');
            $table->json('meta')->nullable();
            $table->string('ip')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('vulnerabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source'); // wordpress_org|patchstack|manual
            $table->string('external_id')->nullable();
            $table->string('slug');
            $table->string('component_type'); // plugin|theme|core
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity'); // low|medium|high|critical
            $table->string('affected_versions')->nullable();
            $table->string('fixed_in')->nullable();
            $table->string('cve')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_id']);
            $table->index(['slug', 'component_type']);
        });

        Schema::create('vulnerability_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('site_id');
            $table->uuid('vulnerability_id');
            $table->string('status')->default('open'); // open|fixed|ignored
            $table->string('installed_version')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'vulnerability_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('vulnerability_id')->references('id')->on('vulnerabilities')->cascadeOnDelete();
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'year']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('client_id');
            $table->uuid('project_id')->nullable();
            $table->string('number')->unique();
            $table->string('status')->default('draft'); // draft|sent|paid|cancelled
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(19);
            $table->boolean('small_business')->default(false);
            $table->string('currency', 3)->default('EUR');
            $table->string('pdf_path')->nullable();
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('total_cents');
            $table->timestamps();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('current_organization_id')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('current_organization_id');
        });
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('vulnerability_findings');
        Schema::dropIfExists('vulnerabilities');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('agent_jobs');
        Schema::dropIfExists('site_events');
        Schema::dropIfExists('pairing_codes');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('organizations');
    }
};
