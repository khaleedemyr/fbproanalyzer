<?php

namespace App\Console\Commands;

use App\Models\ContentBlueprint;
use App\Models\User;
use App\Services\Blueprint\BlueprintGeneratorService;
use Illuminate\Console\Command;

class FinishStuckBlueprints extends Command
{
    protected $signature = 'blueprints:finish-stuck {--minutes=2 : Anggap stuck setelah N menit}';

    protected $description = 'Selesaikan blueprint status generating yang macet (Hostinger bunuh job)';

    public function handle(BlueprintGeneratorService $generator): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $rows = ContentBlueprint::query()
            ->where('status', 'generating')
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada blueprint stuck.');

            return self::SUCCESS;
        }

        foreach ($rows as $bp) {
            $user = User::query()->find($bp->user_id);
            if (! $user) {
                continue;
            }
            $this->line("Finishing #{$bp->id} …");
            try {
                $useAi = $bp->format === BlueprintGeneratorService::FORMAT_REELS_PANJANG
                    || $bp->engine === 'pending_ai';
                $generator->finishGeneratingBlueprint($bp, $user, ['use_ai' => $useAi]);
                $this->info("OK #{$bp->id}");
            } catch (\Throwable $e) {
                $this->error("#{$bp->id}: ".$e->getMessage());
                $bp->forceFill([
                    'status' => 'failed',
                    'engine' => 'failed',
                    'story_concept' => 'Generate gagal: '.$e->getMessage(),
                ])->save();
            }
        }

        return self::SUCCESS;
    }
}
