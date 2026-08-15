<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_backups', function (Blueprint $table) {
            // Gesetzt, wenn das Backup erfolgreich in einem Dev-Clone wiederhergestellt wurde
            $table->timestamp('verified_at')->nullable()->after('uploaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('site_backups', function (Blueprint $table) {
            $table->dropColumn('verified_at');
        });
    }
};
