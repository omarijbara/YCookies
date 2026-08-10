<?php

namespace Database\Seeders;

use App\Models\CookieGroup;
use App\Models\ContentBlocker;
use App\Models\Domain;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCookie;
use App\Models\ServiceSetting;
use Illuminate\Database\Seeder;

class TemplateLibrarySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $domain = \App\Models\Domain::first();
        if (!$domain) {
            return;
        }

        // 1. Ensure Groups Exist
        $marketing = CookieGroup::firstOrCreate(
            ['key' => 'marketing'],
            ['name' => 'Marketing', 'sort_order' => 2, 'is_required' => false]
        );
        $externalMedia = CookieGroup::firstOrCreate(
            ['key' => 'external_media'],
            ['name' => 'External Media', 'sort_order' => 3, 'is_required' => false]
        );

        $domain->cookieGroups()->syncWithoutDetaching([$marketing->id, $externalMedia->id]);

        // 2. Define Providers
        $google = Provider::firstOrCreate(['name' => 'Google Ireland Limited'], [
            'address' => 'Gordon House, Barrow Street, Dublin 4, Ireland',
            'privacy_policy_url' => 'https://policies.google.com/privacy',
        ]);
        
        $meta = Provider::firstOrCreate(['name' => 'Meta Platforms Ireland Ltd.'], [
            'address' => '4 Grand Canal Square, Grand Canal Harbour, Dublin 2, Ireland',
            'privacy_policy_url' => 'https://www.facebook.com/policy.php',
        ]);

        $linkedin = Provider::firstOrCreate(['name' => 'LinkedIn Ireland Unlimited Company'], [
            'address' => 'Wilton Place, Dublin 2, Ireland',
            'privacy_policy_url' => 'https://www.linkedin.com/legal/privacy-policy',
        ]);

        $tiktok = Provider::firstOrCreate(['name' => 'TikTok Information Technologies UK Limited'], [
            'address' => '1 London Wall, London EC2Y 5EB, United Kingdom',
            'privacy_policy_url' => 'https://www.tiktok.com/legal/privacy-policy',
        ]);

        $vimeo = Provider::firstOrCreate(['name' => 'Vimeo.com, Inc.'], [
            'address' => '330 West 34th Street, 5th Floor, New York, New York 10001',
            'privacy_policy_url' => 'https://vimeo.com/privacy',
        ]);

        $twitter = Provider::firstOrCreate(['name' => 'Twitter International Unlimited Company'], [
            'address' => 'One Cumberland Place, Fenian Street, Dublin 2, D02 AX07 Ireland',
            'privacy_policy_url' => 'https://twitter.com/en/privacy',
        ]);

        // 3. Define the massive template library
        $templates = \App\Services\TemplateLibraryService::getTemplates();

        foreach ($templates as $t) {
            $providerModel = Provider::firstOrCreate(['name' => $t['provider']]);
            $groupModel = CookieGroup::where('key', $t['group'])->first() ?? $marketing;

            $service = Service::firstOrCreate(
                ['key' => $t['key']],
                [
                    'name' => $t['name'],
                    'provider_id' => $providerModel->id,
                    'cookie_group_id' => $groupModel->id,
                    'purpose' => $t['purpose'],
                    'instructions' => $t['instructions'] ?? null,
                    'sort_order' => 10,
                    'is_active' => true,
                ]
            );

            // Sync domain
            $domain->services()->syncWithoutDetaching([$service->id]);

            // Save Settings (payloads) if provided
            if (!empty($t['payloads'])) {
                ServiceSetting::updateOrCreate(
                    ['service_id' => $service->id],
                    [
                        'opt_in_code' => $t['payloads']['opt_in'] ?? null,
                        'opt_out_code' => $t['payloads']['opt_out'] ?? null,
                        'fallback_code' => $t['payloads']['fallback'] ?? null,
                    ]
                );
            }

            // Create cookies
            if (!empty($t['cookies'])) {
                foreach ($t['cookies'] as $c) {
                    ServiceCookie::firstOrCreate(
                        ['service_id' => $service->id, 'name' => $c['name']],
                        [
                            'hostname' => '',
                            'lifetime' => $c['lifetime'],
                            'purpose' => $c['purpose'],
                        ]
                    );
                }
            }
        }

        // 4. Create Content Blockers for External Media
        $blockers = [
            [
                'key' => 'vimeo', 
                'name' => 'Vimeo', 
                'hosts' => ['player.vimeo.com', 'vimeo.com'],
                'instructions' => '<p><strong>Hinweis:</strong> Eingebettete Vimeo-Videos werden blockiert, bis der Nutzer zustimmt. Nach der Zustimmung wird das Video via Iframe geladen.</p>',
            ],
            [
                'key' => 'google-maps', 
                'name' => 'Google Maps', 
                'hosts' => ['maps.google.com', 'google.com/maps'],
                'instructions' => '<p><strong>Hinweis:</strong> Google Maps iframes werden blockiert und erst nach expliziter Zustimmung des Nutzers vom Google-Server geladen.</p>',
            ],
            [
                'key' => 'twitter', 
                'name' => 'Twitter / X', 
                'hosts' => ['platform.twitter.com', 'twitter.com', 'x.com'],
                'instructions' => '<p><strong>Hinweis:</strong> Eingebettete Tweets und Timelines werden bis zur Zustimmung blockiert.</p>',
            ],
            [
                'key' => 'instagram', 
                'name' => 'Instagram', 
                'hosts' => ['instagram.com'],
                'instructions' => '<p><strong>Hinweis:</strong> Eingebettete Instagram-Beiträge benötigen die vorherige Zustimmung des Nutzers, bevor das Skript ausgeführt wird.</p>',
            ],
        ];

        foreach ($blockers as $b) {
            $cb = ContentBlocker::firstOrCreate(
                ['key' => $b['key']],
                [
                    'domain_id' => $domain->id,
                    'name' => $b['name'],
                    'hosts' => $b['hosts'],
                    'instructions' => $b['instructions'] ?? null,
                    'is_active' => true,
                ]
            );
        }
    }
}
