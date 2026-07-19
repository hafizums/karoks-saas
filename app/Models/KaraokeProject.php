<?php

namespace App\Models;

use App\Enums\KaraokeProjectStatus;
use App\Support\Karaoke\Processing\KaraokeProcessingNotificationService;
use App\Support\KaraokeProcessingStateService;
use App\Support\KaraokeStorage;
use App\Support\KaraokeTranscriptParser;
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
        'instrumental_path',
        'instrumental_mime_type',
        'mime_type',
        'size_bytes',
        'duration_seconds',
        'status',
        'processing_stage',
        'processing_run_id',
        'processing_attempts',
        'queued_at',
        'processing_started_at',
        'processing_completed_at',
        'processing_failed_at',
        'processing_heartbeat_at',
        'progress',
        'rights_confirmed_at',
        'provider_consent_confirmed_at',
        'processing_driver',
        'provider_checkpoint_run_id',
        'provider_checkpoint_attempt',
        'wavespeed_prediction_id',
        'wavespeed_prediction_failed_at',
        'provider_separation_completed_at',
        'provider_transcript_checkpoint',
        'processing_retryable',
        'transcript',
        'theme',
        'editor_revision',
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
            'processing_attempts' => 'integer',
            'rights_confirmed_at' => 'datetime',
            'provider_consent_confirmed_at' => 'datetime',
            'provider_separation_completed_at' => 'datetime',
            'wavespeed_prediction_failed_at' => 'datetime',
            'provider_checkpoint_attempt' => 'integer',
            'provider_transcript_checkpoint' => 'array',
            'processing_retryable' => 'boolean',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processing_completed_at' => 'datetime',
            'processing_failed_at' => 'datetime',
            'processing_heartbeat_at' => 'datetime',
            'transcript' => 'array',
            'theme' => 'array',
            'editor_revision' => 'integer',
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

    /**
     * @return array{version: int, lines: list<array{id: string, start: float, end: float, words: list<array{id: string, text: string, start: float, end: float}>}>}|null
     */
    public function parsedTranscript(): ?array
    {
        return KaraokeTranscriptParser::parse($this->transcript);
    }

    public function hasPlayableTranscript(): bool
    {
        return $this->parsedTranscript() !== null;
    }

    public function hasEditableTranscript(): bool
    {
        return $this->hasPlayableTranscript();
    }

    public function hasProcessedInstrumental(): bool
    {
        if (! $this->instrumental_path) {
            return false;
        }

        return KaraokeStorage::disk()->exists($this->instrumental_path);
    }

    public function isReadyForPlayback(): bool
    {
        return $this->status === KaraokeProjectStatus::Completed
            && $this->hasPlayableTranscript()
            && $this->hasProcessedInstrumental();
    }

    public function isReadyForEditing(): bool
    {
        return $this->isReadyForPlayback();
    }

    public function playbackAudioPath(): ?string
    {
        if ($this->status === KaraokeProjectStatus::Completed && $this->hasProcessedInstrumental()) {
            return $this->instrumental_path;
        }

        return null;
    }

    public function playbackMimeType(): ?string
    {
        if ($this->status === KaraokeProjectStatus::Completed && $this->hasProcessedInstrumental()) {
            return $this->instrumental_mime_type ?: $this->mime_type;
        }

        return null;
    }

    public function mockProcessingDisclosure(): ?string
    {
        if ($this->status !== KaraokeProjectStatus::Completed) {
            return null;
        }

        return $this->error_message;
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
            app(KaraokeProcessingStateService::class)->releaseUsageForDeletedProject($project);
            app(KaraokeProcessingNotificationService::class)->cleanupForProject($project);
            KaraokeStorage::deleteProjectFiles($project);
        });
    }
}
