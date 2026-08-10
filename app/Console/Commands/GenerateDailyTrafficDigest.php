<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDigestForGroup;
use App\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateDailyTrafficDigest extends Command
{
    protected $signature = 'traffic:digest {--date= : YYYY-MM-DD, default yesterday}';
    protected $description = 'Aggregate yesterday\'s edge+RUM metrics into daily reports for all groups';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::yesterday()->startOfDay();

        $groups = Group::all();

        if ($groups->isEmpty()) {
            $this->warn('No groups found — nothing to digest.');
            return self::SUCCESS;
        }

        $this->info("Generating daily traffic digest for {$date->toDateString()} across {$groups->count()} group(s).");

        foreach ($groups as $group) {
            GenerateDigestForGroup::dispatch($group->id, $date);
            $this->line("  → Queued digest for group [{$group->id}] {$group->name}");
        }

        $this->info('All digest jobs queued on [reports] queue.');
        return self::SUCCESS;
    }
}
