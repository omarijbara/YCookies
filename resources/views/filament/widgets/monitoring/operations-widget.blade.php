<x-filament-widgets::widget>
    <div x-data="{ expanded: {{ $hasIssues ? 'true' : 'false' }} }" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        
        <!-- Header / Toggle -->
        <button 
            type="button" 
            @click="expanded = !expanded"
            class="flex w-full items-center justify-between px-6 py-4 hover:bg-gray-50 dark:hover:bg-white/5 rounded-xl transition-colors"
        >
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-gray-100 p-2 dark:bg-white/5">
                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                </div>
                <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    Operations & Monitoring
                </h3>
                <div class="ml-3 flex items-center gap-2">
                    @if($runningContainers > 0)
                        <span class="inline-flex items-center rounded-md bg-success-50 px-2 py-1 text-xs font-medium text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/20">
                            {{ $runningContainers }} containers running
                        </span>
                    @endif
                    @if($unresolvedErrors > 0)
                        <span class="inline-flex items-center rounded-md bg-danger-50 px-2 py-1 text-xs font-medium text-danger-700 ring-1 ring-inset ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-500/20">
                            {{ $unresolvedErrors }} unresolved errors
                        </span>
                    @endif
                </div>
            </div>
            <x-heroicon-m-chevron-down 
                class="h-5 w-5 text-gray-400 transition-transform duration-200"
                x-bind:class="{ 'rotate-180': expanded }"
            />
        </button>

        <!-- Collapsible Content -->
        <div x-show="expanded" x-collapse>
            <div class="border-t border-gray-100 p-6 dark:border-white/10">
                <div class="grid grid-cols-1 gap-6">
                    @livewire(\App\Filament\Widgets\Monitoring\ServerInfraWidget::class, ['isDashboard' => true])
                    @livewire(\App\Filament\Widgets\Monitoring\TrafficAlertsWidget::class)
                    @livewire(\App\Filament\Widgets\Monitoring\CrashReportsWidget::class, ['isDashboard' => true])
                </div>
            </div>
        </div>

    </div>
</x-filament-widgets::widget>
