<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ExternalFetchJob extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'claimed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ExternalFetchJobBatch::class);
    }

    public function monitoringRule(): BelongsTo
    {
        return $this->belongsTo(MonitoringRule::class);
    }

    /** @return array<string, mixed> */
    public function botPayload(): array
    {
        return ['jobId' => (string) $this->getKey()] + $this->payload;
    }

    public static function releaseStale(): int
    {
        $cutoff = now()->subSeconds((int) config('biodiversity.faune_france_bot_stale_seconds', 300));

        return self::query()
            ->whereIn('status', [self::STATUS_CLAIMED, self::STATUS_RUNNING])
            ->where(function ($query) use ($cutoff): void {
                $query->where('heartbeat_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('heartbeat_at')->where('updated_at', '<', $cutoff);
                    });
            })
            ->update([
                'status' => self::STATUS_PENDING,
                'claimed_at' => null,
                'started_at' => null,
                'heartbeat_at' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);
    }
}
