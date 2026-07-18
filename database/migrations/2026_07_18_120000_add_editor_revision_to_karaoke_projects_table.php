<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('karaoke_projects', function (Blueprint $table) {
            $table->unsignedInteger('editor_revision')->default(1)->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('karaoke_projects', function (Blueprint $table) {
            $table->dropColumn('editor_revision');
        });
    }
};
