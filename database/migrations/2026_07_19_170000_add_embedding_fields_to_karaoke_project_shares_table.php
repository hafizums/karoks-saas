<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('karaoke_project_shares', function (Blueprint $table) {
            $table->boolean('embedding_enabled')->default(false)->after('revoked_at');
            $table->json('embed_allowed_origins')->nullable()->after('embedding_enabled');
            $table->timestamp('embedding_updated_at')->nullable()->after('embed_allowed_origins');
        });
    }

    public function down(): void
    {
        Schema::table('karaoke_project_shares', function (Blueprint $table) {
            $table->dropColumn([
                'embedding_enabled',
                'embed_allowed_origins',
                'embedding_updated_at',
            ]);
        });
    }
};
