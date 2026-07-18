<?php

namespace App\Models;

use Database\Factories\KaraokeUsageRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class KaraokeUsageRecord extends Model
{
    /** @use HasFactory<KaraokeUsageRecordFactory> */
    use HasFactory;

    public const STATE_RESERVED = 'reserved';

    public const STATE_CONSUMED = 'consumed';

    public const STATE_RELEASED = 'released';

    protected $fillable = [
        'public_id',
        'user_id',
        'karaoke_project_id',
        'metric',
        'units',
        'state',
        'period_start',
        'period_end',
        'idempotency_key',
        'reserved_at',
        'consumed_at',
        'released_at',
        'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'reserved_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
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

    public function karaokeProject(): BelongsTo
    {
        return $this->belongsTo(KaraokeProject::class);
    }

    public function countsAgainstAllowance(): bool
    {
        return in_array($this->state, [self::STATE_RESERVED, self::STATE_CONSUMED], true);
    }

    protected static function booted(): void
    {
        static::creating(function (KaraokeUsageRecord $record): void {
            if (empty($record->public_id)) {
                $record->public_id = (string) Str::uuid();
            }
        });
    }
}
