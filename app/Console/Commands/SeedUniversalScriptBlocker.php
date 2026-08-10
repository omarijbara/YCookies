<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\ScriptBlocker;
use Illuminate\Console\Command;

class SeedUniversalScriptBlocker extends Command
{
    protected $signature = 'scriptblocker:seed-universal';

    protected $description = 'Creates universal fallback Script and Style blockers per tenant group';

    public function handle(): int
    {
        $groups = Group::all();
        $count = 0;

        foreach ($groups as $group) {
            // Universal Script Fallback
            ScriptBlocker::updateOrCreate(
                [
                    'group_id' => $group->id,
                    'key' => 'sb-universal-fallback',
                    'is_system' => true,
                ],
                [
                    'domain_id' => null,
                    'name' => [
                        'en' => 'Universal Script Fallback',
                        'de' => 'Universal Script Fallback',
                    ],
                    'hosts' => ['*'],
                    'require_group' => 'uncategorized',
                    'is_active' => true,
                    'blocker_type' => ScriptBlocker::TYPE_SCRIPT,
                ]
            );

            // Universal Style Fallback
            ScriptBlocker::updateOrCreate(
                [
                    'group_id' => $group->id,
                    'key' => 'stb-universal-fallback',
                    'is_system' => true,
                ],
                [
                    'domain_id' => null,
                    'name' => [
                        'en' => 'Universal Style Fallback',
                        'de' => 'Universal Style Fallback',
                    ],
                    'hosts' => ['*'],
                    'require_group' => 'uncategorized',
                    'is_active' => true,
                    'blocker_type' => ScriptBlocker::TYPE_STYLE,
                ]
            );

            $count += 2;
            $this->info("  Created Universal Script + Style Fallback for group: {$group->name}");
        }

        $this->info("Done. {$count} Universal Fallback blocker(s) created.");

        return self::SUCCESS;
    }
}
