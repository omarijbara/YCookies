<x-filament-widgets::widget>
    <div class="flex flex-wrap gap-3">
        <a href="{{ \App\Filament\Resources\Domains\Pages\CreateDomain::getUrl() }}" 
           class="inline-flex items-center gap-2 rounded-lg bg-primary-500/10 px-4 py-2.5 text-sm font-semibold text-primary-400 ring-1 ring-primary-500/20 transition hover:bg-primary-500/20">
            <x-heroicon-m-plus class="h-4 w-4" />
            Add Domain
        </a>
        <a href="{{ route('filament.admin.pages.script-scanner', ['tenant' => \Filament\Facades\Filament::getTenant()?->id ?? 1]) }}" 
           class="inline-flex items-center gap-2 rounded-lg bg-emerald-500/10 px-4 py-2.5 text-sm font-semibold text-emerald-400 ring-1 ring-emerald-500/20 transition hover:bg-emerald-500/20">
            <x-heroicon-m-document-magnifying-glass class="h-4 w-4" />
            Run Scan
        </a>
        <a href="{{ \App\Filament\Resources\TrafficAlerts\TrafficAlertResource::getUrl() }}" 
           class="inline-flex items-center gap-2 rounded-lg bg-red-500/10 px-4 py-2.5 text-sm font-semibold text-red-400 ring-1 ring-red-500/20 transition hover:bg-red-500/20">
            <x-heroicon-m-bell-alert class="h-4 w-4" />
            View Alerts
        </a>
    </div>
</x-filament-widgets::widget>
