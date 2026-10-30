<?php

namespace App\Jobs;

use App\Models\ContentBlueprint;
use App\Models\User;
use App\Services\Blueprint\BlueprintGeneratorService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Dijalankan AFTER HTTP response (bukan antrean worker).
 * Jadi generate 1 jam + Gemini tidak diputus proxy Hostinger.
 */
class GenerateBlueprintJob
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public int $blueprintId,
        public int $userId,
        public string $topic,
        public string $format,
        public array $options = [],
    ) {
    }

    public function handle(BlueprintGeneratorService $generator): void
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $bp = ContentBlueprint::query()->find($this->blueprintId);
        $user = User::query()->find($this->userId);

        if (! $bp || ! $user || $bp->user_id !== $user->id) {
            Log::warning('GenerateBlueprintJob: blueprint/user tidak valid', [
                'blueprint_id' => $this->blueprintId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        if ($bp->status !== 'generating') {
            return;
        }

        Log::info('GenerateBlueprintJob.start', [
            'blueprint_id' => $bp->id,
            'format' => $this->format,
            'duration_minutes' => $this->options['duration_minutes'] ?? null,
        ]);

        try {
            $generator->fillExistingBlueprint(
                $bp,
                $user,
                $this->topic,
                $this->format,
                $this->options
            );

            Log::info('GenerateBlueprintJob.ok', [
                'blueprint_id' => $bp->id,
                'engine' => $bp->fresh()->engine,
                'scenes' => count($bp->fresh()->scenes ?? []),
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('GenerateBlueprintJob.fail', [
                'blueprint_id' => $bp->id,
                'error' => $e->getMessage(),
            ]);

            $bp->forceFill([
                'status' => 'failed',
                'engine' => 'failed',
                'story_concept' => 'Generate gagal: '.$e->getMessage(),
            ])->save();
        }
    }
}
