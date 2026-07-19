<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KaroksProcessingNotificationDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'karaoke_project_id',
        'idempotency_key',
        'event_type',
        'project_public_id',
        'processing_run_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function karaokeProject(): BelongsTo
    {
        return $this->belongsTo(KaraokeProject::class);
    }
}
