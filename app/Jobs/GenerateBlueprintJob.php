<?php

namespace App\Jobs;

use App\Models\ContentBlueprint;
use App\Models\User;
use App\Services\Blueprint\BlueprintGeneratorService;
use App\Support\BlueprintProgress;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Dijalankan AFTER HTTP response.
 * - Tanpa AI: expand template cepat.
 * - Dengan AI (pending_ai): biarkan frontend/finish-generate yang panggil Gemini
 *   (request HTTP baru = budget waktu fresh, tidak diputus proxy diam-diam).
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
        ignore_user_abort(true);
        @set_time_limit(180);
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

        $wantAi = (bool) ($this->options['use_ai'] ?? false)
            && ! (bool) ($this->options['skip_ai'] ?? false);
        if ($bp->engine === 'pending_ai') {
            $wantAi = true;
        }

        $minutes = (int) ($this->options['duration_minutes'] ?? round(((int) ($bp->duration_seconds ?: 0)) / 60));

        Log::info('GenerateBlueprintJob.start', [
            'blueprint_id' => $bp->id,
            'format' => $this->format,
            'duration_minutes' => $minutes,
            'use_ai' => $wantAi,
        ]);

        // Mode AI: jangan Gemini di afterResponse (sering dibunuh Hostinger tanpa log).
        // Frontend akan hit finish-generate dengan use_ai=true.
        if ($wantAi) {
            BlueprintProgress::set(
                $bp->id,
                12,
                'Menunggu AI Gemini… (halaman detail akan lanjut otomatis)',
                'generating'
            );
            Log::info('GenerateBlueprintJob.defer_ai', ['blueprint_id' => $bp->id]);

            return;
        }

        BlueprintProgress::set($bp->id, 6, "Mulai generate {$minutes} menit (template)…", 'generating');

        try {
            $options = $this->options;
            $options['background'] = true;
            $options['force'] = true;
            $options['use_ai'] = false;
            $options['skip_ai'] = true;

            $generator->fillExistingBlueprint(
                $bp,
                $user,
                $this->topic,
                $this->format,
                $options
            );

            $fresh = $bp->fresh();
            if ($fresh && $fresh->status === 'generating') {
                $fresh->forceFill(['status' => 'ready'])->save();
            }

            Log::info('GenerateBlueprintJob.ok', [
                'blueprint_id' => $bp->id,
                'engine' => $fresh?->engine,
                'scenes' => count($fresh?->scenes ?? []),
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
