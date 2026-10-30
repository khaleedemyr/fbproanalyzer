<?php

namespace App\Console\Commands;

use App\Models\ContentBlueprint;
use App\Models\User;
use App\Services\Blueprint\BlueprintGeneratorService;
use App\Support\BlueprintProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FinishBlueprintCommand extends Command
{
    protected $signature = 'blueprints:finish {id : Blueprint ID} {--use_ai=1 : Pakai Gemini (1/0)}';

    protected $description = 'Selesaikan blueprint generating di CLI (hindari timeout proxy Hostinger)';

    public function handle(BlueprintGeneratorService $generator): int
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        ignore_user_abort(true);

        $id = (int) $this->argument('id');
        $bp = ContentBlueprint::query()->find($id);
        if (! $bp) {
            $this->error("Blueprint #{$id} tidak ada.");

            return self::FAILURE;
        }

        $user = User::query()->find($bp->user_id);
        if (! $user) {
            $this->error("User blueprint #{$id} tidak ada.");

            return self::FAILURE;
        }

        $useAi = $bp->format === BlueprintGeneratorService::FORMAT_REELS_PANJANG
            ? true
            : ((string) $this->option('use_ai') !== '0');

        $lockKey = 'blueprint_finish_lock_'.$bp->id;
        // HTTP boleh sudah set lock "spawn" — CLI mengambil alih.
        $existing = Cache::get($lockKey);
        if ($existing !== null && $existing !== 'spawn' && $existing !== 'cli') {
            $this->warn("Blueprint #{$id} sedang diproses ({$existing}).");

            return self::SUCCESS;
        }
        Cache::put($lockKey, 'cli', now()->addMinutes(5));

        Log::info('blueprint.finish.cli.start', [
            'blueprint_id' => $bp->id,
            'use_ai' => $useAi,
            'engine' => $bp->engine,
        ]);

        try {
            if (! in_array($bp->status, ['generating', 'failed'], true)) {
                $this->info("Blueprint #{$id} sudah selesai ({$bp->status}).");

                return self::SUCCESS;
            }

            BlueprintProgress::set($bp->id, 18, 'Memanggil AI Gemini (CLI)…', 'generating');
            $bp->forceFill(['status' => 'generating', 'engine' => $useAi ? 'pending_ai' : $bp->engine])->save();

            $done = $generator->finishGeneratingBlueprint($bp, $user, ['use_ai' => $useAi]);

            if ($useAi && $done->engine !== 'gemini') {
                $done->forceFill([
                    'status' => 'failed',
                    'engine' => 'failed',
                    'story_concept' => 'AI Gemini tidak menghasilkan kerangka. Tidak memakai template.',
                    'scenes' => [],
                ])->save();
                BlueprintProgress::set($done->id, 0, 'AI gagal — tidak pakai template', 'failed');
                Log::error('blueprint.finish.cli.not_gemini', ['blueprint_id' => $done->id]);
                $this->error('Gemini gagal — tidak pakai template.');

                return self::FAILURE;
            }

            BlueprintProgress::done($done->id, 'Blueprint siap');
            Log::info('blueprint.finish.cli.ok', [
                'blueprint_id' => $done->id,
                'engine' => $done->engine,
                'scenes' => count($done->scenes ?? []),
            ]);
            $this->info("OK #{$done->id} engine={$done->engine} scenes=".count($done->scenes ?? []));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            report($e);
            Log::error('blueprint.finish.cli.fail', [
                'blueprint_id' => $bp->id,
                'error' => $e->getMessage(),
            ]);
            $bp->refresh();
            $bp->forceFill([
                'status' => 'failed',
                'engine' => 'failed',
                'story_concept' => 'AI Gemini gagal: '.$e->getMessage(),
            ])->save();
            BlueprintProgress::set($bp->id, 0, 'AI gagal: '.$e->getMessage(), 'failed');
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            Cache::forget($lockKey);
        }
    }
}
