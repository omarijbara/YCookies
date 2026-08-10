<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Models\Domain;
use App\Models\CookieGroup;
use App\Models\Provider;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Str;

class ServiceLibrary extends Page
{
    protected static string $resource = ServiceResource::class;

    protected static ?string $title = 'Library';
    public static function getNavigationLabel(): string
    {
        return __('ycookies.resources.library');
    }

    protected string $view = 'filament.resources.services.pages.service-library';

    public function getTemplates(): array
    {
        return \App\Services\TemplateLibraryService::getTemplates();
    }

    public function installTemplateAction(): Action
    {
        return Action::make('installTemplate')
            ->label('Install Template')
            ->modalHeading(fn (array $arguments) => 'Install ' . ($this->getTemplates()[$arguments['template'] ?? '']['name'] ?? 'Template'))
            ->form([
                Select::make('domain_ids')
                    ->label('Assigned Domains')
                    ->options(Domain::pluck('name', 'id'))
                    ->multiple()
                    ->required(),
                Select::make('cookie_group_id')
                    ->label('Cookie Group')
                    ->options(CookieGroup::pluck('name', 'id'))
                    ->required(),
                TextInput::make('tracking_id')
                    ->label('Tracking ID (Optional)')
                    ->helperText('e.g., G-XXXX, GTM-XXXX, Pixel ID, if applicable.'),
            ])
            ->action(function (array $data, array $arguments) {
                $templateKey = $arguments['template'];
                $tpl = $this->getTemplates()[$templateKey];

                $existingService = Service::where('key', $tpl['key'])->first();
                
                if ($existingService) {
                    \Filament\Notifications\Notification::make()
                        ->warning()
                        ->title('Service Already Installed')
                        ->body("The service '{$tpl['name']}' is already installed in your library.")
                        ->send();

                    return redirect(ServiceResource::getUrl('edit', ['record' => $existingService->id]));
                }

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
                    // Consent Execution Registry v2
                    'integration_type' => $tpl['integration_type'] ?? 'browser_tag',
                    'provider_key' => $tpl['provider_key'] ?? null,
                    'service_domains' => $tpl['domains'] ?? null,
                    'consent_mode_mapping' => $tpl['consent_mode_mapping'] ?? null,
                    'supports_accept_once' => $tpl['supports_accept_once'] ?? false,
                    'supports_accept_provider' => $tpl['supports_accept_provider'] ?? false,
                ]);

                $service->domains()->sync($data['domain_ids']);

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

                $trackingField = match ($templateKey) {
                    'google-analytics' => 'ga_id',
                    'google-tag-manager' => 'gtm_id',
                    'meta-pixel' => 'pixel_id',
                    'linkedin-insight' => 'pixel_id',
                    'tiktok-pixel' => 'pixel_id',
                    default => null,
                };

                $settingsData = [];
                if ($trackingField && !empty($data['tracking_id'])) {
                    $settingsData[$trackingField] = $data['tracking_id'];
                }

                if (!empty($tpl['payloads'])) {
                    $settingsData['opt_in_code'] = $tpl['payloads']['opt_in'] ?? null;
                    $settingsData['opt_out_code'] = $tpl['payloads']['opt_out'] ?? null;
                    $settingsData['fallback_code'] = $tpl['payloads']['fallback'] ?? null;
                }

                $service->settings()->create($settingsData);

                return redirect(ServiceResource::getUrl('edit', ['record' => $service->id]));
            });
    }
}

