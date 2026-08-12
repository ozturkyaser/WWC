<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('hour_packages')->nullable()->after('billing_profile');
            $table->json('maintenance_tiers')->nullable()->after('hour_packages');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['hour_packages', 'maintenance_tiers']);
        });
    }
};
