<?php

namespace App\Filament\Pages;

use App\Models\ContentBlocker;
use App\Models\CookieGroup;
use App\Models\Domain;
use App\Models\Provider;
use App\Models\ScriptBlocker;
use App\Models\Service;
use App\Services\NotificationService;
use App\Services\TemplateLibraryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

class PackageLibrary extends Page
{
    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.package-library';

    public string $activeFilter = 'all';

    public string $search = '';

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-squares-2x2';
    }

    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.library');
    }

    public function getTitle(): string
    {
        return 'Package Library';
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getNavigationBadge(): ?string
    {
        $templates = TemplateLibraryService::getTemplates();
        $count = 0;

        $installedServices = Service::whereNotNull('template_key')->get();
        foreach ($installedServices as $service) {
            $tpl = $templates[$service->template_key] ?? null;
            if ($tpl && version_compare($tpl['version'] ?? '1.0.0', $service->template_version ?? '0.0.0', '>')) {
                $count++;
            }
        }

        $installedBlockers = ScriptBlocker::whereNotNull('template_key')->get();
        foreach ($installedBlockers as $blocker) {
            $tpl = $templates[$blocker->template_key] ?? null;
            if ($tpl && version_compare($tpl['version'] ?? '1.0.0', $blocker->template_version ?? '0.0.0', '>')) {
                $count++;
            }
        }

        $installedContent = ContentBlocker::whereNotNull('template_key')->get();
        foreach ($installedContent as $cb) {
            $tpl = $templates[$cb->template_key] ?? null;
            if ($tpl && version_compare($tpl['version'] ?? '1.0.0', $cb->template_version ?? '0.0.0', '>')) {
                $count++;
            }
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ycookies.nav.tools');
    }

    public function getSubheading(): ?string
    {
        return __('ycookies.admin.page.package_library');
    }

    public function mount(): void
    {
        // Check for package updates and send notifications on page load
        try {
            NotificationService::checkForUpdates();
        } catch (\Exception $e) {
            // Silently fail — don't break the page if notification check fails
        }
    }

    public function getFilteredTemplates(): array
    {
        $templates = TemplateLibraryService::getTemplates();
        $search = strtolower(trim($this->search));

        // Filter by type
        if ($this->activeFilter === 'installed') {
            $installedServiceKeys = Service::where('template_key', '!=', null)->pluck('template_key')->toArray();
            $installedBlockerKeys = ScriptBlocker::where('template_key', '!=', null)->pluck('template_key')->toArray();
            $installedContentKeys = ContentBlocker::where('template_key', '!=', null)->pluck('template_key')->toArray();
            $installedKeys = array_merge($installedServiceKeys, $installedBlockerKeys, $installedContentKeys);

            $templates = array_filter($templates, fn ($tpl) => in_array($tpl['key'], $installedKeys));
        } elseif ($this->activeFilter !== 'all') {
            $templates = array_filter($templates, fn ($tpl) => $tpl['type'] === $this->activeFilter);
        }

        // Filter by search
        if ($search) {
            $templates = array_filter($templates, function ($tpl) use ($search) {
                return str_contains(strtolower($tpl['name']), $search)
                    || str_contains(strtolower($tpl['provider'] ?? ''), $search)
                    || str_contains(strtolower($tpl['purpose'] ?? ''), $search)
                    || str_contains(strtolower($tpl['key']), $search);
            });
        }

        return $templates;
    }

    public function getFilterCounts(): array
    {
        $all = TemplateLibraryService::getTemplates();

        $installedServiceKeys = Service::where('template_key', '!=', null)->pluck('template_key')->toArray();
        $installedBlockerKeys = ScriptBlocker::where('template_key', '!=', null)->pluck('template_key')->toArray();
        $installedContentKeys = ContentBlocker::where('template_key', '!=', null)->pluck('template_key')->toArray();
        $installedKeys = array_merge($installedServiceKeys, $installedBlockerKeys, $installedContentKeys);

        return [
            'all' => count($all),
            'service' => count(array_filter($all, fn ($t) => $t['type'] === 'service')),
            'script_blocker' => count(array_filter($all, fn ($t) => $t['type'] === 'script_blocker')),
            'content_blocker' => count(array_filter($all, fn ($t) => $t['type'] === 'content_blocker')),
            'style_blocker' => count(array_filter($all, fn ($t) => $t['type'] === 'style_blocker')),
            'installed' => count(array_filter($all, fn ($t) => in_array($t['key'], $installedKeys))),
        ];
    }

    public function isInstalled(string $key): bool
    {
        return Service::where('template_key', $key)->exists()
            || ScriptBlocker::where('template_key', $key)->exists()
            || ContentBlocker::where('template_key', $key)->exists();
    }

    /**
     * Get the installed version for a template key.
     */
    public function getInstalledVersion(string $key): ?string
    {
        $service = Service::where('template_key', $key)->first();
        if ($service) {
            return $service->template_version;
        }

        $blocker = ScriptBlocker::where('template_key', $key)->first();
        if ($blocker) {
            return $blocker->template_version;
        }

        $content = ContentBlocker::where('template_key', $key)->first();
        if ($content) {
            return $content->template_version;
        }

        return null;
    }

    /**
     * Check if there is an update available for an installed template.
     */
    public function hasUpdate(string $key): bool
    {
        $installedVersion = $this->getInstalledVersion($key);
        if (! $installedVersion) {
            return false;
        }

        $template = $this->getTemplateByKey($key);
        if (! $template) {
            return false;
        }

        $libraryVersion = $template['version'] ?? '1.0.0';

        return version_compare($libraryVersion, $installedVersion, '>');
    }

    /**
     * Count of packages that have updates available.
     */
    public function getUpdateCount(): int
    {
        $templates = TemplateLibraryService::getTemplates();
        $count = 0;
        foreach ($templates as $key => $tpl) {
            if ($this->isInstalled($tpl['key']) && $this->hasUpdate($tpl['key'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get changelog entries between installed version and lib version.
     */
    public function getChangelog(string $key): array
    {
        $template = $this->getTemplateByKey($key);
        if (! $template || empty($template['changelog'])) {
            return [];
        }

        $installedVersion = $this->getInstalledVersion($key) ?? '0.0.0';
        $entries = [];

        foreach ($template['changelog'] as $version => $changes) {
            if (version_compare($version, $installedVersion, '>')) {
                $entries[$version] = $changes;
            }
        }

        // Sort descending
        uksort($entries, fn ($a, $b) => version_compare($b, $a));

        return $entries;
    }

    public function setFilter(string $filter): void
    {
        $this->activeFilter = $filter;
    }

    public function installPackageAction(): Action
    {
        return Action::make('installPackage')
            ->label('Install Package')
            ->modalHeading(fn (array $arguments) => 'Install '.($this->getTemplateByKey($arguments['template'] ?? '')['name'] ?? 'Package'))
            ->form(function (array $arguments): array {
                $tpl = $this->getTemplateByKey($arguments['template'] ?? '');
                if (! $tpl) {
                    return [];
                }

                $fields = [];

                // Domain selection — needed for all types
                $fields[] = Select::make('domain_ids')
                    ->label('Assigned Domains')
                    ->options(Domain::pluck('name', 'id'))
                    ->multiple()
                    ->required();

                // Type-specific fields
                if ($tpl['type'] === 'service') {
                    $fields[] = Select::make('cookie_group_id')
                        ->label('Cookie Group')
                        ->options(CookieGroup::all()->mapWithKeys(function ($group) {
                            $name = is_array($group->name) ? ($group->name[app()->getLocale()] ?? current($group->name)) : $group->name;

                            return [$group->id => $name ?: 'Unnamed Group'];
                        }))
                        ->required();

                    $fields[] = TextInput::make('tracking_id')
                        ->label('Tracking ID (Optional)')
                        ->helperText('e.g., G-XXXX, GTM-XXXX, Pixel ID, if applicable.');
                }

                if (in_array($tpl['type'], ['script_blocker', 'style_blocker'])) {
                    $fields[] = Select::make('linked_service_id')
                        ->label('Link to Service (Optional)')
                        ->options(Service::pluck('name', 'id'))
                        ->helperText('Optionally link this blocker to an existing service for consent management.')
                        ->searchable();
                }

                if ($tpl['type'] === 'content_blocker') {
                    $fields[] = Select::make('linked_service_id')
                        ->label('Link to Service (Optional)')
                        ->options(Service::pluck('name', 'id'))
                        ->helperText('Link this content blocker to a service. Content is unblocked when the linked service gets consent.')
                        ->searchable();
                }

                return $fields;
            })
            ->action(function (array $data, array $arguments) {
                $templateKey = $arguments['template'];
                $tpl = $this->getTemplateByKey($templateKey);
                if (! $tpl) {
                    return;
                }

                $type = $tpl['type'];
                $domainIds = $data['domain_ids'] ?? [];

                // Check if already installed
                if ($this->isInstalled($templateKey)) {
                    Notification::make()
                        ->warning()
                        ->title('Already Installed')
                        ->body("'{$tpl['name']}' is already installed.")
                        ->send();

                    return;
                }

                match ($type) {
                    'service' => $this->installServicePackage($tpl, $data, $domainIds),
                    'script_blocker' => $this->installScriptBlocker($tpl, $data, $domainIds),
                    'content_blocker' => $this->installContentBlocker($tpl, $data, $domainIds),
                    'style_blocker' => $this->installStyleBlocker($tpl, $data, $domainIds),
                };

                Notification::make()
                    ->success()
                    ->title('Package Installed')
                    ->body("'{$tpl['name']}' has been installed successfully.")
                    ->send();
            });
    }

    public function updatePackageAction(): Action
    {
        return Action::make('updatePackage')
            ->label('Update Package')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Update '.($this->getTemplateByKey($arguments['template'] ?? '')['name'] ?? 'Package'))
            ->modalDescription(fn (array $arguments) => new HtmlString($this->buildUpdateModalContent($arguments['template'] ?? '')))
            ->modalSubmitActionLabel('Yes, update this package')
            ->modalIcon('heroicon-o-arrow-path')
            ->modalIconColor('warning')
            ->action(function (array $arguments) {
                $templateKey = $arguments['template'];
                $tpl = $this->getTemplateByKey($templateKey);
                if (! $tpl) {
                    return;
                }

                $this->applyUpdate($tpl);

                Notification::make()
                    ->success()
                    ->title('Package Updated')
                    ->body("'{$tpl['name']}' has been updated to v{$tpl['version']}.")
                    ->send();
            });
    }

    protected function buildUpdateModalContent(string $key): string
    {
        $template = $this->getTemplateByKey($key);
        if (! $template) {
            return '';
        }

        $installedVersion = $this->getInstalledVersion($key) ?? '0.0.0';
        $newVersion = $template['version'] ?? '1.0.0';
        $changelog = $this->getChangelog($key);

        $html = '<div style="text-align:left;font-size:15px;line-height:1.7;">';

        // Version summary
        $html .= '<div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;padding:14px 18px;border-radius:12px;background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.2);">';
        $html .= '<span style="font-weight:600;font-size:16px;color:#fbbf24;">v'.e($installedVersion).'</span>';
        $html .= '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
        $html .= '<span style="font-weight:800;font-size:18px;color:#f59e0b;">v'.e($newVersion).'</span>';
        $html .= '</div>';

        // Changelog
        if (! empty($changelog)) {
            $html .= '<div style="margin-bottom:20px;">';
            $html .= '<h4 style="font-weight:700;font-size:14px;text-transform:uppercase;letter-spacing:0.06em;color:#9ca3af;margin-bottom:10px;">What\'s New</h4>';
            foreach ($changelog as $version => $changes) {
                $html .= '<div style="margin-bottom:6px;"><span style="font-weight:700;color:#fbbf24;font-size:15px;">v'.e($version).'</span></div>';
                $html .= '<ul style="margin:0 0 14px 18px;padding:0;">';
                foreach ($changes as $change) {
                    $html .= '<li style="margin-bottom:6px;color:#d1d5db;font-size:14px;line-height:1.6;">'.e($change).'</li>';
                }
                $html .= '</ul>';
            }
            $html .= '</div>';
        }

        // Warning about code override
        $html .= '<div style="padding:14px 18px;border-radius:12px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);margin-bottom:10px;">';
        $html .= '<div style="display:flex;align-items:flex-start;gap:12px;">';
        $html .= '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z"/><path d="M12 15.75h.007v.008H12v-.008z"/></svg>';
        $html .= '<div>';
        $html .= '<div style="font-weight:700;color:#fca5a5;font-size:15px;margin-bottom:4px;">Code Override Warning</div>';
        $html .= '<div style="color:#fecaca;font-size:14px;line-height:1.6;">This will <strong>replace</strong> the Opt-In, Opt-Out, and Fallback code with the new template version. If you made any custom changes to these scripts, they will be overwritten.</div>';
        $html .= '</div></div></div>';

        // Reassurance about IDs
        $html .= '<div style="padding:14px 18px;border-radius:12px;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);">';
        $html .= '<div style="display:flex;align-items:flex-start;gap:12px;">';
        $html .= '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><path d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
        $html .= '<div>';
        $html .= '<div style="font-weight:700;color:#86efac;font-size:15px;margin-bottom:4px;">Tracking IDs Preserved</div>';
        $html .= '<div style="color:#bbf7d0;font-size:14px;line-height:1.6;">Your tracking IDs (GTM ID, GA ID, Pixel ID, etc.) will <strong>not</strong> be changed. Only the template code is updated.</div>';
        $html .= '</div></div></div>';

        $html .= '</div>';

        return $html;
    }

    protected function installServicePackage(array $tpl, array $data, array $domainIds): void
    {
        $provider = Provider::firstOrCreate(
            ['name' => $tpl['provider']],
            ['is_library' => true]
        );

        $service = Service::create([
            'provider_id' => $provider->id,
            'cookie_group_id' => $data['cookie_group_id'],
            'name' => $tpl['name'],
            'key' => $tpl['key'],
            'template_key' => $tpl['key'],
            'template_version' => $tpl['version'] ?? '1.0.0',
            'purpose' => $tpl['purpose'],
            'instructions' => $tpl['instructions'] ?? null,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $service->domains()->sync($domainIds);

        // Create cookies
        if (isset($tpl['cookies']) && is_array($tpl['cookies'])) {
            foreach ($tpl['cookies'] as $cookie) {
                $service->cookies()->create([
                    'name' => $cookie['name'],
                    'lifetime' => $cookie['lifetime'],
                    'purpose' => $cookie['purpose'],
                    'hostname' => '',
                ]);
            }
        }

        // Create settings
        $trackingField = match ($tpl['key']) {
            'google-analytics' => 'ga_id',
            'google-tag-manager' => 'gtm_id',
            'meta-pixel', 'linkedin-insight', 'tiktok-pixel', 'pinterest-tag' => 'pixel_id',
            'hotjar' => 'hotjar_id',
            'hubspot' => 'hubspot_id',
            'clarity' => 'clarity_id',
            default => null,
        };

        $settingsData = [];
        if ($trackingField && ! empty($data['tracking_id'])) {
            $settingsData[$trackingField] = $data['tracking_id'];
        }

        if (! empty($tpl['payloads'])) {
            $settingsData['opt_in_code'] = $tpl['payloads']['opt_in'] ?? null;
            $settingsData['opt_out_code'] = $tpl['payloads']['opt_out'] ?? null;
            $settingsData['fallback_code'] = $tpl['payloads']['fallback'] ?? null;
        }

        $service->settings()->create($settingsData);

        // Auto-create linked script blocker if template defines one
        if (! empty($tpl['script_blocker'])) {
            foreach ($domainIds as $domainId) {
                ScriptBlocker::create([
                    'domain_id' => $domainId,
                    'service_id' => $service->id,
                    'name' => $tpl['name'].' Script Blocker',
                    'key' => $tpl['key'].'-sb',
                    'template_key' => $tpl['key'].'-sb',
                    'handles' => $tpl['script_blocker']['handles'] ?? [],
                    'phrases' => $tpl['script_blocker']['phrases'] ?? [],
                    'is_active' => true,
                    'blocker_type' => ScriptBlocker::TYPE_SCRIPT,
                ]);
            }
        }
    }

    protected function installScriptBlocker(array $tpl, array $data, array $domainIds): void
    {
        $serviceId = $data['linked_service_id'] ?? null;

        // If linked_service_key is set, try to find it
        if (! $serviceId && ! empty($tpl['linked_service_key'])) {
            $serviceId = Service::where('key', $tpl['linked_service_key'])->value('id');
        }

        foreach ($domainIds as $domainId) {
            ScriptBlocker::create([
                'domain_id' => $domainId,
                'service_id' => $serviceId,
                'name' => $tpl['name'],
                'key' => $tpl['key'],
                'template_key' => $tpl['key'],
                'template_version' => $tpl['version'] ?? '1.0.0',
                'handles' => $tpl['handles'] ?? [],
                'phrases' => $tpl['phrases'] ?? [],
                'is_active' => true,
                'blocker_type' => ScriptBlocker::TYPE_SCRIPT,
            ]);
        }
    }

    protected function installContentBlocker(array $tpl, array $data, array $domainIds): void
    {
        $serviceId = $data['linked_service_id'] ?? null;

        // If linked_service_key is set, try to find it
        if (! $serviceId && ! empty($tpl['linked_service_key'])) {
            $serviceId = Service::where('key', $tpl['linked_service_key'])->value('id');
        }

        foreach ($domainIds as $domainId) {
            ContentBlocker::create([
                'domain_id' => $domainId,
                'service_id' => $serviceId,
                'name' => $tpl['name'],
                'key' => $tpl['key'],
                'template_key' => $tpl['key'],
                'template_version' => $tpl['version'] ?? '1.0.0',
                'hosts' => $tpl['hosts'] ?? [],
                'is_active' => true,
                'text_placeholders' => $tpl['placeholder'] ?? [],
                'display_mode' => $tpl['display_mode'] ?? 'inline',
                'floating_position' => $tpl['floating_position'] ?? null,
            ]);
        }
    }

    protected function installStyleBlocker(array $tpl, array $data, array $domainIds): void
    {
        // Style blockers are stored as ScriptBlockers with a blocker_type marker
        $serviceId = $data['linked_service_id'] ?? null;

        if (! $serviceId && ! empty($tpl['linked_service_key'])) {
            $serviceId = Service::where('key', $tpl['linked_service_key'])->value('id');
        }

        foreach ($domainIds as $domainId) {
            ScriptBlocker::create([
                'domain_id' => $domainId,
                'service_id' => $serviceId,
                'name' => $tpl['name'],
                'key' => $tpl['key'],
                'template_key' => $tpl['key'],
                'template_version' => $tpl['version'] ?? '1.0.0',
                'handles' => $tpl['handles'] ?? [],
                'phrases' => $tpl['phrases'] ?? [],
                'is_active' => true,
                'blocker_type' => ScriptBlocker::TYPE_STYLE,
            ]);
        }
    }

    /**
     * Apply an update from the library template to the installed package.
     * Preserves tracking IDs but updates code payloads, cookies, purpose, etc.
     */
    protected function applyUpdate(array $tpl): void
    {
        $key = $tpl['key'];
        $type = $tpl['type'];
        $newVersion = $tpl['version'] ?? '1.0.0';

        if ($type === 'service') {
            $service = Service::where('template_key', $key)->first();
            if (! $service) {
                return;
            }

            // Update service metadata (not user-configurable tracking IDs)
            $service->update([
                'purpose' => $tpl['purpose'],
                'instructions' => $tpl['instructions'] ?? $service->instructions,
                'template_version' => $newVersion,
            ]);

            // Update cookies — replace with new set
            $service->cookies()->delete();
            if (isset($tpl['cookies']) && is_array($tpl['cookies'])) {
                foreach ($tpl['cookies'] as $cookie) {
                    $service->cookies()->create([
                        'name' => $cookie['name'],
                        'lifetime' => $cookie['lifetime'],
                        'purpose' => $cookie['purpose'],
                        'hostname' => '',
                    ]);
                }
            }

            // Update code payloads BUT preserve tracking IDs
            $settings = $service->settings;
            if ($settings && ! empty($tpl['payloads'])) {
                // Collect existing tracking ID values
                $preservedFields = ['gtm_id', 'ga_id', 'pixel_id', 'hotjar_id', 'hubspot_id', 'clarity_id'];
                $trackerValues = [];
                foreach ($preservedFields as $field) {
                    if (! empty($settings->{$field})) {
                        $trackerValues[$field] = $settings->{$field};
                    }
                }

                // Replace code payloads
                $newOptIn = $tpl['payloads']['opt_in'] ?? null;

                // Re-inject tracking IDs into the new code
                foreach ($trackerValues as $field => $value) {
                    $placeholder = '{{'.$field.'}}';
                    if ($newOptIn) {
                        $newOptIn = str_replace($placeholder, $value, $newOptIn);
                    }
                }

                $settings->update([
                    'opt_in_code' => $newOptIn,
                    'opt_out_code' => $tpl['payloads']['opt_out'] ?? $settings->opt_out_code,
                    'fallback_code' => $tpl['payloads']['fallback'] ?? $settings->fallback_code,
                ]);
            }

            // Update linked script blockers phrases/handles if defined
            if (! empty($tpl['script_blocker'])) {
                ScriptBlocker::where('service_id', $service->id)
                    ->where('template_key', 'like', $key.'%')
                    ->where('blocker_type', ScriptBlocker::TYPE_SCRIPT)
                    ->update([
                        'handles' => json_encode($tpl['script_blocker']['handles'] ?? []),
                        'phrases' => json_encode($tpl['script_blocker']['phrases'] ?? []),
                        'template_version' => $newVersion,
                    ]);
            }
        } elseif ($type === 'script_blocker') {
            ScriptBlocker::where('template_key', $key)
                ->where('blocker_type', ScriptBlocker::TYPE_SCRIPT)
                ->update([
                'handles' => json_encode($tpl['handles'] ?? []),
                'phrases' => json_encode($tpl['phrases'] ?? []),
                'template_version' => $newVersion,
            ]);
        } elseif ($type === 'content_blocker') {
            ContentBlocker::where('template_key', $key)->update([
                'hosts' => json_encode($tpl['hosts'] ?? []),
                'text_placeholders' => json_encode($tpl['placeholder'] ?? []),
                'display_mode' => $tpl['display_mode'] ?? 'inline',
                'floating_position' => $tpl['floating_position'] ?? null,
                'template_version' => $newVersion,
            ]);
        } elseif ($type === 'style_blocker') {
            ScriptBlocker::where('template_key', $key)
                ->where('blocker_type', ScriptBlocker::TYPE_STYLE)
                ->update([
                'handles' => json_encode($tpl['handles'] ?? []),
                'phrases' => json_encode($tpl['phrases'] ?? []),
                'template_version' => $newVersion,
            ]);
        }

        // Mark any update notifications for this package as read
        NotificationService::markPackageUpdateAsRead($key);
    }

    protected function getTemplateByKey(string $key): ?array
    {
        return TemplateLibraryService::getTemplates()[$key] ?? null;
    }
}
