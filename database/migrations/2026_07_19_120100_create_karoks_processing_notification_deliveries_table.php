<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('karoks_processing_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('karaoke_project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 255)->unique();
            $table->string('event_type', 64);
            $table->uuid('project_public_id');
            $table->uuid('processing_run_id');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karoks_processing_notification_deliveries');
    }
};
