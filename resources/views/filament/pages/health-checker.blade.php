<x-filament-panels::page>
    <div x-data="{ activeTab: 'domain-health' }" class="space-y-6">
        
        {{-- ── Premium Tab Navigation ── --}}
        <div class="flex flex-col sm:flex-row gap-2 sm:gap-1 p-1.5 bg-white/[0.03] border border-white/10 rounded-2xl shadow-sm overflow-x-auto hide-scrollbar backdrop-blur-sm">
            <button @click="activeTab = 'domain-health'" 
                    :class="activeTab === 'domain-health' ? 'bg-primary-500/10 text-primary-400 border-primary-500/30 shadow-sm' : 'text-gray-400 hover:text-gray-200 hover:bg-white/5 border-transparent'"
                    class="flex flex-1 items-center justify-center gap-2.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all duration-300 border whitespace-nowrap">
                <x-heroicon-o-shield-check class="h-5 w-5 transition-transform duration-300" x-bind:class="activeTab === 'domain-health' ? 'scale-110' : ''" />
                Domain Checker
            </button>
            

            <button @click="activeTab = 'crash-reports'" 
                    :class="activeTab === 'crash-reports' ? 'bg-rose-500/10 text-rose-400 border-rose-500/30 shadow-sm' : 'text-gray-400 hover:text-gray-200 hover:bg-white/5 border-transparent'"
                    class="flex flex-1 items-center justify-center gap-2.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all duration-300 border whitespace-nowrap">
                <x-heroicon-o-bug-ant class="h-5 w-5 transition-transform duration-300" x-bind:class="activeTab === 'crash-reports' ? 'scale-110' : ''" />
                System Errors
            </button>
            
            <button @click="activeTab = 'traffic'" 
                    :class="activeTab === 'traffic' ? 'bg-amber-500/10 text-amber-400 border-amber-500/30 shadow-sm' : 'text-gray-400 hover:text-gray-200 hover:bg-white/5 border-transparent'"
                    class="flex flex-1 items-center justify-center gap-2.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all duration-300 border whitespace-nowrap">
                <x-heroicon-o-bell-alert class="h-5 w-5 transition-transform duration-300" x-bind:class="activeTab === 'traffic' ? 'scale-110' : ''" />
                Traffic Alerts
            </button>

            <button @click="activeTab = 'server'" 
                    :class="activeTab === 'server' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30 shadow-sm' : 'text-gray-400 hover:text-gray-200 hover:bg-white/5 border-transparent'"
                    class="flex flex-1 items-center justify-center gap-2.5 px-4 py-3 rounded-xl text-sm font-semibold transition-all duration-300 border whitespace-nowrap">
                <x-heroicon-o-server-stack class="h-5 w-5 transition-transform duration-300" x-bind:class="activeTab === 'server' ? 'scale-110' : ''" />
                Server
            </button>
        </div>

        {{-- ── Tab Contents ── --}}
        <div class="relative w-full">
            {{-- Domain Health --}}
            <div x-show="activeTab === 'domain-health'" 
                 x-transition:enter="transition ease-out duration-300 transform" 
                 x-transition:enter-start="opacity-0 translate-y-2" 
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="w-full space-y-6">
                
                @livewire(\App\Filament\Widgets\Monitoring\HealthOverviewWidget::class)
                
                @livewire(\App\Filament\Widgets\Monitoring\HealthHistoryWidget::class)
            </div>



            {{-- System Errors (Unified) --}}
            <div x-show="activeTab === 'crash-reports'" 
                 style="display: none;"
                 x-transition:enter="transition ease-out duration-300 transform" 
                 x-transition:enter-start="opacity-0 translate-y-2" 
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="w-full space-y-6">
                 @livewire(\App\Filament\Widgets\Monitoring\CrashReportsWidget::class)
            </div>

            {{-- Traffic Alerts --}}
            <div x-show="activeTab === 'traffic'" 
                 style="display: none;"
                 x-transition:enter="transition ease-out duration-300 transform" 
                 x-transition:enter-start="opacity-0 translate-y-2" 
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="w-full">
                 @livewire(\App\Filament\Widgets\Monitoring\TrafficAlertsWidget::class, ['showAll' => true])
            </div>

            {{-- Server Infrastructure --}}
            <div x-show="activeTab === 'server'" 
                 style="display: none;"
                 x-transition:enter="transition ease-out duration-300 transform" 
                 x-transition:enter-start="opacity-0 translate-y-2" 
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="w-full">
                 @livewire(\App\Filament\Widgets\Monitoring\ServerInfraWidget::class)
            </div>
        </div>

    </div>
</x-filament-panels::page>

