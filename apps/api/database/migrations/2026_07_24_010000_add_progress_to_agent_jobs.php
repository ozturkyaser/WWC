<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress')->nullable()->after('status');
            $table->string('progress_label')->nullable()->after('progress');
        });
    }

    public function down(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->dropColumn(['progress', 'progress_label']);
        });
    }
};
