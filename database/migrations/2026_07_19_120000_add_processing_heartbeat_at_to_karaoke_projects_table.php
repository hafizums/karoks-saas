<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('karaoke_projects', function (Blueprint $table) {
            $table->timestamp('processing_heartbeat_at')->nullable()->after('processing_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('karaoke_projects', function (Blueprint $table) {
            $table->dropColumn('processing_heartbeat_at');
        });
    }
};
