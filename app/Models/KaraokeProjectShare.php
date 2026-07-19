<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class KaraokeProjectShare extends Model
{
    protected $fillable = [
        'public_id',
        'karaoke_project_id',
        'created_by_user_id',
        'token_hash',
        'token_ciphertext',
        'expires_at',
        'revoked_at',
        'embedding_enabled',
        'embed_allowed_origins',
        'embedding_updated_at',
    ];

    protected $hidden = [
        'token_hash',
        'token_ciphertext',
        'id',
        'karaoke_project_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'embedding_enabled' => 'boolean',
            'embed_allowed_origins' => 'array',
            'embedding_updated_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function karaokeProject(): BelongsTo
    {
        return $this->belongsTo(KaraokeProject::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function hasEmbedAllowlist(): bool
    {
        return is_array($this->embed_allowed_origins) && $this->embed_allowed_origins !== [];
    }

    public function isEmbeddingActive(): bool
    {
        return $this->isActive()
            && $this->embedding_enabled
            && $this->hasEmbedAllowlist();
    }

    protected static function booted(): void
    {
        static::creating(function (KaraokeProjectShare $share): void {
            if (empty($share->public_id)) {
                $share->public_id = (string) Str::uuid();
            }
        });
    }
}
