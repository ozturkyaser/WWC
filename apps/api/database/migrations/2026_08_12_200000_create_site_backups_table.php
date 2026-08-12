<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('site_id')->index();
            $table->string('backup_id', 100); // agent-side id, e.g. full-20260812-...
            $table->string('type', 20)->default('full'); // full|incremental
            $table->string('label', 100)->nullable();
            $table->string('status', 20)->default('uploading'); // uploading|stored|failed
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->string('storage_path')->nullable();
            $table->string('wp_version', 20)->nullable();
            $table->unsignedInteger('file_count')->default(0);
            $table->string('parent_backup_id', 100)->nullable();
            $table->timestamp('backup_created_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'backup_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_backups');
    }
};
