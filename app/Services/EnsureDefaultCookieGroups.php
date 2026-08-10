<?php

namespace App\Services;

use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\Group;

/**
 * Creates standard system cookie groups per tenant (Group) and attaches them to domains.
 * Idempotent: uses firstOrCreate on group_id + key.
 */
class EnsureDefaultCookieGroups
{
    /**
     * @return array<string, CookieGroup> keyed by cookie group key
     */
    public static function ensureForGroup(Group $group): array
    {
        $definitions = [
            'essential' => [
                'name' => ['en' => 'Essential', 'de' => 'Notwendig'],
                'description' => [
                    'en' => 'Required for the website to function normally.',
                    'de' => 'Erforderlich, damit die Website normal funktioniert.',
                ],
                'is_required' => true,
                'is_preselected' => true,
                'sort_order' => 10,
            ],
            'first_party' => [
                'name' => ['en' => 'First-party / Functional', 'de' => 'Erstpartei / Funktional'],
                'description' => [
                    'en' => 'Features from your own site that are not strictly essential (e.g. personalization, comfort).',
                    'de' => 'Funktionen der eigenen Website, die nicht zwingend notwendig sind (z. B. Personalisierung, Komfort).',
                ],
                'is_required' => false,
                'is_preselected' => false,
                'sort_order' => 15,
            ],
            'analytics' => [
                'name' => ['en' => 'Analytics', 'de' => 'Analyse'],
                'description' => [
                    'en' => 'Helps us understand how visitors interact with the website.',
                    'de' => 'Hilft uns zu verstehen, wie Besucher mit der Website interagieren.',
                ],
                'is_required' => false,
                'is_preselected' => false,
                'sort_order' => 20,
            ],
            'statistics' => [
                'name' => ['en' => 'Statistics', 'de' => 'Statistik'],
                'description' => [
                    'en' => 'Measurement and performance data (e.g. aggregated usage, A/B tests).',
                    'de' => 'Messung und Leistungsdaten (z. B. aggregierte Nutzung, A/B-Tests).',
                ],
                'is_required' => false,
                'is_preselected' => false,
                'sort_order' => 30,
            ],
            'marketing' => [
                'name' => ['en' => 'Marketing', 'de' => 'Marketing'],
                'description' => [
                    'en' => 'Used to track visitors across websites for advertising purposes.',
                    'de' => 'Wird verwendet, um Besucher über Websites hinweg für Werbezwecke zu verfolgen.',
                ],
                'is_required' => false,
                'is_preselected' => false,
                'sort_order' => 40,
            ],
            'external_media' => [
                'name' => ['en' => 'External media', 'de' => 'Externe Medien'],
                'description' => [
                    'en' => 'Embedded videos, maps, and other content loaded from external platforms.',
                    'de' => 'Eingebettete Videos, Karten und andere Inhalte von externen Plattformen.',
                ],
                'is_required' => false,
                'is_preselected' => false,
                'sort_order' => 50,
            ],
            'uncategorized' => [
                'name' => ['en' => 'Uncategorized', 'de' => 'Unkategorisiert'],
                'description' => [
                    'en' => 'Resources discovered during browsing that are not yet assigned to a category.',
                    'de' => 'Ressourcen, die beim Surfen entdeckt und noch keiner Kategorie zugeordnet wurden.',
                ],
                'is_required' => false,
                'is_preselected' => false,
                'sort_order' => 99,
            ],
        ];

        $out = [];
        foreach ($definitions as $key => $def) {
            $out[$key] = CookieGroup::firstOrCreate(
                [
                    'group_id' => $group->id,
                    'key' => $key,
                ],
                array_merge($def, [
                    'is_system' => true,
                ])
            );
        }

        return $out;
    }

    public static function attachAllToDomain(Domain $domain): void
    {
        if (! $domain->group_id) {
            return;
        }

        $group = $domain->group;
        if (! $group) {
            return;
        }

        $map = self::ensureForGroup($group);
        $domain->cookieGroups()->syncWithoutDetaching(
            collect($map)->pluck('id')->values()->all()
        );
    }

    public static function backfillGroup(Group $group): void
    {
        $map = self::ensureForGroup($group);
        $ids = collect($map)->pluck('id')->values()->all();

        foreach ($group->domains as $domain) {
            $domain->cookieGroups()->syncWithoutDetaching($ids);
        }
    }
}
