<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('karaoke_usage_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('karaoke_project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('metric');
            $table->unsignedInteger('units')->default(1);
            $table->string('state');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('idempotency_key')->unique();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'metric', 'state']);
            $table->index(['user_id', 'metric', 'period_start', 'period_end']);
            $table->index(['karaoke_project_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('karaoke_usage_records');
    }
};
