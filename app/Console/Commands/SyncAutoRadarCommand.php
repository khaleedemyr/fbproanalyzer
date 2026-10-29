<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Radar\AutoRadarService;
use Illuminate\Console\Command;

class SyncAutoRadarCommand extends Command
{
    protected $signature = 'radar:sync {--user= : Optional user id to auto-generate ideas}';

    protected $description = 'Sync automatic viral niche radar signals';

    public function handle(AutoRadarService $radar): int
    {
        $user = null;
        if ($this->option('user')) {
            $user = User::query()->find($this->option('user'));
        } else {
            $user = User::query()->orderBy('id')->first();
        }

        $result = $radar->sync($user);

        $this->info('Radar synced: '.$result['synced'].' signals');
        foreach ($result['sources'] as $source => $count) {
            $this->line(" - {$source}: {$count}");
        }
        $this->line('Ideas created: '.$result['ideas']);

        return self::SUCCESS;
    }
}
