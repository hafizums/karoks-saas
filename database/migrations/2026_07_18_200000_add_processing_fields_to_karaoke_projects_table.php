<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('karaoke_projects', function (Blueprint $table) {
            $table->string('instrumental_path')->nullable()->after('source_path');
            $table->string('instrumental_mime_type', 127)->nullable()->after('instrumental_path');
            $table->uuid('processing_run_id')->nullable()->after('processing_stage');
            $table->unsignedInteger('processing_attempts')->default(0)->after('processing_run_id');
            $table->timestamp('queued_at')->nullable()->after('processing_attempts');
            $table->timestamp('processing_started_at')->nullable()->after('queued_at');
            $table->timestamp('processing_completed_at')->nullable()->after('processing_started_at');
            $table->timestamp('processing_failed_at')->nullable()->after('processing_completed_at');

            $table->index('processing_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('karaoke_projects', function (Blueprint $table) {
            $table->dropIndex(['processing_run_id']);
            $table->dropColumn([
                'instrumental_path',
                'instrumental_mime_type',
                'processing_run_id',
                'processing_attempts',
                'queued_at',
                'processing_started_at',
                'processing_completed_at',
                'processing_failed_at',
            ]);
        });
    }
};
