<div class="space-y-4">
    {{-- ── Latest Status Card ── --}}
    @if($latestResult)
        <div class="rounded-xl border p-5
            {{ match($latestResult['status'] ?? 'unknown') {
                'healthy' => 'border-emerald-500/30 bg-emerald-500/5',
                'warning' => 'border-amber-500/30 bg-amber-500/5',
                'failing' => 'border-red-500/30 bg-red-500/5',
                default => 'border-gray-600 bg-gray-800/50'
            } }}">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="text-3xl">
                        {{ match($latestResult['status'] ?? '') {
                            'healthy' => '🟢',
                            'warning' => '🟡',
                            'failing' => '🔴',
                            default => '⚪'
                        } }}
                    </span>
                    <div>
                        <h3 class="text-lg font-bold text-white">{{ ucfirst($latestResult['status'] ?? 'Unknown') }}</h3>
                        <p class="text-xs text-gray-400">
                            {{ $latestResult['checks_passed'] ?? 0 }}/{{ $latestResult['checks_total'] ?? 0 }} checks passed
                            · {{ $latestResult['duration_ms'] ?? 0 }}ms
                            · {{ \Carbon\Carbon::parse($latestResult['checked_at'])->format('M d, Y H:i:s') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Check Results Grid ── --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach(($latestResult['checks'] ?? []) as $check)
                @php
                    $checkStatus = $check['status'] ?? '';
                    $borderColor = match($checkStatus) {
                        'pass' => 'border-emerald-500/20 bg-emerald-500/5',
                        'warn' => 'border-amber-500/20 bg-amber-500/5',
                        'fail' => 'border-red-500/20 bg-red-500/5',
                        default => 'border-gray-700 bg-gray-800/50'
                    };
                @endphp
                <div class="rounded-lg border p-3 flex items-start gap-2.5 {{ $borderColor }}">
                    <span class="text-xs mt-0.5">{{ match($checkStatus) { 'pass' => '✓', 'warn' => '⚠', 'fail' => '✗', default => '●' } }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-medium text-white truncate">{{ $check['label'] ?? $check['name'] }}</p>
                        @if(!empty($check['message']))
                            <p class="text-[10px] text-gray-500 line-clamp-1" title="{{ $check['message'] }}">{{ $check['message'] }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ── AI Diagnosis Panel ── --}}
        @if(!empty($aiDiagnosis))
            <div class="rounded-xl border border-violet-500/30 bg-violet-500/10 p-5 space-y-3 relative overflow-hidden mt-4">
                <div class="absolute -left-1 -top-1 -bottom-1 w-1 bg-violet-500 shadow-[0_0_10px_rgba(139,92,246,0.5)]"></div>
                <div class="flex items-center justify-between pl-2">
                    <div class="flex items-center gap-2">
                        <span class="text-xl">🤖</span>
                        <h4 class="text-sm font-bold text-white">AI Health Diagnosis</h4>
                    </div>
                    <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-black/30 text-violet-300">
                        {{ $aiDiagnosis['overall_assessment'] ?? 'analysis' }}
                    </span>
                </div>
                <div class="pl-2 space-y-3">
                    <div>
                        <p class="text-[10px] uppercase font-bold text-gray-500 tracking-wider mb-1">Root Cause</p>
                        <p class="text-xs text-gray-300 leading-relaxed">{{ $aiDiagnosis['root_cause'] ?? '' }}</p>
                    </div>
                    @if(!empty($aiDiagnosis['suggested_fixes']))
                        <div class="space-y-2">
                            @foreach($aiDiagnosis['suggested_fixes'] as $fix)
                                <div class="rounded-lg bg-white/5 p-2.5 border border-white/5">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-[10px] font-bold text-violet-400 uppercase"># {{ $fix['priority'] ?? 'fix' }}</span>
                                        <span class="text-xs font-bold text-white">{{ $fix['title'] ?? '' }}</span>
                                    </div>
                                    <p class="text-[10px] text-gray-400 leading-relaxed">{{ $fix['description'] ?? '' }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>
