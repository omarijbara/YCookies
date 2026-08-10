<x-filament-panels::page>
    <style>
        .stat-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.2); }
        .script-row { transition: background 0.15s ease; }
        .script-row:hover { background: rgba(255,255,255,0.05); }
        @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
        .float-anim { animation: float 4s ease-in-out infinite; }
        .icon-sm svg { width: 14px !important; height: 14px !important; flex-shrink: 0; }
        .icon-md svg { width: 16px !important; height: 16px !important; flex-shrink: 0; }
        .icon-lg svg { width: 36px !important; height: 36px !important; flex-shrink: 0; }
        .icon-box { overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
        .script-scanner-page { max-width: 72rem; }
        .script-scanner-grid-2 {
            display: grid;
            gap: 1rem;
            margin-top: 1rem;
            grid-template-columns: 1fr;
        }
        @media (min-width: 960px) {
            .script-scanner-grid-2 { grid-template-columns: 1fr 1fr; }
        }
        .script-scanner-stats {
            display: grid;
            gap: 0.75rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        @media (min-width: 640px) {
            .script-scanner-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        /* ── Scanner card system ── */
        .scanner-card {
            border-radius: 12px;
            background: #18181b;
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 20px;
            transition: border-color 0.2s ease;
        }
        .scanner-card:hover {
            border-color: rgba(255, 255, 255, 0.12);
        }
        .scanner-card-header {
            display: flex; align-items: center; gap: 8px; margin-bottom: 12px;
        }
        .scanner-card-header h3 {
            font-size: 14px; font-weight: 700; color: #f3f4f6; margin: 0;
        }
        .scanner-card-header .subtitle {
            font-size: 11px; color: #6b7280;
        }
        .scanner-card-header .icon {
            font-size: 16px;
        }
        /* ── Stage pill bar ── */
        .scanner-stage-bar {
            border-radius: 12px;
            background: #18181b;
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 16px 20px;
        }
        .scanner-stage-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 8px;
            font-size: 11px; font-weight: 700;
        }
        /* ── Scan log accordion ── */
        .scanner-accordion {
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            overflow: hidden;
        }
        .scanner-accordion-trigger {
            width: 100%; padding: 14px 18px;
            display: flex; align-items: center; justify-content: space-between;
            background: #18181b;
            border: none; cursor: pointer; color: #9ca3af;
            transition: background 0.15s ease;
        }
        .scanner-accordion-trigger:hover {
            background: #1f1f23;
        }
    </style>

    <div class="script-scanner-page mx-auto w-full space-y-6 pb-10">
        <div x-data="{ showHelp: false }" class="relative z-10 -mb-2 flex justify-end">
            <button @click="showHelp = !showHelp" @click.away="showHelp = false" type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-primary-500 dark:text-gray-400 dark:hover:text-primary-400 transition bg-white/5 px-3 py-1.5 rounded-full border border-white/10 shadow-sm focus:outline-none">
                <x-heroicon-o-information-circle class="w-4 h-4" />
                {{ __('ycookies.script_scanner.how_it_works') }}
            </button>
            <div x-show="showHelp" 
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 translate-y-2"
                 style="display: none;" 
                 class="absolute top-full right-0 mt-3 w-[calc(100vw-3rem)] sm:w-[450px] max-w-full shadow-2xl z-20">
                <div class="bg-white shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 rounded-xl p-5">
                    <h3 class="text-sm font-bold text-gray-950 dark:text-white mb-4 flex items-center gap-2">
                        <x-heroicon-s-information-circle class="w-5 h-5 text-primary-500" />
                        {{ __('ycookies.script_scanner.how_it_works') }}
                    </h3>
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-3 text-[13px] leading-relaxed p-3 px-4 rounded-lg transition-all duration-200 bg-gray-50 border border-gray-200 text-gray-700 hover:bg-gray-100 hover:border-gray-300 dark:bg-white/5 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:border-white/20">
                            <span class="flex shrink-0 items-center justify-center w-6 h-6 rounded-full text-xs font-bold bg-primary-600 text-white shadow-sm ring-1 ring-primary-600/50">1</span>
                            <div>{{ __('ycookies.script_scanner.step_1') }}</div>
                        </div>
                        <div class="flex items-center gap-3 text-[13px] leading-relaxed p-3 px-4 rounded-lg transition-all duration-200 bg-gray-50 border border-gray-200 text-gray-700 hover:bg-gray-100 hover:border-gray-300 dark:bg-white/5 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:border-white/20">
                            <span class="flex shrink-0 items-center justify-center w-6 h-6 rounded-full text-xs font-bold bg-primary-600 text-white shadow-sm ring-1 ring-primary-600/50">2</span>
                            <div>{{ __('ycookies.script_scanner.step_2') }}</div>
                        </div>
                        <div class="flex items-center gap-3 text-[13px] leading-relaxed p-3 px-4 rounded-lg transition-all duration-200 bg-gray-50 border border-gray-200 text-gray-700 hover:bg-gray-100 hover:border-gray-300 dark:bg-white/5 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:border-white/20">
                            <span class="flex shrink-0 items-center justify-center w-6 h-6 rounded-full text-xs font-bold bg-primary-600 text-white shadow-sm ring-1 ring-primary-600/50">3</span>
                            <div>{{ __('ycookies.script_scanner.step_3') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-5">

        <x-filament::section
            icon="heroicon-o-globe-alt"
            :heading="__('ycookies.script_scanner.section_start')"
            :description="__('ycookies.script_scanner.section_start_desc')"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                <div class="min-w-0 flex-1 sm:min-w-[200px]">
                    <label class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">
                        {{ __('ycookies.script_scanner.label_domain') }}
                    </label>
                    <select wire:model.live="selectedDomainId"
                        class="fi-select-input block w-full rounded-lg border-none bg-white/5 py-2 pe-8 ps-3 text-sm text-white shadow-sm ring-1 ring-white/10 transition duration-75 focus:ring-2 focus:ring-primary-600 dark:text-white">
                        <option value="">{{ __('ycookies.script_scanner.placeholder_domain') }}</option>
                        @foreach($this->getDomains() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                        <option value="custom">{{ __('ycookies.script_scanner.custom_url') }}</option>
                    </select>
                </div>

                @if($selectedDomainId === 'custom')
                    <div class="min-w-0 flex-1 sm:min-w-[220px]">
                        <label class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('ycookies.script_scanner.url_label') }}</label>
                        <input type="url" wire:model="customDomainUrl" placeholder="{{ __('ycookies.script_scanner.custom_url_placeholder') }}"
                            class="fi-input block w-full rounded-lg border-none bg-white/5 py-2 pe-3 ps-3 text-sm text-white shadow-sm ring-1 ring-white/10 transition duration-75 focus:ring-2 focus:ring-primary-600 dark:text-white" />
                    </div>
                @endif

                @php
                    $isAutoScanActive = false;
                    $scanLastRunStr = 'Never';
                    $scanNextRunStr = 'Pending';
                    if ($selectedDomainId && $selectedDomainId !== 'custom') {
                        $domain = \App\Models\Domain::find($selectedDomainId);
                        if ($domain && ($this->data['schedulerEnabled'] ?? $domain->scheduler_enabled ?? false)) {
                            $isAutoScanActive = true;
                            if ($domain->last_scan_at) {
                                $df = config('app.date_format', 'd.m.Y');
                                $tf = config('app.time_format', 'H:i');
                                $tz = config('app.timezone', 'UTC');

                                $lastScanTz = $domain->last_scan_at->copy()->timezone($tz);
                                if ($lastScanTz->isToday()) {
                                    $scanLastRunStr = 'Today at ' . $lastScanTz->format($tf);
                                } elseif ($lastScanTz->isYesterday()) {
                                    $scanLastRunStr = 'Yesterday at ' . $lastScanTz->format($tf);
                                } else {
                                    $scanLastRunStr = $lastScanTz->format("$df, $tf");
                                }
                                
                                $lockMinutes = max(60, (int) ($this->data['lockMinutes'] ?? $domain->lock_minutes ?? 60));
                                $nextScanTz = $domain->last_scan_at->copy()->addMinutes($lockMinutes);
                                
                                // The true next run is the top of the next hour after the lockout expires,
                                // because the scheduler (php artisan ycookies:run-scans) only runs at 0 * * * *
                                $trueNextRunUTC = $nextScanTz->copy()->max(now())->ceilHour();
                                $trueNextRun = $trueNextRunUTC->copy()->timezone($tz);
                                
                                // If the time is exactly right now, it means it was just queued
                                if ($trueNextRunUTC->lte(now()) || (now()->minute < 5 && $trueNextRunUTC->isCurrentHour())) {
                                    $scanNextRunStr = 'Processing or Queued';
                                } else {
                                    if ($trueNextRun->isToday()) {
                                        $scanNextRunStr = 'Today at ' . $trueNextRun->format($tf);
                                    } elseif ($trueNextRun->isTomorrow()) {
                                        $scanNextRunStr = 'Tomorrow at ' . $trueNextRun->format($tf);
                                    } else {
                                        $scanNextRunStr = $trueNextRun->format("$df, $tf");
                                    }
                                }
                            }
                        }
                    }
                @endphp

                @if($isAutoScanActive)
                    <div class="flex flex-col px-4 py-2 rounded-lg bg-gray-50 dark:bg-white/5 ring-1 ring-gray-950/5 dark:ring-white/10 sm:min-w-[200px] mb-1 sm:mb-0 shrink-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                            </span>
                            <span class="text-xs font-semibold text-gray-950 dark:text-white">Auto-Scan Active</span>
                        </div>
                        <div class="flex items-center gap-3 text-[11px] text-gray-500 dark:text-gray-400">
                            <span>Last: <strong class="text-gray-700 dark:text-gray-300">{{ $scanLastRunStr }}</strong></span>
                            <span>Next: <strong class="text-gray-700 dark:text-gray-300">{{ $scanNextRunStr }}</strong></span>
                        </div>
                    </div>
                @endif

                <div class="flex items-center gap-2 shrink-0">
                    <button type="button" x-on:click="$dispatch('open-modal', { id: 'scheduler-settings-modal' })"
                        class="inline-flex items-center justify-center p-2 rounded-lg bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                        <x-heroicon-o-cog-6-tooth class="w-5 h-5" />
                    </button>
                    <button wire:click="scanBareDomain" wire:loading.attr="disabled" type="button"
                        class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-500 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-400 focus-visible:ring-offset-2 focus-visible:ring-offset-gray-950"
                        title="Scan without blockers — see the raw website as visitors see it before consent">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="h-4 w-4 shrink-0">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        <span wire:loading.remove wire:target="scanBareDomain">Baseline Scan</span>
                        <span wire:loading wire:target="scanBareDomain">{{ __('ycookies.script_scanner.scanning') }}</span>
                    </button>
                    <button wire:click="scanDomain" wire:loading.attr="disabled" type="button"
                        class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white bg-amber-600 hover:bg-amber-500 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-gray-950">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="h-4 w-4 shrink-0">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <span wire:loading.remove wire:target="scanDomain">{{ __('ycookies.script_scanner.start_scan') }}</span>
                        <span wire:loading wire:target="scanDomain">{{ __('ycookies.script_scanner.scanning') }}</span>
                    </button>
                </div>
            </div>
        </x-filament::section>

        @if($selectedDomainId && $selectedDomainId !== 'custom')
            {{-- ═══ Page Discovery & Priority Pages ═══ --}}
            <div class="script-scanner-grid-2">
                {{-- Page Discovery --}}
                <div class="scanner-card"
                    @if($isDiscovering) wire:poll.2s="pollDiscovery" @endif>
                    <div class="scanner-card-header">
                        <span class="icon">🔍</span>
                        <h3>Page Discovery</h3>
                    </div>

                    @if($isDiscovering)
                        @php
                            $allSteps = [
                                ['key' => 'robots',   'icon' => '🤖', 'label' => 'robots.txt'],
                                ['key' => 'sitemaps', 'icon' => '🗺️', 'label' => 'Sitemaps'],
                                ['key' => 'search',   'icon' => '🔎', 'label' => 'Search Engines'],
                                ['key' => 'crawl',    'icon' => '🕸️', 'label' => 'Internal Links'],
                                ['key' => 'common',   'icon' => '📁', 'label' => 'Common Paths'],
                            ];
                            $layers = $discoveryProgress['layers'] ?? [];
                            $totalSteps = count($allSteps);
                            $doneCount = 0;
                            $activeIndex = -1;
                            foreach ($allSteps as $i => $step) {
                                $state = $layers[$step['key']]['state'] ?? 'pending';
                                if ($state === 'done' || $state === 'empty' || $state === 'error') $doneCount++;
                                if ($state === 'running' && $activeIndex < 0) $activeIndex = $i;
                            }
                            $progressPct = $totalSteps > 0 ? round(($doneCount / $totalSteps) * 100) : 0;
                            // If organizing, boost to 90%
                            if (($discoveryProgress['status'] ?? '') === 'organizing') {
                                $progressPct = 90;
                            }
                            $totalFound = 0;
                            foreach ($layers as $l) {
                                $totalFound += ($l['count'] ?? 0);
                            }
                        @endphp

                        <style>
                            @keyframes spin { to { transform: rotate(360deg); } }
                            @keyframes pulse-glow { 0%,100% { box-shadow: 0 0 0 0 rgba(139,92,246,0.4); } 50% { box-shadow: 0 0 0 8px rgba(139,92,246,0); } }
                            @keyframes progress-fill { from { width: 0%; } }
                        </style>

                        {{-- Overall progress bar --}}
                        <div style="margin-bottom:16px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                                <span style="font-size:12px;font-weight:600;color:#a78bfa;">
                                    {{ $discoveryProgress['message'] ?? 'Starting discovery…' }}
                                </span>
                                @if($totalFound > 0)
                                    <span style="font-size:12px;font-weight:700;color:#f59e0b;">
                                        {{ number_format($totalFound) }} found
                                    </span>
                                @endif
                            </div>
                            {{-- Bar track --}}
                            <div style="height:6px;border-radius:3px;background:rgba(255,255,255,0.06);overflow:hidden;">
                                <div style="height:100%;border-radius:3px;background:linear-gradient(90deg,#7c3aed,#a78bfa,#c4b5fd);width:{{ $progressPct }}%;transition:width 0.6s ease;animation:progress-fill 0.4s ease-out;"></div>
                            </div>
                            <div style="text-align:right;margin-top:4px;">
                                <span style="font-size:10px;color:#6b7280;">{{ $doneCount }} / {{ $totalSteps }} stages</span>
                            </div>
                        </div>

                        {{-- Step indicators --}}
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            @foreach($allSteps as $i => $step)
                                @php
                                    $layerData = $layers[$step['key']] ?? null;
                                    $state = $layerData['state'] ?? 'pending';
                                    $isActive = ($state === 'running');
                                    $isDone = ($state === 'done');
                                    $isEmpty = ($state === 'empty');
                                    $isError = ($state === 'error');
                                    $isPending = (!$layerData || $state === 'pending');

                                    // Colors based on state
                                    if ($isActive) { $circleColor = '#7c3aed'; $borderColor = '#a78bfa'; $textColor = '#c4b5fd'; $bgColor = 'rgba(124,58,237,0.12)'; }
                                    elseif ($isDone) { $circleColor = '#059669'; $borderColor = '#34d399'; $textColor = '#34d399'; $bgColor = 'rgba(52,211,153,0.06)'; }
                                    elseif ($isError) { $circleColor = '#dc2626'; $borderColor = '#f87171'; $textColor = '#f87171'; $bgColor = 'rgba(248,113,113,0.06)'; }
                                    elseif ($isEmpty) { $circleColor = '#374151'; $borderColor = '#6b7280'; $textColor = '#6b7280'; $bgColor = 'rgba(255,255,255,0.02)'; }
                                    else { $circleColor = '#1f2937'; $borderColor = '#374151'; $textColor = '#4b5563'; $bgColor = 'transparent'; }
                                @endphp

                                <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;background:{{ $bgColor }};border:1px solid {{ $isActive ? 'rgba(139,92,246,0.2)' : 'rgba(255,255,255,0.03)' }};{{ $isActive ? 'animation:none;' : '' }}">
                                    {{-- Step circle --}}
                                    <div style="
                                        width:28px;height:28px;border-radius:50%;flex-shrink:0;
                                        display:flex;align-items:center;justify-content:center;
                                        background:{{ $circleColor }};border:2px solid {{ $borderColor }};
                                        font-size:12px;
                                        {{ $isActive ? 'animation:pulse-glow 1.5s ease-in-out infinite;' : '' }}
                                    ">
                                        @if($isDone)
                                            <span style="color:#fff;font-weight:700;">✓</span>
                                        @elseif($isActive)
                                            <div style="width:12px;height:12px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
                                        @elseif($isError)
                                            <span style="color:#fff;font-weight:700;">✕</span>
                                        @elseif($isEmpty)
                                            <span style="color:#6b7280;">—</span>
                                        @else
                                            <span style="color:#4b5563;font-weight:600;">{{ $i + 1 }}</span>
                                        @endif
                                    </div>

                                    {{-- Icon + Label + Detail --}}
                                    <div style="flex:1;min-width:0;">
                                        <div style="display:flex;align-items:center;gap:6px;">
                                            <span style="font-size:14px;">{{ $layerData['icon'] ?? $step['icon'] }}</span>
                                            <span style="font-size:12px;font-weight:600;color:{{ $textColor }};">{{ $layerData['label'] ?? $step['label'] }}</span>
                                        </div>
                                        @if(!empty($layerData['detail']))
                                            <div style="font-size:10px;color:#6b7280;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-left:20px;">
                                                {{ $layerData['detail'] }}
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Count badge --}}
                                    <div style="text-align:right;white-space:nowrap;">
                                        @if($isDone && ($layerData['count'] ?? 0) > 0)
                                            <span style="display:inline-block;padding:2px 8px;border-radius:10px;background:rgba(52,211,153,0.15);color:#34d399;font-size:11px;font-weight:700;">
                                                {{ number_format($layerData['count']) }}
                                            </span>
                                        @elseif($isActive)
                                            @if(($layerData['count'] ?? 0) > 0)
                                                <span style="display:inline-block;padding:2px 8px;border-radius:10px;background:rgba(139,92,246,0.15);color:#a78bfa;font-size:11px;font-weight:700;">
                                                    {{ number_format($layerData['count']) }}
                                                </span>
                                            @endif
                                        @elseif($isEmpty)
                                            <span style="font-size:10px;color:#6b7280;">skipped</span>
                                        @elseif($isError)
                                            <span style="font-size:10px;color:#f87171;">failed</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Organizing state --}}
                        @if(($discoveryProgress['status'] ?? '') === 'organizing')
                            <div style="display:flex;align-items:center;gap:8px;margin-top:10px;padding:10px 12px;border-radius:8px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.15);">
                                <div style="width:14px;height:14px;border:2px solid rgba(245,158,11,0.3);border-top-color:#f59e0b;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
                                <span style="font-size:12px;color:#f59e0b;font-weight:600;">{{ $discoveryProgress['message'] ?? 'Organizing pages into sets…' }}</span>
                            </div>
                        @endif

                    @elseif($discoveredCount > 0)
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <span style="font-size:28px;font-weight:900;color:#f59e0b;">{{ number_format($discoveredCount) }}</span>
                            <span style="font-size:12px;color:#6b7280;">pages discovered</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;font-size:11px;color:#6b7280;">
                            <span>→ {{ count($pageSetsData) }} sets</span>
                            <span>·</span>
                            <span>{{ count($autoPriorityData) + count($priorityPagesData) }} priority pages</span>
                            @if($lastDiscoveryAt)
                                <span>·</span>
                                <span>Discovered {{ $lastDiscoveryAt }}</span>
                            @endif
                        </div>
                    @else
                        <p style="font-size:12px;color:#6b7280;margin-bottom:12px;">
                            Discover all pages to enable set-based full-coverage scanning.
                        </p>
                    @endif

                    @if(!$isDiscovering)
                        @if($showRediscoverConfirm)
                            {{-- Inline confirmation dialog --}}
                            <div style="padding:12px 16px;border-radius:10px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);margin-top:8px;">
                                <div style="display:flex;align-items:flex-start;gap:10px;">
                                    <span style="font-size:18px;margin-top:2px;">⚠️</span>
                                    <div>
                                        <p style="font-size:12px;font-weight:600;color:#f87171;margin:0 0 4px;">All existing sets will be replaced</p>
                                        <p style="font-size:11px;color:#9ca3af;margin:0 0 10px;">Any edits you've made to page sets will be lost. This will re-scan the website and rebuild all sets from scratch.</p>
                                        <div style="display:flex;gap:8px;">
                                            <button wire:click="cancelRediscover" type="button"
                                                style="padding:5px 14px;border-radius:6px;background:rgba(255,255,255,0.06);color:#9ca3af;font-size:11px;font-weight:600;border:1px solid rgba(255,255,255,0.1);cursor:pointer;">
                                                Cancel
                                            </button>
                                            <button wire:click="discoverAllPages" type="button"
                                                style="padding:5px 14px;border-radius:6px;background:rgba(239,68,68,0.15);color:#f87171;font-size:11px;font-weight:600;border:1px solid rgba(239,68,68,0.25);cursor:pointer;">
                                                Yes, Re-discover
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @else
                            <button wire:click="{{ $discoveredCount > 0 ? 'confirmRediscover' : 'discoverAllPages' }}" type="button"
                                style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;background:rgba(139,92,246,0.15);color:#a78bfa;font-size:12px;font-weight:600;border:1px solid rgba(139,92,246,0.2);cursor:pointer;">
                                🔍 {{ $discoveredCount > 0 ? 'Re-discover Pages' : 'Discover All Pages' }}
                            </button>
                        @endif
                    @endif
                </div>

                {{-- Priority Pages --}}
                <div class="scanner-card" style="overflow:hidden;display:flex;flex-direction:column;">
                    <div class="scanner-card-header" style="margin-bottom:4px;">
                        <span class="icon">⭐</span>
                        <h3>Priority Pages</h3>
                        <span class="subtitle">(scanned every time, max 30)</span>
                    </div>
                    @if(!empty($autoPriorityData))
                        <div x-data="{ showAllPriority: false }" style="margin-bottom:8px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <span style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.08em;">Auto-detected ({{ count($autoPriorityData) }}):</span>
                                @if(count($autoPriorityData) > 5)
                                    <button @click="showAllPriority = !showAllPriority" type="button"
                                        style="font-size:10px;color:#a78bfa;background:none;border:none;cursor:pointer;font-weight:600;padding:0;">
                                        <span x-text="showAllPriority ? '▾ Show less' : '▸ See all {{ count($autoPriorityData) }}'"></span>
                                    </button>
                                @endif
                            </div>
                            {{-- Collapsed: show first 5 --}}
                            <div x-show="!showAllPriority" style="margin-top:4px;">
                                @foreach(array_slice($autoPriorityData, 0, 5) as $url)
                                    <div style="font-size:10px;color:#9ca3af;font-family:monospace;padding:1px 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">⭐ {{ $url }}</div>
                                @endforeach
                                @if(count($autoPriorityData) > 5)
                                    <div style="font-size:10px;color:#4b5563;">+{{ count($autoPriorityData) - 5 }} more</div>
                                @endif
                            </div>
                            {{-- Expanded: show all in scrollable list --}}
                            <div x-show="showAllPriority" x-transition.duration.200ms style="margin-top:4px;max-height:300px;overflow-y:auto;border:1px solid rgba(255,255,255,0.04);border-radius:8px;padding:6px 8px;background:rgba(0,0,0,0.1);">
                                @foreach($autoPriorityData as $url)
                                    <div style="font-size:10px;color:#9ca3af;font-family:monospace;padding:2px 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">⭐ {{ $url }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <textarea wire:model="priorityPagesInput" rows="3" placeholder="https://example.com/checkout&#10;https://example.com/pricing&#10;/important-page"
                        style="width:100%;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:8px 10px;color:#e0e0e0;font-size:11px;font-family:monospace;resize:vertical;outline:none;"
                    ></textarea>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px;">
                        <span style="font-size:10px;color:#6b7280;">Your custom priority pages (one per line)</span>
                        <button wire:click="savePriorityPages" type="button"
                            style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;background:rgba(245,158,11,0.15);color:#f59e0b;font-size:10px;font-weight:600;border:1px solid rgba(245,158,11,0.2);cursor:pointer;">
                            ✓ Save
                        </button>
                    </div>
                </div>
            </div>

            {{-- ═══ Custom Pages & Email Report (side by side) ═══ --}}
            <div class="script-scanner-grid-2">
                {{-- Custom Pages --}}
                <div class="scanner-card">
                    <div class="scanner-card-header">
                        <span class="icon">📄</span>
                        <h3>Custom Pages</h3>
                        <span class="subtitle">(max 500, one per line)</span>
                    </div>
                    <textarea wire:model="manualPagesInput" rows="6" placeholder="/contact&#10;/blog&#10;/pricing&#10;/checkout&#10;https://example.com/custom-page"
                        style="width:100%;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:10px 12px;color:#e0e0e0;font-size:12px;font-family:monospace;resize:vertical;outline:none;"
                    ></textarea>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px;">
                        <span style="font-size:11px;color:#6b7280;">
                            @if(trim($manualPagesInput))
                                {{ count(array_filter(explode("\n", $manualPagesInput))) }} pages entered
                            @else
                                Leave empty for auto-discovery
                            @endif
                        </span>
                        <button wire:click="saveManualPages" type="button"
                            style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:6px;background:rgba(139,92,246,0.15);color:#a78bfa;font-size:11px;font-weight:600;border:1px solid rgba(139,92,246,0.2);cursor:pointer;">
                            ✓ Save Pages
                        </button>
                    </div>
                </div>

                {{-- Email Report --}}
                <div class="scanner-card">
                    <div class="scanner-card-header">
                        <span class="icon">📧</span>
                        <h3>Email Report</h3>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                        <label style="position:relative;display:inline-flex;align-items:center;cursor:pointer;">
                            <input type="checkbox" wire:model.live="reportEnabled" style="position:absolute;opacity:0;width:0;height:0;">
                            <div style="width:36px;height:20px;border-radius:10px;transition:all 0.2s;{{ $reportEnabled ? 'background:#f59e0b;' : 'background:rgba(255,255,255,0.1);' }}">
                                <div style="width:16px;height:16px;border-radius:50%;background:#fff;margin-top:2px;transition:all 0.2s;{{ $reportEnabled ? 'margin-left:18px;' : 'margin-left:2px;' }}"></div>
                            </div>
                        </label>
                        <span style="font-size:12px;color:{{ $reportEnabled ? '#f59e0b' : '#6b7280' }};font-weight:500;">
                            {{ $reportEnabled ? 'Reports enabled' : 'Reports disabled' }}
                        </span>
                    </div>
                    @if($reportEnabled)
                        <input type="email" wire:model="reportEmail" placeholder="you@example.com"
                            style="width:100%;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:8px 12px;color:#e0e0e0;font-size:13px;outline:none;margin-bottom:8px;">
                        <p style="font-size:11px;color:#6b7280;margin:0 0 12px;">
                            Receive an HTML scan report after each scan completes.
                        </p>
                    @endif
                    <button wire:click="saveReportSettings" type="button"
                        style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:6px;background:rgba(245,158,11,0.15);color:#f59e0b;font-size:11px;font-weight:600;border:1px solid rgba(245,158,11,0.2);cursor:pointer;">
                        ✓ Save Report Settings
                    </button>
                </div>
            </div>

            {{-- ═══ Page Sets Overview ═══ --}}
            @if(!empty($pageSetsData))
                <div x-data="{ setsOpen: false }" class="scanner-accordion" style="margin-top:16px;">
                    <button @click="setsOpen = !setsOpen" type="button"
                        class="scanner-accordion-trigger" style="color:#fff;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span style="font-size:16px;">📦</span>
                            <div>
                                <span style="font-size:13px;font-weight:700;">Page Sets</span>
                                <span style="font-size:11px;color:#6b7280;margin-left:8px;">
                                    Cycle {{ $currentCycle }}: {{ collect($pageSetsData)->where('scanned', true)->count() }}/{{ count($pageSetsData) }} sets scanned
                                </span>
                            </div>
                        </div>
                        <span x-text="setsOpen ? '▾' : '▸'" style="font-size:12px;color:#6b7280;"></span>
                    </button>

                    {{-- Cycle progress bar --}}
                    @php
                        $scannedSets = collect($pageSetsData)->where('scanned', true)->count();
                        $totalSets = count($pageSetsData);
                        $cycleProgress = $totalSets > 0 ? round(($scannedSets / $totalSets) * 100) : 0;
                    @endphp
                    <div style="height:3px;background:rgba(255,255,255,0.05);">
                        <div style="height:100%;background:linear-gradient(90deg,#a78bfa,#f59e0b);border-radius:0 2px 2px 0;transition:width 0.3s ease;width:{{ $cycleProgress }}%;"></div>
                    </div>

                    {{-- Sets grid --}}
                    <div x-show="setsOpen" x-collapse>
                        <div style="padding:12px 16px;display:flex;flex-wrap:wrap;gap:6px;">
                            @foreach($pageSetsData as $set)
                                <div wire:click="viewSet({{ $set['index'] }})" style="padding:6px 10px;border-radius:6px;font-size:10px;font-weight:600;min-width:60px;text-align:center;cursor:pointer;transition:all 0.15s;
                                    {{ $viewingSetIndex === $set['index']
                                        ? 'background:rgba(139,92,246,0.15);color:#a78bfa;border:1px solid rgba(139,92,246,0.3);box-shadow:0 0 0 2px rgba(139,92,246,0.15);'
                                        : ($set['scanned']
                                            ? 'background:rgba(16,185,129,0.1);color:#34d399;border:1px solid rgba(16,185,129,0.2);'
                                            : ($set['index'] == $currentSetIndex
                                                ? 'background:rgba(245,158,11,0.1);color:#fbbf24;border:1px solid rgba(245,158,11,0.2);'
                                                : 'background:rgba(255,255,255,0.03);color:#6b7280;border:1px solid rgba(255,255,255,0.06);')) }}"
                                    title="Click to view pages · {{ $set['scanned'] ? 'Scanned ' . $set['scanned_at'] : ($set['index'] == $currentSetIndex ? 'Next to scan' : 'Pending') }}">
                                    {{ $set['scanned'] ? '✅' : ($set['index'] == $currentSetIndex ? '🔄' : '⏳') }}
                                    Set {{ $set['index'] + 1 }}
                                    <div style="font-size:9px;opacity:0.7;">{{ $set['page_count'] }} pg</div>
                                </div>
                            @endforeach
                        </div>
                        <div style="padding:8px 16px;border-top:1px solid rgba(255,255,255,0.04);font-size:10px;color:#4b5563;display:flex;gap:16px;">
                            <span>✅ = scanned</span>
                            <span>🔄 = next</span>
                            <span>⏳ = pending</span>
                            <span style="margin-left:auto;">Click a set to view/edit pages</span>
                        </div>

                        {{-- Set detail panel (visible when a set is selected) --}}
                        @if($viewingSetIndex !== null)
                            <div style="padding:16px;border-top:1px solid rgba(255,255,255,0.06);background:rgba(255,255,255,0.015);">
                                {{-- Header --}}
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <span style="font-size:14px;">📋</span>
                                        <span style="font-size:13px;font-weight:700;color:#fff;">Set {{ $viewingSetIndex + 1 }}</span>
                                        <span style="font-size:11px;color:#6b7280;">· {{ count($viewingSetPages) }} pages</span>
                                    </div>
                                    <div style="display:flex;gap:6px;">
                                        <button wire:click="toggleEditSet" type="button"
                                            style="padding:4px 10px;border-radius:6px;font-size:10px;font-weight:600;cursor:pointer;
                                            {{ $isEditingSet
                                                ? 'background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.2);'
                                                : 'background:rgba(139,92,246,0.1);color:#a78bfa;border:1px solid rgba(139,92,246,0.15);' }}">
                                            {{ $isEditingSet ? '👁️ View' : '✏️ Edit' }}
                                        </button>
                                        <button wire:click="closeSet" type="button"
                                            style="padding:4px 10px;border-radius:6px;background:rgba(255,255,255,0.05);color:#6b7280;font-size:10px;font-weight:600;border:1px solid rgba(255,255,255,0.08);cursor:pointer;">
                                            ✕ Close
                                        </button>
                                    </div>
                                </div>

                                @if($isEditingSet)
                                    {{-- Edit mode --}}
                                    <textarea wire:model="editingSetPages" rows="12"
                                        style="width:100%;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:10px 12px;color:#e0e0e0;font-size:11px;font-family:monospace;resize:vertical;outline:none;line-height:1.5;"
                                        placeholder="One URL per line..."></textarea>
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px;">
                                        <span style="font-size:10px;color:#6b7280;">One URL per line · Changes will update the set</span>
                                        <button wire:click="saveSetPages" type="button"
                                            style="display:inline-flex;align-items:center;gap:4px;padding:5px 14px;border-radius:6px;background:rgba(52,211,153,0.15);color:#34d399;font-size:11px;font-weight:600;border:1px solid rgba(52,211,153,0.2);cursor:pointer;">
                                            💾 Save Changes
                                        </button>
                                    </div>
                                @else
                                    {{-- View mode: scrollable list --}}
                                    <div style="max-height:300px;overflow-y:auto;border:1px solid rgba(255,255,255,0.04);border-radius:8px;background:rgba(0,0,0,0.15);">
                                        @forelse($viewingSetPages as $i => $url)
                                            <div style="display:flex;align-items:center;gap:8px;padding:5px 12px;font-size:11px;font-family:monospace;color:#d1d5db;border-bottom:1px solid rgba(255,255,255,0.03);{{ $i % 2 === 0 ? 'background:rgba(255,255,255,0.01);' : '' }}">
                                                <span style="font-size:9px;color:#4b5563;min-width:24px;text-align:right;">{{ $i + 1 }}</span>
                                                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $url }}">{{ $url }}</span>
                                            </div>
                                        @empty
                                            <div style="padding:20px;text-align:center;font-size:12px;color:#6b7280;">No pages in this set</div>
                                        @endforelse
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- ═══ Scheduler Settings Modal ═══ --}}
            <x-filament::modal id="scheduler-settings-modal" width="3xl" slide-over>
                <x-slot name="heading">
                    Scheduler Settings
                </x-slot>

                {{ $this->form }}
            </x-filament::modal>
        @endif

        {{-- ═══ Live Scan Progress ═══ --}}
        @if($isScanning)
            <div wire:poll.500ms="scanNextPage">
                <div style="border-radius:12px;border:1px solid rgba(245,158,11,0.2);background:rgba(245,158,11,0.03);overflow:hidden;">
                    {{-- Header --}}
                    <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(245,158,11,0.08);">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div class="float-anim" style="font-size:20px;">🔍</div>
                            <div>
                                <p style="font-size:14px;font-weight:700;color:#fff;">Scanning {{ $scannedDomainName }}</p>
                                <p style="font-size:11px;color:#6b7280;">Page {{ $scanPageIndex }} of {{ count($pagesToScan) }}</p>
                            </div>
                        </div>
                        <span style="font-size:24px;font-weight:900;color:#f59e0b;">{{ $scanProgress }}%</span>
                    </div>

                    {{-- Progress bar --}}
                    <div style="height:4px;background:rgba(255,255,255,0.05);">
                        <div style="height:100%;background:linear-gradient(90deg,#f59e0b,#ea580c);border-radius:0 2px 2px 0;transition:width 0.3s ease;width:{{ $scanProgress }}%;"></div>
                    </div>

                    {{-- Current page --}}
                    <div style="padding:12px 20px;border-bottom:1px solid rgba(255,255,255,0.03);">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="width:6px;height:6px;border-radius:50%;background:#f59e0b;animation:float 1s ease-in-out infinite;"></div>
                            <span style="font-size:12px;color:#9ca3af;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $scanCurrentPage }}</span>
                        </div>
                    </div>

                    {{-- Live scan log --}}
                    @if(!empty($scanLog))
                        <div style="max-height:220px;overflow-y:auto;padding:8px 12px;">
                            @foreach(array_reverse($scanLog) as $entry)
                                <div style="display:flex;align-items:center;gap:8px;padding:4px 8px;border-radius:6px;margin-bottom:2px;font-size:11px;
                                    {{ $entry['status'] === 'success' ? 'color:#34d399;' : 'color:#f87171;' }}">
                                    <span>{{ $entry['status'] === 'success' ? '✓' : '✗' }}</span>
                                    <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#9ca3af;font-family:monospace;">
                                        {{ str_replace('https://' . $scannedDomainName, '', $entry['url']) ?: '/' }}
                                    </span>
                                    <span style="flex-shrink:0;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;
                                        {{ $entry['scripts'] > 0 ? 'background:rgba(245,158,11,0.1);color:#fbbf24;' : 'background:rgba(255,255,255,0.03);color:#4b5563;' }}">
                                        {{ $entry['scripts'] }} scripts
                                    </span>
                                    <span style="flex-shrink:0;color:#374151;font-size:10px;">{{ $entry['time'] }}s</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- ═══ Error ═══ --}}
        @if($scanError)
            <div style="display:flex;align-items:flex-start;gap:12px;padding:16px;border-radius:12px;background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.15);">
                <span style="color:#f87171;font-size:18px;flex-shrink:0;">⚠️</span>
                <div>
                    <p style="font-size:14px;font-weight:600;color:#f87171;">{{ __('ycookies.script_scanner.scan_failed') }}</p>
                    <p style="font-size:12px;color:rgba(248,113,113,0.6);margin-top:2px;">{{ $scanError }}</p>
                </div>
            </div>
        @endif

        {{-- ═══ Results ═══ --}}
        @if($hasResults)
            <div class="rounded-xl border border-white/10 shadow-lg mt-4 mb-6 overflow-hidden" style="background:#18181b;">
                {{-- Header & Meta --}}
                <header class="px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-document-magnifying-glass class="w-5 h-5 text-gray-400" />
                            <h2 class="text-base font-semibold leading-6 text-white">
                                Scan Results
                            </h2>
                        </div>
                        <p class="text-sm text-gray-400 ml-7">
                            Scanned <span class="font-medium text-gray-300">{{ $lastScanAt ? $lastScanAt : 'just now' }}</span>
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        @if($viewingScanSource)
                            <span class="px-2.5 py-1 rounded-md text-[11px] font-bold uppercase tracking-wider
                                {{ $viewingScanSource === 'manual'
                                    ? 'bg-purple-500/10 text-purple-400 border border-purple-500/20'
                                    : ($viewingScanSource === 'scheduled'
                                        ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20'
                                        : ($viewingScanSource === 'baseline'
                                            ? 'bg-cyan-500/10 text-cyan-400 border border-cyan-500/20'
                                            : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20')) }}">
                                {{ match($viewingScanSource) {
                                    'scheduled' => '⏰ Scheduled',
                                    'manual' => '✋ Manual',
                                    'baseline' => '👁️ Baseline',
                                    default => '🤖 Auto',
                                } }}
                            </span>
                        @endif
                    </div>
                </header>

                {{-- Stage pills --}}
                <div class="px-6 py-3 border-y border-white/5 bg-white/[0.02] overflow-x-auto">
                    <div class="flex items-center justify-start gap-3 min-w-max">
                        @if(isset($scanStages['discovery']))
                            <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold
                                {{ $scanStages['discovery']['status'] === 'success' ? 'bg-purple-500/10 text-purple-400 border border-purple-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' }}">
                                <span class="text-sm">{{ $scanStages['discovery']['status'] === 'success' ? '✓' : '✗' }}</span>
                                📄 {{ count($discoveredPages) }} Pages
                                @if(isset($scanStages['discovery']['source']))
                                    <span class="opacity-60 font-normal">({{ $scanStages['discovery']['source'] }})</span>
                                @endif
                            </div>
                        @endif
                        @if(isset($scanStages['http']))
                            <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold
                                {{ $scanStages['http']['status'] === 'success' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' }}">
                                <span class="text-sm">{{ $scanStages['http']['status'] === 'success' ? '✓' : '✗' }}</span>
                                🌐 HTTP · {{ $scanStages['http']['count'] }}
                            </div>
                        @endif
                        @if(isset($scanStages['deep']))
                            <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold
                                {{ $scanStages['deep']['status'] === 'success' ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20' : 'bg-amber-500/10 text-amber-400 border border-amber-500/20' }}">
                                <span class="text-sm">{{ $scanStages['deep']['status'] === 'success' ? '✓' : '⚠' }}</span>
                                🖥️ Chrome · {{ $scanStages['deep']['count'] }}
                            </div>
                        @endif
                    </div>
                </div>
    
                {{-- Pages scanned log --}}
                @if(!empty($scanLog))
                    <div x-data="{ open: false }" class="border-b border-base-200 dark:border-white/5">
                        <button @click="open = !open" type="button" class="w-full flex items-center justify-between p-4 bg-transparent hover:bg-white/5 transition-colors cursor-pointer border-none text-left focus:outline-none">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="font-size:14px;">📋</span>
                                <span style="font-size:12px;font-weight:600;color:#e5e7eb;">{{ __('ycookies.script_scanner.scan_log') }}</span>
                                <span style="font-size:11px;color:#4b5563;">{{ count($scanLog) }} {{ __('ycookies.script_scanner.pages_unit') }}</span>
                            </div>
                            <span x-text="open ? '▾' : '▸'" style="font-size:12px;color:#9ca3af;"></span>
                        </button>
                        <div x-show="open" x-collapse>
                            <div style="padding:12px 16px;background:rgba(0,0,0,0.2);border-top:1px solid rgba(255,255,255,0.02);">
                                @foreach($scanLog as $entry)
                                    <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:6px;font-size:11px;">
                                        <span style="{{ $entry['status'] === 'success' ? 'color:#34d399;' : 'color:#f87171;' }}">
                                            {{ $entry['status'] === 'success' ? '✓' : '✗' }}
                                        </span>
                                        <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#9ca3af;font-family:monospace;">
                                            {{ $entry['url'] }}
                                        </span>
                                        <span style="flex-shrink:0;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;
                                            {{ $entry['scripts'] > 0 ? 'background:rgba(245,158,11,0.1);color:#fbbf24;' : 'background:rgba(255,255,255,0.03);color:#4b5563;' }}">
                                            {{ $entry['scripts'] }} scripts
                                        </span>
                                        <span style="flex-shrink:0;color:#374151;font-size:10px;min-width:32px;text-align:right;">{{ $entry['time'] }}s</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
    
                {{-- Baseline / Scanned Stats --}}
                @if($viewingScanSource === 'baseline')
                    <div class="p-6 bg-black/20">
                        <div class="flex flex-col gap-4">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-globe-alt class="w-5 h-5 text-cyan-500" />
                                <h3 class="text-lg font-black text-white m-0">👁️ External Resources</h3>
                            </div>
                            <p class="text-sm text-gray-400">Unfiltered baseline view showing what the site loads by default.</p>
                            <div class="flex flex-col divide-y divide-white/5">
                                @forelse($unknownScripts as $script)
                                    <div class="py-3 flex items-center justify-between gap-4">
                                        <div class="flex items-center gap-3 min-w-0 flex-1">
                                            <div class="w-8 h-8 rounded-lg bg-cyan-500/20 text-cyan-500 flex items-center justify-center shrink-0">
                                                <x-heroicon-o-globe-alt class="w-4 h-4" />
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-sm font-medium text-white truncate m-0">{{ $script['host'] }}</p>
                                                <p class="text-xs text-gray-500 font-mono m-0">{{ $script['url'] }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="py-6 text-center text-gray-400">
                                        No external resources were found.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @else
                    {{-- Stats Grid — hidden when unblocked resources detected --}}
                    @if(count($unblockedScripts) > 0)
                        <div class="grid grid-cols-2 gap-0 border-b border-white/5">
                            <div class="p-6 text-center border-r border-white/5 border-t-4 border-t-danger-500 bg-danger-500/5">
                                <p class="text-3xl font-black text-danger-400">{{ count($unblockedScripts) }}</p>
                                <p class="text-xs font-bold text-danger-500/70 mt-2 uppercase tracking-widest">Unblocked</p>
                            </div>
                            <div class="p-6 text-center border-t-4 border-t-gray-500">
                                <p class="text-3xl font-black text-gray-100">{{ count($rawScripts) }}</p>
                                <p class="text-xs font-bold text-gray-500 mt-2 uppercase tracking-widest">{{ __('ycookies.script_scanner.stat_total') }}</p>
                            </div>
                        </div>
                    @else
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-0 border-b border-white/5">
                            <div class="p-6 text-center border-r border-b sm:border-b-0 border-white/5 border-t-4 border-t-gray-500">
                                <p class="text-3xl font-black text-gray-100">{{ count($rawScripts) }}</p>
                                <p class="text-xs font-bold text-gray-500 mt-2 uppercase tracking-widest">{{ __('ycookies.script_scanner.stat_total') }}</p>
                            </div>
                            <div class="p-6 text-center border-r border-b sm:border-b-0 border-white/5 border-t-4 border-t-emerald-500 bg-emerald-500/5">
                                <p class="text-3xl font-black text-emerald-400">{{ count($protectedScripts) }}</p>
                                <p class="text-xs font-bold text-emerald-500/70 mt-2 uppercase tracking-widest">{{ __('ycookies.script_scanner.stat_protected') }}</p>
                            </div>
                            <div class="p-6 text-center border-r border-b sm:border-b-0 border-white/5 border-t-4 border-t-amber-500 bg-amber-500/5">
                                <p class="text-3xl font-black text-amber-400">{{ count($suggestedScripts) }}</p>
                                <p class="text-xs font-bold text-amber-500/70 mt-2 uppercase tracking-widest">{{ __('ycookies.script_scanner.stat_suggested') }}</p>
                            </div>
                            <div class="p-6 text-center border-t-4 border-t-red-500 bg-red-500/5">
                                <p class="text-3xl font-black text-red-500">{{ count($unknownScripts) }}</p>
                                <p class="text-xs font-bold text-red-500/70 mt-2 uppercase tracking-widest">{{ __('ycookies.script_scanner.stat_unknown') }}</p>
                            </div>
                        </div>
                    @endif

                {{-- 🚨 Unblocked Resources Warning / Compliance Check 🚨 --}}
                <div class="p-6 bg-black/20">
                    @if(count($unblockedScripts) > 0)
                        <div class="border border-danger-500/30 bg-danger-500/10 rounded-lg p-5">
                            <div class="flex items-center gap-2 mb-2">
                                <x-heroicon-s-exclamation-triangle class="w-6 h-6 text-danger-500 shrink-0" />
                                <h3 class="text-lg font-black text-danger-500 p-0 m-0 leading-none">🚨 Unblocked Resources Detected</h3>
                            </div>
                            <p class="text-danger-400/90 font-medium text-sm mb-4">
                                Warning: The following external resources were loaded successfully before any consent was granted. If these are tracking or marketing scripts, this violates GDPR.
                            </p>
                            
                            <div class="flex flex-col gap-2 mt-2">
                                @foreach($unblockedScripts as $url)
                                    <div class="py-2 px-3 bg-black/40 rounded-md border border-danger-500/20 flex items-center gap-2 overflow-hidden shadow-sm">
                                        <span class="text-sm font-mono text-gray-300 truncate" title="{{ $url }}">{{ $url }}</span>
                                    </div>
                                @endforeach
                            </div>
                            
                            <div class="mt-4 text-xs text-danger-400 opacity-80">
                                <strong>Fix this:</strong> Ensure "Automatic Blocking" is enabled for this domain, or manually block these script URLs if using manual script tagging.
                            </div>
                        </div>
                    @elseif(count($rawScripts) > 0)
                        <div class="flex items-center gap-4 border border-success-500/20 bg-success-500/5 rounded-lg p-4">
                            <div class="w-10 h-10 rounded-full bg-success-500/20 text-success-500 flex items-center justify-center shrink-0">
                                <x-heroicon-m-check-badge class="w-6 h-6" />
                            </div>
                            <div>
                                <h3 class="text-base font-black text-success-500 m-0">100% GDPR Compliant (Before Consent)</h3>
                                <p class="text-sm text-gray-400 mt-0.5 m-0">All {{ count($rawScripts) }} detected external resources were successfully blocked before consent. Automatic Blocking is working perfectly.</p>
                            </div>
                        </div>
                    @endif
                </div>            @if(count($protectedScripts) > 0)
                <div class="px-4 py-4 border-t border-white/5">
                    <div class="flex flex-col gap-1 mb-3">
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-success-500 font-bold uppercase tracking-widest flex items-center gap-1.5">🛡️ Known & Installed</span>
                            <span class="text-[10px] text-success-500/80 bg-success-500/10 px-1.5 py-0.5 rounded font-bold">{{ count($protectedScripts) }}</span>
                        </div>
                        <p class="text-[11px] text-gray-400">You have actively installed blockers for these recognized resources.</p>
                    </div>
                    
                    <div class="flex flex-col gap-1.5">
                        @foreach($protectedScripts as $script)
                            <div class="py-1.5 px-3 flex items-center justify-between gap-4 group rounded bg-white/[0.02] border border-white/5">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-6 h-6 rounded bg-success-500/10 text-success-500 flex items-center justify-center shrink-0">
                                        <x-heroicon-o-check class="w-4 h-4" />
                                    </div>
                                    <div class="min-w-0 flex items-center gap-2">
                                        <p class="text-sm font-bold text-gray-200 truncate">{{ $script['blocker_name'] }}</p>
                                        <p class="text-xs text-gray-500 font-mono truncate hidden sm:block">{{ $script['host'] }}</p>
                                    </div>
                                </div>
                                <span class="px-1.5 py-0.5 text-[10px] font-bold rounded bg-success-500/10 text-success-500 uppercase">Active</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ⚠️ Suggested --}}
            @if(count($suggestedScripts) > 0)
                <div class="px-4 py-4 border-t border-white/5">
                    <div class="flex flex-col gap-1 mb-3">
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-warning-500 font-bold uppercase tracking-widest flex items-center gap-1.5">💡 Known (Not Installed)</span>
                            <span class="text-[10px] text-warning-500/80 bg-warning-500/10 px-1.5 py-0.5 rounded font-bold">{{ count($suggestedScripts) }}</span>
                        </div>
                        <p class="text-[11px] text-gray-400">We have blockers available for these resources, but you have not installed them yet.</p>
                    </div>
                    
                    <div class="flex flex-col gap-1.5">
                        @foreach($suggestedScripts as $script)
                            <div class="py-2 px-3 flex flex-col sm:flex-row sm:items-center justify-between gap-4 group rounded bg-white/[0.02] border border-white/5">
                                <div class="flex items-center gap-3 min-w-0 flex-1">
                                    <div class="w-6 h-6 rounded bg-warning-500/10 text-warning-500 flex items-center justify-center shrink-0">
                                        <x-heroicon-o-puzzle-piece class="w-4 h-4" />
                                    </div>
                                    <div class="min-w-0 flex flex-wrap items-center gap-x-3 text-sm">
                                        <p class="font-bold text-gray-200">{{ $script['template_name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $script['provider'] }}</p>
                                        <p class="text-xs text-gray-600 font-mono hidden md:block">{{ $script['host'] }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span class="px-1.5 py-0.5 text-[10px] font-bold rounded border uppercase {{ match($script['template_type']) { 'service' => 'bg-blue-500/10 border-blue-500/20 text-blue-400', 'script_blocker' => 'bg-purple-500/10 border-purple-500/20 text-purple-400', 'content_blocker' => 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400', default => 'bg-gray-500/10 border-gray-500/20 text-gray-400' } }}">
                                        {{ str_replace('_', ' ', $script['template_type']) }}
                                    </span>
                                    @if($selectedDomainId !== 'custom')
                                        <x-filament::button
                                            wire:click="installBlocker('{{ $script['template_key'] }}')"
                                            wire:loading.attr="disabled"
                                            color="warning"
                                            size="xs"
                                        >
                                            Install
                                        </x-filament::button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ❌ Unknown --}}
            @if(count($unknownScripts) > 0)
                <div class="px-4 py-4 border-t border-white/5">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex flex-col gap-1 pr-4">
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-danger-500 font-bold uppercase tracking-widest flex items-center gap-1.5">❓ Unknown Resources</span>
                                <span class="text-[10px] text-danger-500/80 bg-danger-500/10 px-1.5 py-0.5 rounded font-bold">{{ count($unknownScripts) }}</span>
                            </div>
                            <p class="text-[11px] text-gray-400">We do not have pre-built blockers for these resources. You can build your own or report them to us.</p>
                        </div>
                        @if($selectedDomainId !== 'custom')
                            <x-filament::button
                                wire:click="sendReport" 
                                wire:loading.attr="disabled"
                                color="danger"
                                icon="heroicon-o-envelope"
                                size="xs"
                            >
                                Report All
                            </x-filament::button>
                        @endif
                    </div>
                    
                    <div class="flex flex-col gap-1.5">
                        @foreach($unknownScripts as $i => $script)
                            <div x-data="{ open: false }" class="group rounded bg-white/[0.02] border border-white/5">
                                <div class="py-1.5 px-3 flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3 min-w-0 flex-1">
                                        <div class="w-6 h-6 rounded bg-danger-500/10 text-danger-500 flex items-center justify-center shrink-0">
                                            <span class="text-[10px] font-black">?</span>
                                        </div>
                                        <div class="min-w-0 flex items-center gap-2">
                                            <p class="text-sm font-medium text-gray-200 truncate">{{ $script['host'] }}</p>
                                            <p class="text-xs text-gray-600 font-mono truncate hidden sm:block">{{ $script['url'] }}</p>
                                        </div>
                                    </div>
                                    @if($selectedDomainId !== 'custom')
                                        <x-filament::button
                                            @click="open = !open"
                                            color="gray"
                                            size="xs"
                                        >
                                            <span x-text="open ? 'Cancel' : 'Custom'"></span>
                                        </x-filament::button>
                                    @endif
                                </div>
                                <div x-show="open" x-collapse x-cloak>
                                    <div x-data="{ n: '{{ addslashes($script['host']) }}', p: 'Blocks scripts from {{ addslashes($script['host']) }}' }"
                                         class="p-4 rounded-b bg-black/40 border-t border-danger-500/10 flex flex-wrap items-end gap-3 mt-1">
                                        <div class="flex-1 min-w-[160px]">
                                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Name</label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input type="text" x-model="n" />
                                            </x-filament::input.wrapper>
                                        </div>
                                        <div class="flex-1 min-w-[160px]">
                                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Purpose</label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input type="text" x-model="p" />
                                            </x-filament::input.wrapper>
                                        </div>
                                        <x-filament::button
                                            @click="$wire.createCustomBlocker('{{ addslashes($script['url']) }}', n, p); open = false;"
                                            color="primary"
                                        >
                                            Create
                                        </x-filament::button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                
                    @if($selectedDomainId !== 'custom' && $this->getMailtoUrl())
                        <div class="mt-4 pt-3 border-t border-white/5 flex justify-center">
                            <a href="{{ $this->getMailtoUrl() }}" target="_blank" class="text-xs text-gray-500 hover:text-gray-300 transition flex items-center gap-1.5">
                                <x-heroicon-o-envelope class="w-3.5 h-3.5" /> Send payload manually via email client
                            </a>
                        </div>
                    @endif
                </div>
            @endif

            {{-- All Clear --}}
            @if(count($protectedScripts) > 0 && count($suggestedScripts) === 0 && count($unknownScripts) === 0)
                <div class="p-6 border-t border-white/5 text-center bg-success-500/5">
                    <div class="w-14 h-14 mx-auto rounded-2xl bg-success-500/20 text-success-500 flex items-center justify-center mb-4">
                        <x-heroicon-o-shield-check class="w-8 h-8" />
                    </div>
                    <h3 class="text-lg font-black text-success-400">All Protected</h3>
                    <p class="text-sm text-gray-400 mt-1">Every script on <strong class="text-white">{{ $scannedDomainName }}</strong> is blocked.</p>
                </div>
            @endif

            @if(count($rawScripts) === 0)
                <div class="p-6 border-t border-white/5 text-center text-gray-500 dark:text-gray-400">
                    <div class="flex justify-center mb-3">
                        <x-heroicon-o-magnifying-glass class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                    </div>
                    <h3 class="text-lg font-black text-white">No External Scripts</h3>
                    <p class="text-sm mt-1 text-gray-400">{{ $scannedDomainName }} has no external scripts or iframes.</p>
                </div>
            @endif
            @endif
            </div>

        @else
            {{-- ═══ Empty State ═══ --}}
            <x-filament::section class="text-center mt-8">
                <div class="w-20 h-20 mx-auto rounded-3xl bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-500 flex items-center justify-center mb-5 ring-1 ring-primary-500/30">
                    <x-heroicon-o-magnifying-glass class="w-10 h-10" />
                </div>
                <h3 class="text-lg font-black text-gray-950 dark:text-white mb-2">{{ __('ycookies.script_scanner.empty_title') }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 max-w-md mx-auto mb-6 leading-relaxed">
                    {{ __('ycookies.script_scanner.empty_lead') }}
                </p>
                <div class="flex items-center justify-center gap-2 text-xs">
                    <x-filament::badge color="gray">{{ __('ycookies.script_scanner.empty_tag_http') }}</x-filament::badge>
                    <span class="text-gray-400">+</span>
                    <x-filament::badge color="gray">{{ __('ycookies.script_scanner.empty_tag_chrome') }}</x-filament::badge>
                </div>
            </x-filament::section>
        @endif
        </div>

    {{-- ═══ Scan History ═══ --}}
    @if(!empty($scanHistory))
        <div class="mt-2">
            <x-filament::section
                icon="heroicon-o-clock"
                :heading="__('ycookies.script_scanner.scan_history')"
                :description="__('ycookies.script_scanner.scans_recorded', ['count' => count($scanHistory)])"
            >
                {{-- Bulk selection toolbar --}}
                <div class="flex flex-wrap items-center gap-4 mb-4 pb-4 border-b border-gray-200 dark:border-white/10">
                    <label class="flex items-center gap-2 cursor-pointer text-sm font-medium text-gray-700 dark:text-gray-300 select-none">
                        <x-filament::input.checkbox
                            wire:click.prevent="toggleSelectAllHistoryScans"
                            :checked="$this->allHistoryScansSelected()"
                        />
                        <span>{{ __('ycookies.script_scanner.select_all') }}</span>
                    </label>
                    {{ $this->deleteSelectedHistoryScansAction }}
                </div>

                <div class="flex flex-col gap-3">
                    @foreach($this->scanHistory as $scan)
                        @php
                            $isActive = $viewingScanId === $scan['id'];
                            $scannedAt = \Carbon\Carbon::parse($scan['scanned_at']);
                        @endphp
                        <div
                            wire:key="scan-history-{{ $scan['id'] }}"
                            @class([
                                'flex items-center gap-4 p-4 rounded-xl ring-1 transition cursor-pointer',
                                'ring-primary-600/50 bg-primary-50 dark:bg-primary-500/10' => $isActive,
                                'ring-gray-950/5 dark:ring-white/10 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-white/5' => !$isActive,
                            ])
                            wire:click="viewScan({{ $scan['id'] }})"
                            title="{{ __('ycookies.script_scanner.click_row_hint') }}"
                        >
                            <div wire:click.stop class="shrink-0 flex items-center" title="{{ __('ycookies.script_scanner.select_for_bulk') }}">
                                <x-filament::input.checkbox
                                    wire:model.live="selectedHistoryScanIds"
                                    value="{{ $scan['id'] }}"
                                />
                            </div>

                            {{-- Date / Domain Name --}}
                            <div class="w-48 shrink-0">
                                <p @class(["text-sm font-bold truncate", "text-primary-600 dark:text-primary-400" => $isActive, "text-gray-950 dark:text-white" => !$isActive]) title="{{ $scan['domain_name'] ?? __('ycookies.script_scanner.unknown_domain') }}">
                                    {{ $scan['domain_name'] ?? __('ycookies.script_scanner.unknown_domain') }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $scannedAt->format('M d, H:i') }} · {{ $scannedAt->diffForHumans() }}
                                </p>
                            </div>

                            {{-- Source badge --}}
                            <x-filament::badge 
                                :color="match($scan['source']) { 'manual' => 'primary', 'scheduled' => 'info', 'baseline' => 'gray', default => 'success' }" 
                                class="shrink-0 uppercase"
                            >
                                {{ $scan['source'] }}
                            </x-filament::badge>

                            {{-- GDPR status + script counts --}}
                            <div class="flex flex-wrap items-center gap-2 flex-1">
                                @if(($scan['unblocked_count'] ?? 0) > 0)
                                    <x-filament::badge color="danger">🚨 privacy risk (GDPR non-compliant)</x-filament::badge>
                                @else
                                    @if($scan['total_scripts'] > 0)
                                        <x-filament::badge color="success">✓ privacy safe (GDPR compliant)</x-filament::badge>
                                    @endif
                                @endif
                                <x-filament::badge color="gray">{{ $scan['total_scripts'] }} {{ __('ycookies.script_scanner.total_label') }}</x-filament::badge>
                                @if(($scan['unblocked_count'] ?? 0) === 0)
                                    @if($scan['protected_count'] > 0)
                                        <x-filament::badge color="success">🛡 {{ $scan['protected_count'] }}</x-filament::badge>
                                    @endif
                                    @if($scan['suggested_count'] > 0)
                                        <x-filament::badge color="warning">💡 {{ $scan['suggested_count'] }}</x-filament::badge>
                                    @endif
                                    @if($scan['unknown_count'] > 0)
                                        <x-filament::badge color="danger">? {{ $scan['unknown_count'] }}</x-filament::badge>
                                    @endif
                                @endif
                            </div>

                            {{-- Pages count --}}
                            <div class="text-xs text-gray-500 dark:text-gray-400 shrink-0">
                                {{ $scan['pages_scanned_count'] }}
                                {{ $scan['pages_scanned_count'] === 1 ? __('ycookies.script_scanner.page_unit') : __('ycookies.script_scanner.pages_unit') }}
                            </div>

                            {{-- Active indicator --}}
                            @if($isActive)
                                <div class="text-xs font-bold text-primary-600 dark:text-primary-400 uppercase shrink-0">
                                    {{ __('ycookies.script_scanner.viewing') }}
                                </div>
                            @endif

                            {{-- Delete button --}}
                            <div wire:click.stop class="shrink-0">
                                {{ ($this->deleteScanAction)(['scanId' => $scan['id']]) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        </div>
    @endif

    {{-- ═══════════ VISITOR DISCOVERY ═══════════ --}}
    @if($selectedDomainId && $selectedDomainId !== 'custom')
        <div class="mt-6">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-signal class="w-5 h-5 text-warning-500" />
                        <span>{{ __('Visitor Discovery') }}</span>
                        @if($discoveredPendingCount > 0)
                            <span class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold rounded-full bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400">
                                {{ $discoveredPendingCount }} pending
                            </span>
                        @endif
                    </div>
                </x-slot>
                <x-slot name="description">
                    Resources blocked by visitor browsers that are not yet configured as explicit blockers.
                </x-slot>

                @if(count($discoveredResources) > 0)
                    <div class="space-y-2">
                        @foreach($discoveredResources as $resource)
                            <div class="flex items-center gap-4 p-3 rounded-lg border border-gray-200 dark:border-gray-700"
                                 style="background: {{ $resource['status'] === 'pending' ? 'rgba(234,179,8,0.05)' : ($resource['status'] === 'resolved' ? 'rgba(34,197,94,0.05)' : 'rgba(100,116,139,0.05)') }};">

                                {{-- Type icon --}}
                                <div class="shrink-0">
                                    @if($resource['resource_type'] === 'script')
                                        <x-heroicon-o-code-bracket class="w-5 h-5 text-blue-500" />
                                    @elseif($resource['resource_type'] === 'style')
                                        <x-heroicon-o-paint-brush class="w-5 h-5 text-purple-500" />
                                    @else
                                        <x-heroicon-o-signal class="w-5 h-5 text-orange-500" />
                                    @endif
                                </div>

                                {{-- Provider & URL --}}
                                <div class="flex-1 min-w-0">
                                    <div class="font-semibold text-sm">{{ $resource['provider_host'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $resource['sample_url'] }}</div>
                                </div>

                                {{-- Type badge --}}
                                <div class="shrink-0">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                        {{ $resource['resource_type'] === 'script' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' : '' }}
                                        {{ $resource['resource_type'] === 'style' ? 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400' : '' }}
                                        {{ $resource['resource_type'] === 'service' ? 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400' : '' }}
                                    ">
                                        {{ $resource['resource_type'] }}
                                    </span>
                                </div>

                                {{-- Hit count --}}
                                <div class="shrink-0 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                    {{ $resource['hit_count'] }} {{ $resource['hit_count'] === 1 ? 'hit' : 'hits' }}
                                </div>

                                {{-- Status --}}
                                <div class="shrink-0">
                                    @if($resource['status'] === 'pending')
                                        <span class="text-xs font-semibold text-warning-600 dark:text-warning-400">Pending</span>
                                    @elseif($resource['status'] === 'resolved')
                                        <span class="text-xs font-semibold text-success-600 dark:text-success-400">Resolved</span>
                                    @else
                                        <span class="text-xs font-semibold text-gray-400">Ignored</span>
                                    @endif
                                </div>

                                {{-- Actions --}}
                                @if($resource['status'] === 'pending')
                                    <div class="flex gap-1 shrink-0">
                                        <button wire:click="resolveDiscoveredResource({{ $resource['id'] }})"
                                                class="inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md text-white bg-primary-600 hover:bg-primary-500 transition">
                                            Create Blocker
                                        </button>
                                        <button wire:click="ignoreDiscoveredResource({{ $resource['id'] }})"
                                                class="inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                                            Ignore
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <x-heroicon-o-signal class="w-8 h-8 mx-auto mb-2 opacity-50" />
                        <p>No discovered resources yet.</p>
                        <p class="text-xs mt-1">Resources will appear here as visitors browse your site and encounter blocked third-party content.</p>
                    </div>
                @endif
            </x-filament::section>
        </div>
    @endif

    </div>

</x-filament-panels::page>

