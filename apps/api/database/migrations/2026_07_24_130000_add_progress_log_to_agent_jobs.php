<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->json('progress_log')->nullable()->after('progress_label');
        });
    }

    public function down(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->dropColumn('progress_log');
        });
    }
};
