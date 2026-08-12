<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('staging_slug')->nullable()->unique()->after('url');
            $table->string('staging_url')->nullable()->after('staging_slug');
            $table->string('staging_admin_url')->nullable()->after('staging_url');
            $table->text('staging_access_encrypted')->nullable()->after('staging_admin_url');
            $table->timestamp('staging_ready_at')->nullable()->after('staging_access_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'staging_slug',
                'staging_url',
                'staging_admin_url',
                'staging_access_encrypted',
                'staging_ready_at',
            ]);
        });
    }
};
