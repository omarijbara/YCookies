<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Services\EnsureDefaultCookieGroups;
use Illuminate\Console\Command;

class EnsureCookieGroupsCommand extends Command
{
    protected $signature = 'ycookies:ensure-cookie-groups
                            {group? : Optional Group (tenant) ID — omit to process all groups}';

    protected $description = 'Ensure default system cookie groups exist per tenant and attach them to all domains';

    public function handle(): int
    {
        $groupId = $this->argument('group');

        $groups = $groupId
            ? Group::whereKey($groupId)->get()
            : Group::query()->orderBy('id')->get();

        if ($groupId && $groups->isEmpty()) {
            $this->error("No group found for ID {$groupId}.");

            return self::FAILURE;
        }

        foreach ($groups as $group) {
            EnsureDefaultCookieGroups::backfillGroup($group);
            $this->info("Cookie groups ensured for tenant «{$group->name}» (ID {$group->id}).");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
