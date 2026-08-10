<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Group;
use App\Services\GdprService;

class GdprExport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ycookies:gdpr:export {group_id : The ID of the group to export}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export all data for a specific group for GDPR DSAR requests.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(GdprService $gdprService)
    {
        $groupId = $this->argument('group_id');
        $group = Group::find($groupId);

        if (!$group) {
            $this->error("Group with ID {$groupId} not found.");
            return 1;
        }

        $this->info("Exporting data for group: {$group->name}...");
        $path = $gdprService->exportGroup($group);

        $this->info("Successfully exported data to: {$path}");

        return 0;
    }
}
