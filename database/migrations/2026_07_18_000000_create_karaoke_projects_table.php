<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('karaoke_projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 191);
            $table->string('artist', 191)->nullable();
            $table->string('original_filename', 191);
            $table->string('source_path');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status')->default('uploaded');
            $table->string('processing_stage')->nullable();
            $table->unsignedInteger('progress')->default(0);
            $table->timestamp('rights_confirmed_at');
            $table->timestamp('provider_consent_confirmed_at')->nullable();
            $table->json('transcript')->nullable();
            $table->json('theme')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karaoke_projects');
    }
};
