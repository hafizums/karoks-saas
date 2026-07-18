<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('karaoke_projects', function (Blueprint $table) {
            $table->string('processing_driver')->nullable()->after('processing_attempts');
            $table->uuid('provider_checkpoint_run_id')->nullable()->after('processing_driver');
            $table->unsignedInteger('provider_checkpoint_attempt')->nullable()->after('provider_checkpoint_run_id');
            $table->string('wavespeed_prediction_id')->nullable()->after('provider_checkpoint_attempt');
            $table->timestamp('provider_separation_completed_at')->nullable()->after('wavespeed_prediction_id');
            $table->json('provider_transcript_checkpoint')->nullable()->after('provider_separation_completed_at');
            $table->boolean('processing_retryable')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('karaoke_projects', function (Blueprint $table) {
            $table->dropColumn([
                'processing_driver',
                'provider_checkpoint_run_id',
                'provider_checkpoint_attempt',
                'wavespeed_prediction_id',
                'provider_separation_completed_at',
                'provider_transcript_checkpoint',
                'processing_retryable',
            ]);
        });
    }
};
