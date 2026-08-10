<div class="space-y-6" wire:poll.30s="refresh">

    {{-- ── Header ── --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                <x-heroicon-o-server-stack class="w-5 h-5 text-emerald-400" />
            </div>
            <div>
                <h3 class="text-lg font-bold text-white">Server Infrastructure</h3>
                <p class="text-xs text-gray-500">Last refreshed: {{ $lastRefresh }}</p>
            </div>
        </div>
        <button wire:click="refresh" class="flex items-center gap-2 px-3 py-1.5 text-xs font-medium rounded-lg bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10 transition-all">
            <x-heroicon-o-arrow-path class="w-3.5 h-3.5" wire:loading.class="animate-spin" wire:target="refresh" />
            Refresh
        </button>
    </div>

    {{-- ── Resource Gauges ── --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

        {{-- Disk --}}
        <div class="relative overflow-hidden rounded-2xl bg-white/[0.03] border border-white/10 p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <div class="flex items-center justify-center w-8 h-8 rounded-lg
                        @if($disk['status'] === 'critical') bg-red-500/15 @elseif($disk['status'] === 'warning') bg-amber-500/15 @elseif($disk['status'] === 'caution') bg-yellow-500/15 @else bg-emerald-500/15 @endif">
                        <x-heroicon-o-circle-stack class="w-4 h-4 {{ $disk['status'] === 'critical' ? 'text-red-400' : ($disk['status'] === 'warning' ? 'text-amber-400' : ($disk['status'] === 'caution' ? 'text-yellow-400' : 'text-emerald-400')) }}" />
                    </div>
                    <span class="text-sm font-semibold text-gray-300">Disk</span>
                </div>
                <span class="text-xs font-mono
                    @if($disk['status'] === 'critical') text-red-400 @elseif($disk['status'] === 'warning') text-amber-400 @else text-gray-500 @endif">
                    {{ $disk['percent'] }}%
                </span>
            </div>
            <div class="flex items-baseline gap-1 mb-3">
                <span class="text-2xl font-bold text-white">{{ $disk['used_gb'] }}</span>
                <span class="text-sm text-gray-500">/ {{ $disk['total_gb'] }} GB</span>
            </div>
            <div class="w-full h-2 rounded-full bg-white/5 overflow-hidden">
                <div class="h-full rounded-full transition-all duration-700
                    @if($disk['status'] === 'critical') bg-gradient-to-r from-red-500 to-red-400
                    @elseif($disk['status'] === 'warning') bg-gradient-to-r from-amber-500 to-amber-400
                    @elseif($disk['status'] === 'caution') bg-gradient-to-r from-yellow-500 to-yellow-400
                    @else bg-gradient-to-r from-emerald-500 to-emerald-400 @endif"
                     style="width: {{ min($disk['percent'], 100) }}%"></div>
            </div>
            <div class="flex justify-between mt-2 text-xs text-gray-500">
                <span>{{ $disk['free_gb'] }} GB free</span>
                <span>{{ $disk['used_gb'] }} GB used</span>
            </div>
        </div>

        {{-- Memory --}}
        <div class="relative overflow-hidden rounded-2xl bg-white/[0.03] border border-white/10 p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <div class="flex items-center justify-center w-8 h-8 rounded-lg
                        @if($memory['status'] === 'critical') bg-red-500/15 @elseif($memory['status'] === 'warning') bg-amber-500/15 @else bg-blue-500/15 @endif">
                        <x-heroicon-o-cpu-chip class="w-4 h-4 {{ $memory['status'] === 'critical' ? 'text-red-400' : ($memory['status'] === 'warning' ? 'text-amber-400' : 'text-blue-400') }}" />
                    </div>
                    <span class="text-sm font-semibold text-gray-300">Memory</span>
                </div>
                <span class="text-xs font-mono
                    @if($memory['status'] === 'critical') text-red-400 @elseif($memory['status'] === 'warning') text-amber-400 @else text-gray-500 @endif">
                    {{ $memory['percent'] }}%
                </span>
            </div>
            <div class="flex items-baseline gap-1 mb-3">
                <span class="text-2xl font-bold text-white">{{ round($memory['used_mb'] / 1024, 1) }}</span>
                <span class="text-sm text-gray-500">/ {{ round($memory['total_mb'] / 1024, 1) }} GB</span>
            </div>
            <div class="w-full h-2 rounded-full bg-white/5 overflow-hidden">
                <div class="h-full rounded-full transition-all duration-700
                    @if($memory['status'] === 'critical') bg-gradient-to-r from-red-500 to-red-400
                    @elseif($memory['status'] === 'warning') bg-gradient-to-r from-amber-500 to-amber-400
                    @else bg-gradient-to-r from-blue-500 to-blue-400 @endif"
                     style="width: {{ min($memory['percent'], 100) }}%"></div>
            </div>
            <div class="flex justify-between mt-2 text-xs text-gray-500">
                <span>{{ round($memory['available_mb'] / 1024, 1) }} GB available</span>
                <span>Buffers: {{ round($memory['buffers_mb'] / 1024, 1) }}G / Cache: {{ round($memory['cached_mb'] / 1024, 1) }}G</span>
            </div>
        </div>

        {{-- CPU Load --}}
        <div class="relative overflow-hidden rounded-2xl bg-white/[0.03] border border-white/10 p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <div class="flex items-center justify-center w-8 h-8 rounded-lg
                        @if($cpu['status'] === 'critical') bg-red-500/15 @elseif($cpu['status'] === 'warning') bg-amber-500/15 @else bg-purple-500/15 @endif">
                        <x-heroicon-o-bolt class="w-4 h-4 {{ $cpu['status'] === 'critical' ? 'text-red-400' : ($cpu['status'] === 'warning' ? 'text-amber-400' : 'text-purple-400') }}" />
                    </div>
                    <span class="text-sm font-semibold text-gray-300">CPU Load</span>
                </div>
                <span class="text-xs font-mono
                    @if($cpu['status'] === 'critical') text-red-400 @elseif($cpu['status'] === 'warning') text-amber-400 @else text-gray-500 @endif">
                    {{ $cpu['load_percent'] }}%
                </span>
            </div>
            <div class="flex items-baseline gap-1 mb-3">
                <span class="text-2xl font-bold text-white">{{ $cpu['load_1m'] }}</span>
                <span class="text-sm text-gray-500">/ {{ $cpu['cores'] }} cores</span>
            </div>
            <div class="w-full h-2 rounded-full bg-white/5 overflow-hidden">
                <div class="h-full rounded-full transition-all duration-700
                    @if($cpu['status'] === 'critical') bg-gradient-to-r from-red-500 to-red-400
                    @elseif($cpu['status'] === 'warning') bg-gradient-to-r from-amber-500 to-amber-400
                    @else bg-gradient-to-r from-purple-500 to-purple-400 @endif"
                     style="width: {{ min($cpu['load_percent'], 100) }}%"></div>
            </div>
            <div class="flex justify-between mt-2 text-xs text-gray-500">
                <span>1m: {{ $cpu['load_1m'] }}</span>
                <span>5m: {{ $cpu['load_5m'] }}</span>
                <span>15m: {{ $cpu['load_15m'] }}</span>
            </div>
        </div>

        {{-- Uptime --}}
        <div class="relative overflow-hidden rounded-2xl bg-white/[0.03] border border-white/10 p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-cyan-500/15">
                        <x-heroicon-o-clock class="w-4 h-4 text-cyan-400" />
                    </div>
                    <span class="text-sm font-semibold text-gray-300">Uptime</span>
                </div>
                <span class="flex items-center gap-1.5 text-xs">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-emerald-400 font-medium">Online</span>
                </span>
            </div>
            <div class="flex items-baseline gap-1 mb-3">
                <span class="text-2xl font-bold text-white">{{ $uptime['days'] ?? 0 }}</span>
                <span class="text-sm text-gray-500">days</span>
            </div>
            <div class="mt-1 text-sm text-gray-400">{{ $uptime['formatted'] ?? '—' }}</div>
            <div class="mt-2 text-xs text-gray-500">Server is running normally</div>
        </div>
    </div>

    @if(!$isDashboard)
    {{-- ── Manual Cleanup Actions (always visible) ── --}}
    <div class="rounded-2xl bg-white/[0.03] border border-white/10 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-white/5">
            <div class="flex items-center gap-2">
                <x-heroicon-o-sparkles class="w-5 h-5 text-sky-400" />
                <h4 class="text-sm font-semibold text-white">Manual Cleanup</h4>
            </div>
            <span class="text-xs text-gray-500">Free up disk space & memory</span>
        </div>

        <div class="p-5 space-y-4">
            {{-- Safe actions --}}
            <div>
                <p class="text-xs font-medium text-emerald-400 mb-3 flex items-center gap-1.5">
                    <x-heroicon-o-shield-check class="w-3.5 h-3.5" />
                    Safe — no data loss
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    {{-- Laravel Caches --}}
                    <div class="flex items-center justify-between rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div>
                            <p class="text-sm font-medium text-gray-200">Laravel Caches</p>
                            <p class="text-xs text-gray-500 mt-0.5">Config, routes, views, compiled</p>
                            <p class="text-xs font-mono text-sky-400 mt-1">{{ $cleanupEstimates['laravel_cache']['formatted'] ?? '—' }}</p>
                        </div>
                        <button wire:click="clearLaravelCaches"
                                wire:loading.attr="disabled"
                                wire:target="clearLaravelCaches"
                                class="shrink-0 ml-3 flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="clearLaravelCaches"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="clearLaravelCaches" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="clearLaravelCaches">Clear</span>
        <span wire:loading wire:target="clearLaravelCaches" style="display: none;">Wait...</span>
    </button>
                    </div>

                    {{-- Redis Cache --}}
                    <div class="flex items-center justify-between rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div>
                            <p class="text-sm font-medium text-gray-200">Redis Cache</p>
                            <p class="text-xs text-gray-500 mt-0.5">Flush non-critical cached data</p>
                            <p class="text-xs font-mono text-sky-400 mt-1">Frees memory</p>
                        </div>
                        <button wire:click="clearRedisCache"
                                wire:loading.attr="disabled"
                                wire:target="clearRedisCache"
                                class="shrink-0 ml-3 flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="clearRedisCache"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="clearRedisCache" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="clearRedisCache">Flush</span>
        <span wire:loading wire:target="clearRedisCache" style="display: none;">Wait...</span>
    </button>
                    </div>

                    {{-- Proxy Config Cache --}}
                    <div class="flex items-center justify-between rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div>
                            <p class="text-sm font-medium text-gray-200">Proxy Config Cache</p>
                            <p class="text-xs text-gray-500 mt-0.5">Disk snapshots from Node proxy</p>
                            <p class="text-xs font-mono text-sky-400 mt-1">{{ $cleanupEstimates['proxy_cache']['formatted'] ?? '—' }}</p>
                        </div>
                        <button wire:click="clearProxyCache"
                                wire:loading.attr="disabled"
                                wire:target="clearProxyCache"
                                class="shrink-0 ml-3 flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="clearProxyCache"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="clearProxyCache" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="clearProxyCache">Clear</span>
        <span wire:loading wire:target="clearProxyCache" style="display: none;">Wait...</span>
    </button>
                    </div>
                </div>
            </div>

            {{-- Destructive actions --}}
            <div>
                <p class="text-xs font-medium text-amber-400 mb-3 flex items-center gap-1.5">
                    <x-heroicon-o-exclamation-triangle class="w-3.5 h-3.5" />
                    Destructive — removes old data permanently
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    {{-- Purge Logs --}}
                    <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-sm font-medium text-gray-200">Old Logs</p>
                            <span class="text-xs font-mono text-amber-400">{{ $cleanupEstimates['logs']['formatted'] ?? '—' }}</span>
                        </div>
                        <p class="text-xs text-gray-500 mb-3">Log files older than 7 days</p>
                        <button wire:click="purgeLogs"
                                wire:loading.attr="disabled"
                                wire:target="purgeLogs"
                                wire:confirm="Delete all log files older than 7 days?"
                                class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400 hover:bg-amber-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="purgeLogs"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="purgeLogs" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="purgeLogs">Purge Logs</span>
        <span wire:loading wire:target="purgeLogs" style="display: none;">Wait...</span>
    </button>
                    </div>

                    {{-- Prune Backups --}}
                    <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-sm font-medium text-gray-200">Old Backups</p>
                            <span class="text-xs font-mono text-amber-400">{{ $cleanupEstimates['backups']['formatted'] ?? '—' }}</span>
                        </div>
                        <p class="text-xs text-gray-500 mb-3">Backups beyond retention policy</p>
                        <button wire:click="pruneBackups"
                                wire:loading.attr="disabled"
                                wire:target="pruneBackups"
                                wire:confirm="Prune backups beyond the configured retention policy?"
                                class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400 hover:bg-amber-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="pruneBackups"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="pruneBackups" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="pruneBackups">Prune Backups</span>
        <span wire:loading wire:target="pruneBackups" style="display: none;">Wait...</span>
    </button>
                    </div>

                    {{-- Purge Health History --}}
                    <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-sm font-medium text-gray-200">Health History</p>
                            <span class="text-xs font-mono text-amber-400">{{ $cleanupEstimates['health_history']['formatted'] ?? '—' }}</span>
                        </div>
                        <p class="text-xs text-gray-500 mb-3">Health checks older than 30 days</p>
                        <button wire:click="purgeHealthHistory"
                                wire:loading.attr="disabled"
                                wire:target="purgeHealthHistory"
                                wire:confirm="Delete all health check records older than 30 days? This cannot be undone."
                                class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400 hover:bg-amber-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="purgeHealthHistory"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="purgeHealthHistory" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="purgeHealthHistory">Purge History</span>
        <span wire:loading wire:target="purgeHealthHistory" style="display: none;">Wait...</span>
    </button>
                    </div>

                    {{-- Purge Traffic Metrics --}}
                    <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-sm font-medium text-gray-200">Traffic Metrics</p>
                            <span class="text-xs font-mono text-amber-400">{{ $cleanupEstimates['traffic_metrics']['formatted'] ?? '—' }}</span>
                        </div>
                        <p class="text-xs text-gray-500 mb-3">Edge metrics older than 30 days</p>
                        <button wire:click="purgeTrafficMetrics"
                                wire:loading.attr="disabled"
                                wire:target="purgeTrafficMetrics"
                                wire:confirm="Delete all traffic metrics older than 30 days? This cannot be undone."
                                class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400 hover:bg-amber-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="purgeTrafficMetrics"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="purgeTrafficMetrics" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="purgeTrafficMetrics">Purge Metrics</span>
        <span wire:loading wire:target="purgeTrafficMetrics" style="display: none;">Wait...</span>
    </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Server-Level Cleanup (Docker + System) — works via SSH ── --}}
    @if($sshConfigured)
    <div class="rounded-2xl bg-white/[0.03] border border-white/10 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-white/5">
            <div class="flex items-center gap-2">
                <x-heroicon-o-server-stack class="w-5 h-5 text-rose-400" />
                <h4 class="text-sm font-semibold text-white">Server Cleanup</h4>
            </div>
            <div class="flex items-center gap-3">
                @if(!empty($dockerUsage['total']) && $dockerUsage['total'] !== '—')
                    <span class="text-xs font-mono text-rose-400">{{ $dockerUsage['total'] }}</span>
                @endif
                <button wire:click="pruneDockerAll"
                        wire:loading.attr="disabled"
                        wire:target="pruneDockerAll"
                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-400 hover:bg-rose-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="pruneDockerAll"><x-heroicon-o-fire class="w-3.5 h-3.5" /></span>
        <span wire:loading wire:target="pruneDockerAll" style="display: none;"><x-filament::loading-indicator class="w-3.5 h-3.5" /></span>
        <span wire:loading.remove wire:target="pruneDockerAll">Full System Prune</span>
        <span wire:loading wire:target="pruneDockerAll" style="display: none;">Wait...</span>
    </button>
            </div>
        </div>

        <div class="p-5 space-y-4">
            {{-- Docker cleanup row --}}
            <div>
                <p class="text-xs font-medium text-rose-400 mb-3 flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    Docker — running services are not affected
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">

                    {{-- Images --}}
                    <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div class="flex items-center justify-between mb-1">
                            <p class="text-sm font-medium text-gray-200">Images</p>
                        </div>
                        <p class="text-xs text-gray-500 mb-1">Unused images older than {{ $imagePruneAge }}</p>
                        <p class="text-xs font-mono text-rose-400/80 mb-2">{{ $dockerUsage['images'] ?? '—' }}</p>
                        <div class="flex items-center gap-1.5 mb-2">
                            <select wire:model.live="imagePruneAge"
                                    class="flex-1 text-xs rounded-lg border border-white/10 text-gray-300 px-2 py-1.5 focus:border-rose-500/50 focus:ring-0"
                                    style="background-color: rgba(255,255,255,0.05); color: #d1d5db;">
                                <option value="1h" style="background:#1a1a2e;color:#d1d5db;">> 1 hour</option>
                                <option value="2h" style="background:#1a1a2e;color:#d1d5db;">> 2 hours</option>
                                <option value="4h" style="background:#1a1a2e;color:#d1d5db;">> 4 hours</option>
                                <option value="6h" style="background:#1a1a2e;color:#d1d5db;">> 6 hours</option>
                                <option value="12h" style="background:#1a1a2e;color:#d1d5db;">> 12 hours</option>
                                <option value="24h" style="background:#1a1a2e;color:#d1d5db;">> 24 hours</option>
                                <option value="48h" style="background:#1a1a2e;color:#d1d5db;">> 2 days</option>
                                <option value="72h" style="background:#1a1a2e;color:#d1d5db;">> 3 days</option>
                            </select>
                        </div>
                        <button wire:click="pruneDockerImages"
                                wire:loading.attr="disabled"
                                wire:target="pruneDockerImages"
                                class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-400 hover:bg-rose-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="pruneDockerImages"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="pruneDockerImages" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="pruneDockerImages">Prune Images</span>
        <span wire:loading wire:target="pruneDockerImages" style="display: none;">Wait...</span>
    </button>
                    </div>

                    {{-- Containers --}}
                    <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div class="flex items-center justify-between mb-1">
                            <p class="text-sm font-medium text-gray-200">Containers</p>
                        </div>
                        <p class="text-xs text-gray-500 mb-1">Stopped >24h containers</p>
                        <p class="text-xs font-mono text-rose-400/80 mb-3">{{ $dockerUsage['containers'] ?? '—' }}</p>
                        <button wire:click="pruneDockerContainers"
                                wire:loading.attr="disabled"
                                wire:target="pruneDockerContainers"
                                class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-400 hover:bg-rose-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="pruneDockerContainers"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="pruneDockerContainers" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="pruneDockerContainers">Prune Containers</span>
        <span wire:loading wire:target="pruneDockerContainers" style="display: none;">Wait...</span>
    </button>
                    </div>

                    {{-- Volumes --}}
                    <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div class="flex items-center justify-between mb-1">
                            <p class="text-sm font-medium text-gray-200">Volumes</p>
                        </div>
                        <p class="text-xs text-gray-500 mb-1">Anonymous volumes only</p>
                        <p class="text-xs font-mono text-rose-400/80 mb-3">{{ $dockerUsage['volumes'] ?? '—' }}</p>
                        <button wire:click="pruneDockerVolumes"
                                wire:loading.attr="disabled"
                                wire:target="pruneDockerVolumes"
                                class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-400 hover:bg-rose-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="pruneDockerVolumes"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="pruneDockerVolumes" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="pruneDockerVolumes">Prune Volumes</span>
        <span wire:loading wire:target="pruneDockerVolumes" style="display: none;">Wait...</span>
    </button>
                    </div>

                    {{-- Build Cache --}}
                    <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div class="flex items-center justify-between mb-1">
                            <p class="text-sm font-medium text-gray-200">Build Cache</p>
                        </div>
                        <p class="text-xs text-gray-500 mb-1">Cached image build layers</p>
                        <p class="text-xs font-mono text-rose-400/80 mb-3">{{ $dockerUsage['build_cache'] ?? '—' }}</p>
                        <button wire:click="pruneDockerBuildCache"
                                wire:loading.attr="disabled"
                                wire:target="pruneDockerBuildCache"
                                class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-400 hover:bg-rose-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="pruneDockerBuildCache"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="pruneDockerBuildCache" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="pruneDockerBuildCache">Prune Cache</span>
        <span wire:loading wire:target="pruneDockerBuildCache" style="display: none;">Wait...</span>
    </button>
                    </div>
                </div>
            </div>

            {{-- Stopped services warning (appears when user clicks Prune Images with offline apps) --}}
            @if($pendingImagePrune && !empty($stoppedServices))
                <div class="rounded-xl bg-amber-500/5 border border-amber-500/30 p-4 space-y-3">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-amber-400 shrink-0" />
                        <p class="text-sm font-medium text-amber-400">Offline services detected — images may be deleted</p>
                    </div>
                    <p class="text-xs text-gray-400">These stopped apps have images older than 24h that will be removed. They can be rebuilt from git on restart (2-3 min).</p>
                    <div class="space-y-1.5">
                        @foreach($stoppedServices as $svc)
                            <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-white/[0.02] border border-white/5">
                                <span class="text-xs font-medium text-gray-300">{{ $svc['name'] }}</span>
                                <span class="text-xs text-gray-500">{{ $svc['status'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-2 pt-1">
                        <button wire:click="confirmPruneImages"
                                wire:loading.attr="disabled"
                                wire:target="confirmPruneImages"
                                class="flex items-center gap-1.5 px-4 py-2 text-xs font-medium rounded-lg bg-amber-500/20 border border-amber-500/40 text-amber-300 hover:bg-amber-500/30 disabled:opacity-50 transition-all">
                            <span wire:loading.remove wire:target="confirmPruneImages"><x-heroicon-o-check class="w-3.5 h-3.5" /></span>
                            <span wire:loading wire:target="confirmPruneImages" style="display: none;"><x-filament::loading-indicator class="w-3.5 h-3.5" /></span>
                            <span wire:loading.remove wire:target="confirmPruneImages">Prune Anyway</span>
                            <span wire:loading wire:target="confirmPruneImages" style="display: none;">Pruning...</span>
                        </button>
                        <button wire:click="cancelPruneImages"
                                class="flex items-center gap-1.5 px-4 py-2 text-xs font-medium rounded-lg bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10 transition-all">
                            <x-heroicon-o-x-mark class="w-3.5 h-3.5" />
                            Cancel
                        </button>
                    </div>
                </div>
            @endif

            {{-- System cleanup row --}}
            <div>
                <p class="text-xs font-medium text-gray-400 mb-3 flex items-center gap-1.5">
                    <x-heroicon-o-command-line class="w-3.5 h-3.5" />
                    System — OS-level temp files & logs
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">

                    {{-- Journal Logs --}}
                    <div class="flex items-center justify-between rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div>
                            <p class="text-sm font-medium text-gray-200">Journal Logs</p>
                            <p class="text-xs text-gray-500 mt-0.5">Vacuum systemd logs older than 3 days</p>
                        </div>
                        <button wire:click="vacuumJournalLogs"
                                wire:loading.attr="disabled"
                                wire:target="vacuumJournalLogs"
                                class="shrink-0 ml-3 flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-500/10 border border-gray-500/30 text-gray-400 hover:bg-gray-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="vacuumJournalLogs"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="vacuumJournalLogs" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="vacuumJournalLogs">Vacuum</span>
        <span wire:loading wire:target="vacuumJournalLogs" style="display: none;">Wait...</span>
    </button>
                    </div>

                    {{-- Temp Files --}}
                    <div class="flex items-center justify-between rounded-xl bg-white/[0.02] border border-white/5 p-4">
                        <div>
                            <p class="text-sm font-medium text-gray-200">Temp Files & Package Cache</p>
                            <p class="text-xs text-gray-500 mt-0.5">Clean /tmp (>7d) and apt/apk cache</p>
                        </div>
                        <button wire:click="cleanSystemTemp"
                                wire:loading.attr="disabled"
                                wire:target="cleanSystemTemp"
                                class="shrink-0 ml-3 flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-500/10 border border-gray-500/30 text-gray-400 hover:bg-gray-500/20 disabled:opacity-50 transition-all">
        <span wire:loading.remove wire:target="cleanSystemTemp"><x-heroicon-o-trash class="w-3 h-3" /></span>
        <span wire:loading wire:target="cleanSystemTemp" style="display: none;"><x-filament::loading-indicator class="w-3 h-3" /></span>
        <span wire:loading.remove wire:target="cleanSystemTemp">Clean</span>
        <span wire:loading wire:target="cleanSystemTemp" style="display: none;">Wait...</span>
    </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Action Output Console ── --}}
        @if($actionTitle && $actionOutput)
            <div class="px-5 pb-5">
                <div class="rounded-xl bg-gray-900 border border-gray-700/50 overflow-hidden shadow-lg">
                    <div class="px-4 py-2 border-b border-gray-700/50 bg-gray-800/80 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-command-line class="w-4 h-4 text-gray-400" />
                            <h5 class="text-xs font-semibold text-gray-300">Action Output: {{ $actionTitle }}</h5>
                        </div>
                        <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-full {{ str_contains(strtolower($actionTitle), 'failed') ? 'bg-rose-500/20 text-rose-400' : 'bg-emerald-500/20 text-emerald-400' }}">
                            {{ str_contains(strtolower($actionTitle), 'failed') ? 'Error' : 'Success' }}
                        </span>
                    </div>
                    <div class="p-4 overflow-x-auto">
                        <pre class="text-[11px] font-mono leading-relaxed text-gray-300 whitespace-pre-wrap break-words">{!! nl2br(e(trim($actionOutput))) !!}</pre>
                        @if(trim($actionOutput) === 'Total reclaimed space: 0B' || str_contains($actionOutput, 'Total reclaimed space: 0B'))
                            <div class="mt-3 p-2.5 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex gap-2">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-indigo-400 shrink-0 mt-0.5" />
                                <p class="text-xs text-indigo-300/90 leading-relaxed">
                                    <strong>No files were deleted.</strong> Docker determined the targeted items are still actively tied to running containers or build layers. Use 'Full System Prune' if you are certain you want to clear stopped containers and caches.
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- ── Emergency SSH Auto-Cleanup ── --}}
    <div class="rounded-2xl bg-white/[0.03] border border-white/10 overflow-hidden mb-8 mt-6">
        <div class="px-5 py-4 border-b border-white/5 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <x-heroicon-o-shield-exclamation class="w-5 h-5 text-rose-400" />
                <h4 class="text-sm font-semibold text-white">Emergency SSH Auto-Cleanup</h4>
            </div>
            <div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" wire:model.live="autoCleanupEnabled" class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-600 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-rose-500"></div>
                </label>
            </div>
        </div>
        
        <div class="p-5 {{ !$autoCleanupEnabled ? 'opacity-50 pointer-events-none' : '' }}">
            <p class="text-xs text-gray-400 mb-4 max-w-3xl">
                Automatically runs a background job via SSH to prune Docker if the server disk gets dangerously full between Coolify's native daily cleanups.
            </p>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-2xl">
                {{-- Threshold --}}
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Disk Usage Threshold (%)</label>
                    <div class="flex rounded-md shadow-sm">
                        <input type="number" wire:model.live.debounce.1000ms="autoCleanupThreshold" min="10" max="95" class="bg-gray-800 border border-gray-700 text-white text-sm rounded-lg focus:ring-rose-500 focus:border-rose-500 block w-full p-2.5">
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Triggers when disk is above this limit.</p>
                </div>

                {{-- Interval --}}
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Check Interval (Minutes)</label>
                    <div class="flex rounded-md shadow-sm">
                        <input type="number" wire:model.live.debounce.1000ms="autoCleanupInterval" min="15" step="15" class="bg-gray-800 border border-gray-700 text-white text-sm rounded-lg focus:ring-rose-500 focus:border-rose-500 block w-full p-2.5">
                    </div>
                    <p class="mt-1 text-xs text-gray-500">How often to check disk space. Minimum 15 mins.</p>
                </div>
            </div>
        </div>
    </div>

    @else
    {{-- SSH not configured — show setup guide --}}
    <div class="rounded-2xl bg-white/[0.03] border border-white/10 overflow-hidden">
        <div class="px-5 py-4 border-b border-white/5">
            <div class="flex items-center gap-2">
                <x-heroicon-o-server-stack class="w-5 h-5 text-gray-500" />
                <h4 class="text-sm font-semibold text-white">Server Cleanup</h4>
            </div>
        </div>
        <div class="px-5 py-8 text-center space-y-4">
            <div class="mx-auto w-12 h-12 rounded-full bg-amber-500/10 border border-amber-500/20 flex items-center justify-center">
                <x-heroicon-o-key class="w-6 h-6 text-amber-400" />
            </div>
            <div>
                <p class="text-sm font-medium text-gray-200">SSH Access Not Configured</p>
                <p class="text-xs text-gray-500 mt-1">Server cleanup requires SSH access to manage Docker resources on your host server.</p>
            </div>
            <a href="{{ \App\Filament\Pages\Settings::getUrl() }}"
               class="inline-flex items-center gap-2 px-4 py-2 text-xs font-medium rounded-lg bg-primary-500/10 border border-primary-500/30 text-primary-400 hover:bg-primary-500/20 transition-all">
                <x-heroicon-o-cog-6-tooth class="w-4 h-4" />
                Set Up in Settings
            </a>
        </div>
    </div>
    @endif

    @if($coolifyAvailable)
    {{-- ── Applications Status ── --}}
    <div class="rounded-2xl bg-white/[0.03] border border-white/10 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-white/5">
            <div class="flex items-center gap-2">
                <x-heroicon-o-squares-2x2 class="w-5 h-5 text-indigo-400" />
                <h4 class="text-sm font-semibold text-white">Applications</h4>
            </div>
            <span class="text-xs text-gray-500">{{ count($applications) }} services</span>
        </div>
        <div class="divide-y divide-white/5">
            @forelse($applications as $app)
                <div class="flex items-center justify-between px-5 py-3 hover:bg-white/[0.02] transition-colors">
                    <div class="flex items-center gap-3">
                        @php
                            $isHealthy = str_contains($app['status'] ?? '', 'healthy');
                            $isRunning = str_contains($app['status'] ?? '', 'running');
                        @endphp
                        <span class="w-2.5 h-2.5 rounded-full {{ $isHealthy ? 'bg-emerald-400' : ($isRunning ? 'bg-amber-400' : 'bg-red-400') }}"></span>
                        <div>
                            <p class="text-sm font-medium text-gray-200">{{ $app['name'] }}</p>
                            <p class="text-xs text-gray-500">{{ $app['type'] }}</p>
                        </div>
                    </div>
                    <span class="px-2.5 py-1 text-xs font-medium rounded-lg
                        {{ $isHealthy ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                            : ($isRunning ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20'
                                : 'bg-red-500/10 text-red-400 border border-red-500/20') }}">
                        {{ $app['status'] }}
                    </span>
                </div>
            @empty
                <div class="px-5 py-8 text-center text-gray-500 text-sm">No applications found</div>
            @endforelse
        </div>
    </div>

    {{-- ── Docker Cleanup Settings ── --}}
    <div class="rounded-2xl bg-white/[0.03] border border-white/10 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-white/5">
            <div class="flex items-center gap-2">
                <x-heroicon-o-trash class="w-5 h-5 text-amber-400" />
                <h4 class="text-sm font-semibold text-white">Docker Cleanup Settings</h4>
            </div>
            <div class="flex items-center gap-2">

                <a href="{{ config('services.coolify.base_url') }}/server/kcwsok0kk88kwc8cs8s48sow/docker-cleanup"
                   target="_blank"
                   class="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-lg bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10 transition-all">
                    <x-heroicon-o-arrow-top-right-on-square class="w-3 h-3" />
                    Manage in Coolify
                </a>
            </div>
        </div>

        <div class="px-5 py-4 bg-gray-500/5 border-b border-white/5">
            <p class="text-sm font-medium text-gray-300">If you run it on Coolify</p>
            <p class="text-xs text-gray-400 mt-1 mb-2">Use Coolify's built-in engine for aggressive daily pruning instead of scheduling background cron jobs here.</p>
            <ol class="text-xs text-gray-400 space-y-1 list-decimal list-inside">
                <li>Go to your <strong>Coolify Dashboard</strong></li>
                <li>Navigate to <strong>Servers → localhost → Docker Cleanup</strong></li>
                <li>Set Frequency: <code class="px-1 py-0.5 rounded bg-white/5 text-gray-300">0 0 * * *</code> (Runs Midnight)</li>
                <li>Check <strong>Force Docker Cleanup</strong> and <strong>Disable Application Image Retention</strong></li>
                <li>Click Save</li>
            </ol>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 p-5">
            {{-- Cleanup Schedule --}}
            <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                <p class="text-xs text-gray-500 mb-1">Cleanup Schedule</p>
                <p class="text-sm font-mono font-semibold text-white">{{ $cleanup['frequency'] ?? '—' }}</p>
                <p class="text-xs text-gray-500 mt-1">
                    @if(($cleanup['frequency'] ?? '') === '0 0 * * *') Daily at midnight
                    @elseif(($cleanup['frequency'] ?? '') === '0 3 * * *') Daily at 3 AM
                    @else Custom schedule @endif
                </p>
            </div>

            {{-- Disk Threshold --}}
            <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                <p class="text-xs text-gray-500 mb-1">Disk Alert Threshold</p>
                <p class="text-sm font-semibold text-white">{{ $cleanup['disk_alert_threshold'] ?? 80 }}%</p>
                <p class="text-xs mt-1 {{ ($disk['percent'] ?? 0) >= ($cleanup['disk_alert_threshold'] ?? 80) ? 'text-red-400' : 'text-emerald-400' }}">
                    @if(($disk['percent'] ?? 0) >= ($cleanup['disk_alert_threshold'] ?? 80))
                        ⚠ Above threshold!
                    @else
                        ✓ Within limits
                    @endif
                </p>
            </div>

            {{-- Flags --}}
            <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                <p class="text-xs text-gray-500 mb-2">Cleanup Flags</p>
                <div class="space-y-1.5">
                    <div class="flex items-center gap-2 text-xs">
                        @if($cleanup['force_cleanup'] ?? false)
                            <span class="w-4 h-4 rounded flex items-center justify-center bg-emerald-500/20"><x-heroicon-o-check class="w-3 h-3 text-emerald-400" /></span>
                            <span class="text-gray-300">Force cleanup</span>
                        @else
                            <span class="w-4 h-4 rounded flex items-center justify-center bg-white/5"><x-heroicon-o-x-mark class="w-3 h-3 text-gray-600" /></span>
                            <span class="text-gray-500">Force cleanup</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        @if($cleanup['delete_unused_volumes'] ?? false)
                            <span class="w-4 h-4 rounded flex items-center justify-center bg-emerald-500/20"><x-heroicon-o-check class="w-3 h-3 text-emerald-400" /></span>
                            <span class="text-gray-300">Delete unused volumes</span>
                        @else
                            <span class="w-4 h-4 rounded flex items-center justify-center bg-white/5"><x-heroicon-o-x-mark class="w-3 h-3 text-gray-600" /></span>
                            <span class="text-gray-500">Delete unused volumes</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        @if($cleanup['disable_image_retention'] ?? false)
                            <span class="w-4 h-4 rounded flex items-center justify-center bg-emerald-500/20"><x-heroicon-o-check class="w-3 h-3 text-emerald-400" /></span>
                            <span class="text-gray-300">Auto-delete old images</span>
                        @else
                            <span class="w-4 h-4 rounded flex items-center justify-center bg-red-500/20"><x-heroicon-o-x-mark class="w-3 h-3 text-red-400" /></span>
                            <span class="text-red-400">Old images kept forever!</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Cron Cleanup (our custom) --}}
            <div class="rounded-xl bg-white/[0.02] border border-white/5 p-4">
                <p class="text-xs text-gray-500 mb-2">Additional Cron (3 AM)</p>
                <div class="space-y-1.5 text-xs text-gray-400">
                    <p>• Build cache prune &gt;24h</p>
                    <p>• Dangling images &gt;2h</p>
                    <p>• Stopped containers &gt;24h</p>
                    <p>• Dangling volumes &gt;24h</p>
                    <p>• Disk usage logged</p>
                </div>
            </div>
        </div>
    </div>
    @else
    {{-- Coolify not configured --}}
    <div class="rounded-2xl bg-amber-500/5 border border-amber-500/20 p-6">
        <div class="flex items-start gap-3">
            <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-amber-400 mt-0.5 shrink-0" />
            <div>
                <p class="text-sm font-semibold text-amber-300">Coolify API not configured</p>
                <p class="text-xs text-amber-400/70 mt-1">
                    Set <code class="px-1.5 py-0.5 rounded bg-white/5 text-amber-300 font-mono">COOLIFY_INSTANCE_URL</code>
                    and <code class="px-1.5 py-0.5 rounded bg-white/5 text-amber-300 font-mono">COOLIFY_API_TOKEN</code>
                    in your environment to see application status and Docker cleanup settings.
                </p>
            </div>
        </div>
    </div>
    @endif
    @endif

</div>

