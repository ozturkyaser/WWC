<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vulnerabilities', function (Blueprint $table) {
            $table->decimal('cvss', 3, 1)->nullable()->after('severity');
            $table->unsignedTinyInteger('patch_priority')->nullable()->after('cvss');
            $table->boolean('is_exploited')->default(false)->after('patch_priority');
            $table->unsignedInteger('priority_score')->default(0)->after('is_exploited');
            $table->string('url')->nullable()->after('cve');
            $table->timestamp('disclosed_at')->nullable()->after('url');
            $table->index(['priority_score', 'severity']);
            $table->index('disclosed_at');
        });

        Schema::table('vulnerability_findings', function (Blueprint $table) {
            $table->unsignedInteger('priority_score')->default(0)->after('status');
            $table->index(['organization_id', 'priority_score']);
        });
    }

    public function down(): void
    {
        Schema::table('vulnerability_findings', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'priority_score']);
            $table->dropColumn('priority_score');
        });

        Schema::table('vulnerabilities', function (Blueprint $table) {
            $table->dropIndex(['priority_score', 'severity']);
            $table->dropIndex(['disclosed_at']);
            $table->dropColumn([
                'cvss', 'patch_priority', 'is_exploited', 'priority_score', 'url', 'disclosed_at',
            ]);
        });
    }
};
