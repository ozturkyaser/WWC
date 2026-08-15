<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // {status, port, url, backup_id, php_image, admin_user, admin_pass_encrypted, error, built_at}
            $table->json('dev_clone')->nullable()->after('backup_last_scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('dev_clone');
        });
    }
};
