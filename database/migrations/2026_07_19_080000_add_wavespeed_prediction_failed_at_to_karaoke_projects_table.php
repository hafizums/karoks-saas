<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karaoke_projects', function (Blueprint $table) {
            $table->timestamp('wavespeed_prediction_failed_at')->nullable()->after('provider_separation_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('karaoke_projects', function (Blueprint $table) {
            $table->dropColumn('wavespeed_prediction_failed_at');
        });
    }
};
