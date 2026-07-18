<?php

namespace App\Models;

use App\Enums\KaraokeProjectStatus;
use App\Support\KaraokeStorage;
use Database\Factories\KaraokeProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class KaraokeProject extends Model
{
    /** @use HasFactory<KaraokeProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'user_id',
        'title',
        'artist',
        'original_filename',
        'source_path',
        'mime_type',
        'size_bytes',
        'duration_seconds',
        'status',
        'processing_stage',
        'progress',
        'rights_confirmed_at',
        'provider_consent_confirmed_at',
        'transcript',
        'theme',
        'error_code',
        'error_message',
    ];

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'status' => KaraokeProjectStatus::class,
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'progress' => 'integer',
            'rights_confirmed_at' => 'datetime',
            'provider_consent_confirmed_at' => 'datetime',
            'transcript' => 'array',
            'theme' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storageDirectory(): string
    {
        return 'karaoke/'.$this->user_id.'/'.$this->public_id;
    }

    protected static function booted(): void
    {
        static::creating(function (KaraokeProject $project): void {
            if (empty($project->public_id)) {
                $project->public_id = (string) Str::uuid();
            }

            if (empty($project->status)) {
                $project->status = KaraokeProjectStatus::Uploaded;
            }
        });

        static::deleting(function (KaraokeProject $project): void {
            KaraokeStorage::deleteProjectFiles($project);
        });
    }
}
