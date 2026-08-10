{{-- Detail View — expanded health check result --}}
<div class="rounded-xl border border-gray-600 bg-gray-800/50 p-5 space-y-4">
    <div class="flex items-center justify-between">
        <h3 class="text-base font-bold text-white">
            Run Detail — {{ $viewingResultData['checked_at'] ?? '' }}
            <span class="ml-2 text-xs px-2 py-0.5 rounded-full
                {{ match($viewingResultData['status'] ?? '') {
                    'healthy' => 'bg-emerald-500/20 text-emerald-400',
                    'warning' => 'bg-amber-500/20 text-amber-400',
                    'failing' => 'bg-red-500/20 text-red-400',
                    default => 'bg-gray-600 text-gray-300'
                } }}">
                {{ ucfirst($viewingResultData['status'] ?? '') }}
            </span>
        </h3>
        <button wire:click="closeDetail" class="text-gray-400 hover:text-white text-sm">✕ Close</button>
    </div>

    {{-- Checks --}}
    <div class="space-y-2">
        @foreach(($viewingResultData['checks'] ?? []) as $check)
            @php
                $cs = $check['status'] ?? '';
                $sev = $check['severity'] ?? 'informational';
                $rowBg = match($cs) {
                    'pass' => 'bg-emerald-500/5',
                    'expected' => 'bg-cyan-500/5',
                    'warn' => 'bg-amber-500/5',
                    'fail' => 'bg-red-500/5',
                    'ignored' => 'bg-gray-700/20 opacity-50',
                    default => 'bg-gray-700/30'
                };
                $icon = match($cs) {
                    'pass' => '✓',
                    'expected' => '✓',
                    'warn' => '⚠',
                    'fail' => '✗',
                    'ignored' => '—',
                    'skipped' => '—',
                    default => '?'
                };
                $sevLabel = match($sev) {
                    'critical' => 'Core',
                    'warning' => 'Important',
                    default => 'Info'
                };
                $sevBadge = match($sev) {
                    'critical' => 'text-blue-400',
                    'warning' => 'text-slate-400',
                    default => 'text-gray-500'
                };
            @endphp
            <div class="flex items-center gap-3 p-2 rounded-lg {{ $rowBg }}">
                <span class="text-sm shrink-0 w-5 text-center">{{ $icon }}</span>
                <span class="text-[10px] uppercase font-semibold w-20 {{ $sevBadge }}">{{ $sevLabel }}</span>
                <span class="text-sm font-medium text-white w-48 shrink-0 truncate" title="{{ $check['label'] ?? $check['name'] ?? '' }}">{{ $check['label'] ?? $check['name'] ?? '' }}</span>
                <span class="text-sm text-gray-400 flex-1 min-w-0 break-words whitespace-normal" title="{{ $check['message'] ?? '' }}">
                    {{ $check['message'] ?? '' }}
                    @if($cs === 'expected')
                        <span class="text-cyan-400 text-xs font-medium">EXPECTED</span>
                    @elseif($cs === 'ignored')
                        <span class="text-gray-500 text-xs font-medium">IGNORED</span>
                    @endif
                </span>
                @if(isset($check['duration_ms']))
                    <span class="text-xs text-gray-500 shrink-0">{{ $check['duration_ms'] }}ms</span>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Headers evidence --}}
    @if(!empty($viewingResultData['headers']))
        <div>
            <h4 class="text-sm font-semibold text-gray-300 mb-2">Collected Headers</h4>
            <pre class="bg-gray-900 rounded-lg p-3 text-xs text-gray-300 overflow-x-auto max-h-48">{{ json_encode($viewingResultData['headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif

    {{-- Enrichment Evidence Cards --}}
    @if(!empty($viewingResultData['evidence']))
        @php
            $evidence = $viewingResultData['evidence'];
            $cmp = $evidence['cmp_detection'] ?? null;
            $scripts = $evidence['third_party_scripts'] ?? null;
            $iframes = $evidence['iframe_inventory'] ?? null;
            $secHeaders = $evidence['security_headers'] ?? null;
            $structuredKeys = ['cmp_detection', 'third_party_scripts', 'iframe_inventory', 'security_headers'];
            $otherEvidence = array_diff_key($evidence, array_flip($structuredKeys));
        @endphp

        <div class="space-y-4">
            <h4 class="text-sm font-semibold text-gray-300 uppercase tracking-wide">Enrichment Evidence</h4>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                {{-- CMP Detection Card --}}
                <div class="rounded-xl border p-4 space-y-3
                    {{ $cmp && ($cmp['competing_cmp'] ?? false)
                        ? 'border-amber-500/30 bg-amber-500/5'
                        : ($cmp && ($cmp['ycookies_found'] ?? false)
                            ? 'border-emerald-500/30 bg-emerald-500/5'
                            : 'border-gray-600/40 bg-gray-800/30') }}">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🛡️</span>
                        <h5 class="text-sm font-bold text-white">CMP Detection</h5>
                    </div>
                    @if($cmp)
                        @if($cmp['competing_cmp'] ?? false)
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-400">⚠ Competing CMP</span>
                        @elseif($cmp['ycookies_found'] ?? false)
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-400">✓ YCookies Only</span>
                        @else
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-600/30 text-gray-400">No CMP Detected</span>
                        @endif
                        @if(!empty($cmp['detected_cmps']))
                            <div class="space-y-1">
                                @foreach($cmp['detected_cmps'] as $name => $info)
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="font-medium {{ $name === 'YCookies' ? 'text-emerald-400' : 'text-amber-400' }}">{{ $name }}</span>
                                        <span class="text-gray-500">{{ $info['signatures'] ?? $info['matches'] ?? 0 }} match{{ (($info['signatures'] ?? $info['matches'] ?? 0)) !== 1 ? 'es' : '' }} · {{ $info['confidence'] ?? 'low' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="text-xs text-gray-500 italic">No data available</p>
                    @endif
                </div>

                {{-- Third-Party Scripts Card --}}
                <div class="rounded-xl border border-gray-600/40 bg-gray-800/30 p-4 space-y-3">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">📜</span>
                        <h5 class="text-sm font-bold text-white">Third-Party Scripts</h5>
                    </div>
                    @if($scripts)
                        <div class="flex gap-3 text-center">
                            <div>
                                <div class="text-xl font-bold text-white">{{ $scripts['total_scripts'] ?? 0 }}</div>
                                <div class="text-[10px] text-gray-500 uppercase">Total</div>
                            </div>
                            <div>
                                <div class="text-xl font-bold text-emerald-400">{{ $scripts['first_party'] ?? 0 }}</div>
                                <div class="text-[10px] text-gray-500 uppercase">1st Party</div>
                            </div>
                            <div>
                                <div class="text-xl font-bold {{ ($scripts['third_party'] ?? 0) > 30 ? 'text-amber-400' : 'text-blue-400' }}">{{ $scripts['third_party'] ?? 0 }}</div>
                                <div class="text-[10px] text-gray-500 uppercase">3rd Party</div>
                            </div>
                        </div>
                        @if(!empty($scripts['by_category']))
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($scripts['by_category'] as $cat => $count)
                                    @php
                                        $catColor = match($cat) {
                                            'analytics' => 'bg-blue-500/20 text-blue-400',
                                            'advertising' => 'bg-red-500/20 text-red-400',
                                            'social' => 'bg-pink-500/20 text-pink-400',
                                            'tag_manager' => 'bg-violet-500/20 text-violet-400',
                                            'cdn' => 'bg-cyan-500/20 text-cyan-400',
                                            'performance' => 'bg-emerald-500/20 text-emerald-400',
                                            'chat' => 'bg-orange-500/20 text-orange-400',
                                            default => 'bg-gray-600/20 text-gray-400',
                                        };
                                    @endphp
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full font-semibold {{ $catColor }}">{{ str_replace('_', ' ', $cat) }}: {{ $count }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if(!empty($scripts['vendors']))
                            <div class="space-y-0.5">
                                @foreach(array_slice($scripts['vendors'], 0, 5) as $v)
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-gray-300 truncate">{{ $v['domain'] ?? '' }}</span>
                                        <span class="text-gray-500 shrink-0 ml-2">{{ $v['count'] ?? 0 }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="text-xs text-gray-500 italic">No data available</p>
                    @endif
                </div>

                {{-- Iframe Inventory Card --}}
                <div class="rounded-xl border border-gray-600/40 bg-gray-800/30 p-4 space-y-3">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🖼️</span>
                        <h5 class="text-sm font-bold text-white">Iframe Inventory</h5>
                    </div>
                    @if($iframes)
                        <div class="flex gap-3 text-center">
                            <div>
                                <div class="text-xl font-bold text-white">{{ $iframes['total'] ?? 0 }}</div>
                                <div class="text-[10px] text-gray-500 uppercase">Total</div>
                            </div>
                            <div>
                                <div class="text-xl font-bold text-blue-400">{{ $iframes['with_src'] ?? 0 }}</div>
                                <div class="text-[10px] text-gray-500 uppercase">With Src</div>
                            </div>
                            <div>
                                <div class="text-xl font-bold text-violet-400">{{ $iframes['lazy_loaded'] ?? 0 }}</div>
                                <div class="text-[10px] text-gray-500 uppercase">Lazy</div>
                            </div>
                        </div>
                        @if(!empty($iframes['sources']))
                            <div class="space-y-1">
                                @foreach($iframes['sources'] as $src)
                                    @php
                                        $srcColor = match($src['category'] ?? '') {
                                            'video' => 'text-red-400',
                                            'maps' => 'text-emerald-400',
                                            'social' => 'text-pink-400',
                                            'advertising' => 'text-amber-400',
                                            'payment' => 'text-violet-400',
                                            'security' => 'text-cyan-400',
                                            default => 'text-gray-400',
                                        };
                                    @endphp
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="truncate {{ $srcColor }}">{{ $src['domain'] ?? '' }}</span>
                                        <span class="text-gray-500 shrink-0 ml-2">{{ $src['category'] ?? 'other' }} · {{ $src['count'] ?? 0 }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @if(!empty($iframes['notable']))
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($iframes['notable'] as $domain => $cat)
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full font-semibold bg-amber-500/15 text-amber-400">{{ $domain }}</span>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="text-xs text-gray-500 italic">No data available</p>
                    @endif
                </div>

                {{-- Security Headers Card --}}
                <div class="rounded-xl border p-4 space-y-3
                    {{ $secHeaders
                        ? (($secHeaders['score'] ?? 0) >= 8
                            ? 'border-emerald-500/30 bg-emerald-500/5'
                            : (($secHeaders['score'] ?? 0) >= 5
                                ? 'border-amber-500/30 bg-amber-500/5'
                                : 'border-red-500/30 bg-red-500/5'))
                        : 'border-gray-600/40 bg-gray-800/30' }}">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🔒</span>
                        <h5 class="text-sm font-bold text-white">Security Headers</h5>
                    </div>
                    @if($secHeaders)
                        @php $headerScore = $secHeaders['score'] ?? 0; @endphp
                        <div class="flex items-center gap-3">
                            <div class="relative w-12 h-12 flex items-center justify-center">
                                <svg class="w-12 h-12 -rotate-90" viewBox="0 0 36 36">
                                    <circle cx="18" cy="18" r="15" fill="none" stroke="#374151" stroke-width="3"/>
                                    <circle cx="18" cy="18" r="15" fill="none"
                                        stroke="{{ $headerScore >= 8 ? '#10b981' : ($headerScore >= 5 ? '#f59e0b' : '#ef4444') }}"
                                        stroke-width="3" stroke-dasharray="{{ $headerScore * 9.42 }} 94.2"
                                        stroke-linecap="round"/>
                                </svg>
                                <span class="absolute text-xs font-bold {{ $headerScore >= 8 ? 'text-emerald-400' : ($headerScore >= 5 ? 'text-amber-400' : 'text-red-400') }}">{{ $headerScore }}/10</span>
                            </div>
                            <div class="text-xs text-gray-400">
                                <span class="text-emerald-400 font-semibold">{{ $secHeaders['present_count'] ?? 0 }}</span> present,
                                <span class="text-red-400 font-semibold">{{ $secHeaders['missing_count'] ?? 0 }}</span> missing
                            </div>
                        </div>

                        @if(!empty($secHeaders['findings']))
                            <div class="space-y-1.5">
                                @foreach($secHeaders['findings'] as $headerKey => $finding)
                                    <div class="flex items-start gap-2 text-xs">
                                        <span class="mt-0.5">
                                            @if(($finding['status'] ?? '') === 'good')
                                                <span class="text-emerald-400">✓</span>
                                            @elseif(($finding['status'] ?? '') === 'weak')
                                                <span class="text-amber-400">⚠</span>
                                            @else
                                                <span class="text-red-400">✗</span>
                                            @endif
                                        </span>
                                        <div>
                                            <span class="text-gray-300 font-medium">{{ str_replace('_', '-', $headerKey) }}</span>
                                            @if(!empty($finding['value']))
                                                <span class="text-gray-500 ml-1 break-all">{{ Str::limit($finding['value'], 80) }}</span>
                                            @endif
                                            @if(!empty($finding['note']))
                                                <span class="text-gray-500 italic ml-1">{{ $finding['note'] }}</span>
                                            @endif
                                            @if(!empty($finding['recommendation']))
                                                <p class="text-amber-400/70 mt-0.5">↳ {{ $finding['recommendation'] }}</p>
                                            @endif
                                            @if(!empty($finding['dangers']))
                                                @foreach($finding['dangers'] as $danger)
                                                    <p class="text-red-400/70 mt-0.5">⚠ {{ $danger }}</p>
                                                @endforeach
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="text-xs text-gray-500 italic">No data available</p>
                    @endif
                </div>

            </div>
        </div>

        {{-- Other evidence (collapsible raw JSON) --}}
        @if(!empty($otherEvidence))
            <div x-data="{ showRaw: false }">
                <button @click="showRaw = !showRaw" class="text-xs text-gray-500 hover:text-gray-300 flex items-center gap-1.5 transition">
                    <span x-text="showRaw ? '▼' : '▶'"></span>
                    Other Evidence ({{ count($otherEvidence) }} keys)
                </button>
                <pre x-show="showRaw" x-transition class="bg-gray-900 rounded-lg p-3 text-xs text-gray-300 overflow-x-auto max-h-48 mt-2">{{ json_encode($otherEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        @endif
    @endif

    {{-- Response Times --}}
    @if(!empty($viewingResultData['response_times']))
        <div>
            <h4 class="text-sm font-semibold text-gray-300 mb-2">Response Times</h4>
            <div class="flex flex-wrap gap-2">
                @foreach($viewingResultData['response_times'] as $checkName => $ms)
                    <span class="text-xs px-2 py-1 rounded bg-gray-700 text-gray-300">
                        {{ str_replace('_', ' ', $checkName) }}: <strong>{{ $ms }}ms</strong>
                    </span>
                @endforeach
            </div>
        </div>
    @endif
</div>
