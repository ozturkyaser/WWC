<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('access_mode')->nullable()->after('paired_at');
            $table->timestamp('support_granted_at')->nullable()->after('access_mode');
            $table->timestamp('support_revoked_at')->nullable()->after('support_granted_at');
            $table->string('support_contact')->nullable()->after('support_revoked_at');
            $table->string('support_note', 500)->nullable()->after('support_contact');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'access_mode',
                'support_granted_at',
                'support_revoked_at',
                'support_contact',
                'support_note',
            ]);
        });
    }
};
