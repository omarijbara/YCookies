<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateDomain extends CreateRecord
{
    use \Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
    
    protected static string $resource = DomainResource::class;

    public function mount(): void
    {
        // Redirect to upgrade page if domain limit is reached
        $tenant = Filament::getTenant();
        if ($tenant && $tenant->domains()->count() >= $tenant->domain_limit) {
            $this->redirect(\App\Filament\Pages\BillingUpgrade::getUrl(['tenant' => $tenant]));
            return;
        }

        parent::mount();
    }

    protected function getSteps(): array
    {
        return [
            \Filament\Schemas\Components\Wizard\Step::make('Domain Info')
                ->description('Name and general settings')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('name')
                        ->required()
                        ->placeholder('example.com'),
                    \Filament\Forms\Components\TextInput::make('site_id')
                        ->label('Site ID')
                        ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'Unique identifier used to link this domain to the consent script.')
                        ->helperText('Unique 32-character UUID used to link the JS client.')
                        ->default(fn () => (string) \Illuminate\Support\Str::uuid())
                        ->required()
                        ->unique(ignoreRecord: true),
                    \Filament\Forms\Components\Toggle::make('is_active')
                        ->default(true)
                        ->required(),
                ]),
                
            \Filament\Schemas\Components\Wizard\Step::make('Deployment Mode')
                ->description('Proxy vs Script Tag')
                ->schema([
                    \Filament\Forms\Components\Toggle::make('proxy_enabled')
                        ->label('Enable Reverse Proxy Engine')
                        ->helperText('When enabled, traffic for this domain will be routed through the YCookies Edge Proxy network. Disable to use standard Script Tag injection.')
                        ->default(true)
                        ->disabled(function () {
                            $tenant = \Filament\Facades\Filament::getTenant();
                            return $tenant && !$tenant->canCreateDomain();
                        })
                        ->live(),
                        
                    \Filament\Forms\Components\TextInput::make('origin_subdomain')
                        ->label('Origin Subdomain')
                        ->placeholder('e.g., origin.yourdomain.com')
                        ->helperText('Create this subdomain on your real server pointing to your server IP. Required if proxy is enabled.')
                        ->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('proxy_enabled')),
                ]),
                
            \Filament\Schemas\Components\Wizard\Step::make('Review & Finish')
                ->description('Deploy configuration')
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('setup')
                        ->label('Next Steps')
                        ->content(new \Illuminate\Support\HtmlString('
                            <div class="prose dark:prose-invert">
                                <p>Once you finish creating this domain:</p>
                                <ul>
                                    <li>If <strong>Proxy Mode</strong> is enabled, point your CNAME record to <code>proxy.ycookies.io</code>.</li>
                                    <li>If <strong>Script Mode</strong> is enabled, you will find the HTML snippet to embed on your website inside the domain overview.</li>
                                </ul>
                                <p><strong>Note:</strong> We will begin scanning cookies immediately upon creation.</p>
                            </div>
                        ')),
                ]),
        ];
    }
}
