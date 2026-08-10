<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Group;
use App\Services\GdprService;

class GdprDelete extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ycookies:gdpr:delete {group_id : The ID of the group to delete} {--force : Force the deletion without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a group and all its associated data for GDPR requests.';

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

        if (!$this->option('force')) {
            if (!$this->confirm("Are you sure you want to PERMANENTLY delete group: {$group->name} and ALL its data? This action cannot be undone.")) {
                $this->info('Deletion cancelled.');
                return 0;
            }
        }

        $this->info("Deleting data for group: {$group->name}...");
        $gdprService->deleteGroup($group);

        $this->info("Successfully deleted group and associated data.");

        return 0;
    }
}
