<?php

namespace App\Support;

use App\Models\ContentBlueprint;
use Illuminate\Support\Facades\Cache;

class BlueprintProgress
{
    public static function key(int $blueprintId): string
    {
        return 'blueprint_progress_'.$blueprintId;
    }

    public static function set(int $blueprintId, int $percent, string $message, string $status = 'generating'): void
    {
        $percent = max(0, min(100, $percent));
        $payload = [
            'percent' => $percent,
            'message' => $message,
            'status' => $status,
            'updated_at' => now()->toIso8601String(),
        ];

        Cache::put(self::key($blueprintId), $payload, now()->addHours(2));

        // Persist ringkas di DB supaya polling show tetap kebaca walau cache beda worker.
        if ($status === 'generating') {
            try {
                ContentBlueprint::query()->where('id', $blueprintId)->update([
                    'story_concept' => "Progress {$percent}% — {$message}",
                    'updated_at' => now(),
                ]);
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    /**
     * @return array{percent:int,message:string,status:string,updated_at:?string}
     */
    public static function get(int $blueprintId): array
    {
        $cached = Cache::get(self::key($blueprintId));
        if (is_array($cached) && isset($cached['percent'])) {
            return [
                'percent' => (int) $cached['percent'],
                'message' => (string) ($cached['message'] ?? ''),
                'status' => (string) ($cached['status'] ?? 'generating'),
                'updated_at' => $cached['updated_at'] ?? null,
            ];
        }

        $bp = ContentBlueprint::query()->find($blueprintId);
        if (! $bp) {
            return ['percent' => 0, 'message' => '', 'status' => 'unknown', 'updated_at' => null];
        }

        if ($bp->status === 'ready' || $bp->status === 'scheduled') {
            return ['percent' => 100, 'message' => 'Selesai', 'status' => 'ready', 'updated_at' => optional($bp->updated_at)->toIso8601String()];
        }
        if ($bp->status === 'failed') {
            return ['percent' => 0, 'message' => (string) $bp->story_concept, 'status' => 'failed', 'updated_at' => optional($bp->updated_at)->toIso8601String()];
        }

        $msg = (string) ($bp->story_concept ?? 'Menunggu…');
        $percent = 5;
        if (preg_match('/Progress\s+(\d+)%/i', $msg, $m)) {
            $percent = (int) $m[1];
        }

        return [
            'percent' => $percent,
            'message' => $msg,
            'status' => (string) $bp->status,
            'updated_at' => optional($bp->updated_at)->toIso8601String(),
        ];
    }

    public static function clear(int $blueprintId): void
    {
        Cache::forget(self::key($blueprintId));
    }

    public static function done(int $blueprintId, string $message = 'Selesai'): void
    {
        Cache::put(self::key($blueprintId), [
            'percent' => 100,
            'message' => $message,
            'status' => 'ready',
            'updated_at' => now()->toIso8601String(),
        ], now()->addMinutes(30));
    }
}
