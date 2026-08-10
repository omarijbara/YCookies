<x-filament-widgets::widget class="space-y-6">
    <div x-data="{
        running: @entangle('isRunning'),
        checks: @js(array_values(\App\Filament\Widgets\Monitoring\HealthOverviewWidget::CHECK_LABELS)),
        currentIndex: 0,
        timer: null,
        safetyTimer: null,
        startRun() {
            this.running = true;
            this.currentIndex = 0;
            this.timer = setInterval(() => {
                if (this.currentIndex < this.checks.length) {
                    this.currentIndex++;
                }
            }, 2000);
            // Safety: force-hide after 120s
            this.safetyTimer = setTimeout(() => { this.stopRun(); }, 120000);
            $wire.runNow();
        },
        stopRun() {
            this.running = false;
            if (this.timer) { clearInterval(this.timer); this.timer = null; }
            if (this.safetyTimer) { clearTimeout(this.safetyTimer); this.safetyTimer = null; }
        },
        get percent() {
            return Math.round((this.currentIndex / this.checks.length) * 100);
        }
    }"
    x-on:health-check-done.window="stopRun()">
    
        <x-filament::section>
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div class="flex-1 max-w-md">
                    <label class="block text-sm font-medium leading-6 text-gray-950 dark:text-white mb-1">Domain</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="selectedDomainId">
                            @foreach($this->getDomains() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                
                @if($schedulerEnabled)
                    <div class="flex flex-col px-4 py-2 rounded-lg bg-gray-50 dark:bg-white/5 ring-1 ring-gray-950/5 dark:ring-white/10 sm:min-w-[200px]">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                            </span>
                            <span class="text-xs font-semibold text-gray-950 dark:text-white">Auto-Scan Active</span>
                        </div>
                        <div class="flex items-center gap-3 text-[11px] text-gray-500 dark:text-gray-400">
                            <span>Last: <strong class="text-gray-700 dark:text-gray-300">{{ $schedulerLastRun }}</strong></span>
                            <span>Next: <strong class="text-gray-700 dark:text-gray-300">{{ $schedulerNextRun }}</strong></span>
                        </div>
                    </div>
                @endif
                
                <div class="flex flex-wrap items-center gap-3 mt-4 sm:mt-0">
                    {{ $this->manageSettingsAction }}
                    
                    <x-filament::button
                        @click="startRun()"
                        x-bind:disabled="running"
                        color="primary"
                        icon="heroicon-o-play">
                        <span x-show="!running">Run Health Check</span>
                        <span x-show="running" class="inline-flex items-center gap-2">
                            <x-filament::loading-indicator class="h-4 w-4" /> Running...
                        </span>
                    </x-filament::button>
                </div>
            </div>

            {{-- ── Live Progress Panel ── --}}
            <div x-show="running" x-transition class="mt-6 p-4 rounded-xl ring-1 ring-primary-500/20 bg-primary-50 dark:bg-primary-500/10">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-3 text-primary-600 dark:text-primary-400">
                        <h3 class="text-sm font-bold">Executing Analysis...</h3>
                    </div>
                    <span class="text-sm font-bold text-primary-600 dark:text-primary-400" x-text="percent + '%'"></span>
                </div>
                
                <div class="w-full bg-primary-100 dark:bg-primary-900/40 rounded-full h-2 overflow-hidden mb-4">
                    <div class="bg-primary-600 dark:bg-primary-500 h-2 rounded-full transition-all duration-700 ease-out"
                         :style="'width: ' + percent + '%'"></div>
                </div>
                
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    <template x-for="(label, i) in checks" :key="i">
                        <div class="flex items-center gap-2 text-xs transition-all duration-300"
                             :class="i < currentIndex ? 'text-gray-500 dark:text-gray-400' : (i === currentIndex ? 'text-primary-600 dark:text-primary-400 font-bold' : 'text-gray-400 dark:text-gray-600')">
                            <span x-text="i < currentIndex ? '✓' : (i === currentIndex ? '●' : '○')"></span>
                            <span class="truncate" x-text="label"></span>
                        </div>
                    </template>
                </div>
            </div>
        </x-filament::section>
    </div>

    {{-- ── Latest Status Card ── --}}
    @if($latestResult && !$isRunning)
        <div class="flex flex-col gap-6">
            <x-filament::section>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div @class([
                            'p-3 rounded-full',
                            'bg-success-50 text-success-600 dark:bg-success-500/10 dark:text-success-500' => ($latestResult['status'] ?? '') === 'healthy',
                            'bg-warning-50 text-warning-600 dark:bg-warning-500/10 dark:text-warning-500' => ($latestResult['status'] ?? '') === 'warning',
                            'bg-danger-50 text-danger-600 dark:bg-danger-500/10 dark:text-danger-500' => ($latestResult['status'] ?? '') === 'failing',
                            'bg-gray-50 text-gray-600 dark:bg-gray-500/10 dark:text-gray-500' => !in_array($latestResult['status'] ?? '', ['healthy', 'warning', 'failing']),
                        ])>
                            @if(($latestResult['status'] ?? '') === 'healthy') <x-heroicon-o-check-circle class="w-8 h-8" />
                            @elseif(($latestResult['status'] ?? '') === 'warning') <x-heroicon-o-exclamation-triangle class="w-8 h-8" />
                            @elseif(($latestResult['status'] ?? '') === 'failing') <x-heroicon-o-x-circle class="w-8 h-8" />
                            @else <x-heroicon-o-question-mark-circle class="w-8 h-8" /> @endif
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-950 dark:text-white">{{ ucfirst($latestResult['status'] ?? 'Unknown') }}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $latestResult['checks_passed'] ?? 0 }}/{{ $latestResult['checks_total'] ?? 0 }} checks passed
                                · {{ $latestResult['duration_ms'] ?? 0 }}ms
                                · {{ $latestResult['checked_at'] ?? 'Unknown' }}
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <x-filament::button wire:click="analyzeWithAI" color="info" icon="heroicon-o-sparkles" wire:loading.attr="disabled">
                            AI Diagnosis
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach(($latestResult['checks'] ?? []) as $check)
                    @php
                        $checkStatus = $check['status'] ?? '';
                        $color = match($checkStatus) {
                            'pass' => 'success',
                            'warn' => 'warning',
                            'fail' => 'danger',
                            default => 'gray',
                        };
                    @endphp
                    <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 bg-white dark:bg-gray-900 shadow-sm p-4 flex items-start gap-3">
                        <x-filament::badge :color="$color" class="mt-0.5">
                            {{ match($checkStatus) { 'pass' => '✓', 'warn' => '⚠', 'fail' => '✗', default => '●' } }}
                        </x-filament::badge>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-950 dark:text-white truncate">{{ $check['label'] ?? $check['name'] }}</p>
                            @if(!empty($check['message']))
                                <p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2 mt-1" title="{{ $check['message'] }}">{{ $check['message'] }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- ── AI Diagnosis Panel ── --}}
            @if($aiDiagnosis)
                <x-filament::section>
                    <x-slot name="heading">
                        <span class="flex items-center gap-2">
                            🤖 AI Health Diagnosis
                        </span>
                    </x-slot>
                    <x-slot name="headerEnd">
                        <x-filament::badge color="info">
                            {{ $aiDiagnosis['overall_assessment'] ?? 'analysis' }}
                        </x-filament::badge>
                    </x-slot>

                    <div class="space-y-4">
                        <div>
                            <h4 class="text-sm font-medium text-gray-950 dark:text-white mb-1">Root Cause</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">{{ $aiDiagnosis['root_cause'] ?? '' }}</p>
                        </div>
                        @if(!empty($aiDiagnosis['suggested_fixes']))
                            <div>
                                <h4 class="text-sm font-medium text-gray-950 dark:text-white mb-2">Suggested Fixes</h4>
                                <div class="space-y-3">
                                    @foreach($aiDiagnosis['suggested_fixes'] as $fix)
                                        <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3 ring-1 ring-gray-950/5 dark:ring-white/10">
                                            <div class="flex items-center gap-2 mb-1">
                                                <x-filament::badge color="primary">#{{ $fix['priority'] ?? 'fix' }}</x-filament::badge>
                                                <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $fix['title'] ?? '' }}</span>
                                            </div>
                                            <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">{{ $fix['description'] ?? '' }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </x-filament::section>
            @endif
        </div>
    @endif

    <x-filament-actions::modals />
</x-filament-widgets::widget>
