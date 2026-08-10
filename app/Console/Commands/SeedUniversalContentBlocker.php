<?php

namespace App\Console\Commands;

use App\Models\ContentBlocker;
use App\Models\Group;
use App\Services\ContentBlockerTemplates;
use Illuminate\Console\Command;

class SeedUniversalContentBlocker extends Command
{
    protected $signature = 'contentblocker:seed-universal';

    protected $description = 'Creates a single customizable Universal Fallback Content Blocker per tenant group';

    public function handle(): int
    {
        // Clean up legacy per-domain fallbacks
        ContentBlocker::where('key', 'like', '%universal-fallback%')->delete();
        $this->line('  Cleaned up legacy per-domain fallback blockers.');

        $groups = Group::all();
        $count = 0;
        $template = ContentBlockerTemplates::getTemplate('inline-default');

        foreach ($groups as $group) {
            ContentBlocker::updateOrCreate(
                [
                    'group_id' => $group->id,
                    'key' => 'cb-universal-fallback',
                    'is_system' => true,
                ],
                [
                    'domain_id' => null,
                    'name' => [
                        'en' => 'Universal Fallback',
                        'de' => 'Universal Fallback',
                    ],
                    'description' => [
                        'en' => 'Blocks any external iframe that does not have a specific blocker configured. Applies to all domains.',
                        'de' => 'Blockiert externe iFrames, für die kein spezifischer Blocker konfiguriert ist. Gilt für alle Domains.',
                    ],
                    'hosts' => ['*'],
                    'is_active' => true,
                    'display_mode' => 'inline',
                    'supports_accept_once' => true,
                    'supports_accept_provider' => false,
                    'html_code' => str_replace('{{name}}', 'External', $template['html_code'] ?? ''),
                    'css_code' => $template['css_code'] ?? '',
                ]
            );
            $count++;
            $this->info("  Created Universal Fallback for group: {$group->name}");
        }

        $this->info("Done. {$count} Universal Fallback blocker(s) created (one per tenant).");

        return self::SUCCESS;
    }
}
