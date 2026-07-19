<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    public function up(): void
    {
        DB::table('karaoke_projects')
            ->whereNull('processing_heartbeat_at')
            ->whereIn('status', ['queued', 'processing'])
            ->where(function ($query): void {
                $query->whereNotNull('processing_started_at')
                    ->orWhereNotNull('queued_at');
            })
            ->update([
                'processing_heartbeat_at' => DB::raw('COALESCE(processing_started_at, queued_at)'),
            ]);
    }

    public function down(): void
    {
        // Non-destructive backfill; no rollback required.
    }
};
