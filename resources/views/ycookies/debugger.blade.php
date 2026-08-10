<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consent Debugger - {{ $domain ? $domain->name : 'Universal Tag Inspector' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body { background-color: #0d1117; color: #c9d1d9; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        .debugger-panel { background-color: #161b22; border-left: 1px solid #30363d; }
        .log-entry { border-bottom: 1px solid #21262d; }
        .log-entry:hover { background-color: #21262d; }
        .json-key { color: #79c0ff; }
        .json-string { color: #a5d6ff; }
        .json-number { color: #d2a8ff; }
        .json-boolean { color: #ff7b72; }
        
        .consent-granted { color: #3fb950; background: rgba(63, 185, 80, 0.1); border: 1px solid rgba(63, 185, 80, 0.2); }
        .consent-denied { color: #f85149; background: rgba(248, 81, 73, 0.1); border: 1px solid rgba(248, 81, 73, 0.2); }
        .consent-waiting { color: #d29922; background: rgba(210, 153, 34, 0.1); border: 1px solid rgba(210, 153, 34, 0.2); }
        
        /* Pixel brand colors */
        .pixel-meta { background: rgba(24, 119, 242, 0.1); border-color: rgba(24, 119, 242, 0.3); }
        .pixel-tiktok { background: rgba(255, 0, 80, 0.1); border-color: rgba(255, 0, 80, 0.3); }
        .pixel-linkedin { background: rgba(10, 102, 194, 0.1); border-color: rgba(10, 102, 194, 0.3); }
        .pixel-pinterest { background: rgba(230, 0, 35, 0.1); border-color: rgba(230, 0, 35, 0.3); }
        .pixel-twitter { background: rgba(29, 155, 240, 0.1); border-color: rgba(29, 155, 240, 0.3); }
        .pixel-snapchat { background: rgba(255, 252, 0, 0.1); border-color: rgba(255, 252, 0, 0.3); }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #484f58; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #6e7681; }
    </style>
</head>
<body class="h-screen flex flex-col overflow-hidden" x-data="consentDebugger()">
    <!-- Header -->
    <header class="h-14 bg-[#161b22] border-b border-[#30363d] flex items-center justify-between px-4 shrink-0">
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2 text-[#e6edf3] font-semibold">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
                {{ $mode === 'universal' ? 'Universal Tag Inspector' : 'Consent Debugger' }}
            </div>
            <div class="h-4 w-px bg-[#30363d]"></div>
            <div class="text-sm text-[#8b949e] flex items-center gap-2">
                @if($mode === 'universal')
                    Debugging: <span class="text-[#e6edf3] font-medium truncate max-w-[300px]" title="{{ $externalUrl }}">{{ parse_url($externalUrl, PHP_URL_HOST) }}</span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-[#a371f7]/10 text-[#a371f7] border border-[#a371f7]/20 tracking-wide uppercase">Universal</span>
                @else
                    Debugging: <span class="text-[#e6edf3] font-medium">{{ $domain->name }}</span>
                    @if($mode === 'live')
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-[#f85149]/10 text-[#f85149] border border-[#f85149]/20 tracking-wide uppercase">Live Website</span>
                    @else
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-[#58a6ff]/10 text-[#58a6ff] border border-[#58a6ff]/20 tracking-wide uppercase">Test Page</span>
                    @endif
                @endif
            </div>
        </div>
        
        <div class="flex items-center gap-3">
            <!-- Action Buttons -->
            <button @click="reloadIframe(false)" class="text-xs text-[#8b949e] hover:text-[#e6edf3] flex items-center gap-1 transition-colors px-2 py-1 bg-[#21262d] rounded border border-[#30363d] hover:border-[#8b949e]" title="Reload Website">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 1 0 2.1-5.7L2 8"/></svg>
                Reload
            </button>
            <button @click="reloadIframe(true)" class="text-xs text-[#8b949e] hover:text-[#f85149] flex items-center gap-1 transition-colors px-2 py-1 bg-[#21262d] rounded border border-[#30363d] hover:border-[#f85149]" title="Clear LocalStorage/Cookies & Reload">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2M10 11v6M14 11v6"/></svg>
                Clear & Reload
            </button>

            <!-- Connection Status -->
            <div class="flex items-center gap-2 text-xs font-mono bg-[#0d1117] border border-[#30363d] rounded-full px-3 py-1">
                <span class="relative flex h-2 w-2">
                  <span x-show="connected" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-2 w-2" :class="connected ? 'bg-green-500' : 'bg-red-500'"></span>
                </span>
                <span x-text="connected ? 'Connected' : 'Waiting for connection...'" :class="connected ? 'text-green-400' : 'text-[#8b949e]'"></span>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="flex flex-1 overflow-hidden relative">
        <!-- Connecting Overlay -->
        <div x-show="!connected" class="absolute inset-0 flex items-center justify-center bg-[#0d1117]/80 backdrop-blur-sm z-50 transition-opacity">
            <div class="flex flex-col items-center p-6 bg-[#161b22] rounded-xl border border-[#30363d] shadow-2xl">
                <svg class="animate-spin -ml-1 mr-3 h-10 w-10 text-blue-500 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-base font-medium text-[#e6edf3]">{{ $mode === 'universal' ? 'Fetching & Instrumenting Website...' : 'Connecting to Website...' }}</span>
                <span class="text-xs text-[#8b949e] mt-1">{{ $mode === 'universal' ? 'Injecting pixel interception hooks' : 'Waiting for Consent Mode initialization' }}</span>
            </div>
        </div>

        <!-- Preview Iframe (Left) -->
        <div class="flex-1 relative bg-white">
            <iframe 
                id="preview-iframe"
                @if($mode === 'universal')
                    src="{{ route('ycookies.proxy-debug', ['url' => $externalUrl]) }}"
                @elseif($mode === 'live')
                    src="{{ $domain->url ?? '' }}"
                @else
                    src="{{ route('ycookies.preview', ['site_id' => $siteId]) }}"
                @endif
                class="w-full h-full border-0"
                title="Site Preview"
                sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-storage-access-by-user-activation">
            </iframe>
        </div>

        <!-- Debugger Sidebar (Right) -->
        <div class="w-[450px] md:w-[500px] debugger-panel flex flex-col shrink-0 relative z-20 shadow-2xl">
            <!-- Tabs -->
            <div class="flex border-b border-[#30363d] bg-[#161b22] px-2 pt-2 gap-1 shrink-0 overflow-x-auto">
                <button @click="activeTab = 'events'" class="px-3 py-2 text-sm font-medium border-b-2 transition-colors pb-1.5 focus:outline-none whitespace-nowrap"
                    :class="activeTab === 'events' ? 'border-[#58a6ff] text-[#e6edf3]' : 'border-transparent text-[#8b949e] hover:text-[#c9d1d9] hover:border-[#8b949e]'">
                    <div class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                        </svg>
                        Timeline
                        <span x-show="logs.length > 0" class="ml-1 bg-[#30363d] text-[#e6edf3] text-[10px] px-1.5 py-0.5 rounded-full" x-text="logs.length"></span>
                    </div>
                </button>
                <button @click="activeTab = 'pixels'" class="px-3 py-2 text-sm font-medium border-b-2 transition-colors pb-1.5 focus:outline-none whitespace-nowrap"
                    :class="activeTab === 'pixels' ? 'border-[#a371f7] text-[#e6edf3]' : 'border-transparent text-[#8b949e] hover:text-[#c9d1d9] hover:border-[#8b949e]'">
                    <div class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Pixels
                        <span x-show="Object.keys(detectedPixels).length > 0" class="ml-1 bg-[#a371f7]/20 text-[#a371f7] text-[10px] px-1.5 py-0.5 rounded-full" x-text="Object.keys(detectedPixels).length"></span>
                    </div>
                </button>
                <button @click="activeTab = 'network'" class="px-3 py-2 text-sm font-medium border-b-2 transition-colors pb-1.5 focus:outline-none whitespace-nowrap"
                    :class="activeTab === 'network' ? 'border-[#f97316] text-[#e6edf3]' : 'border-transparent text-[#8b949e] hover:text-[#c9d1d9] hover:border-[#8b949e]'">
                    <div class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Network
                        <span x-show="networkLogs.length > 0" class="ml-1 bg-[#f97316]/20 text-[#f97316] text-[10px] px-1.5 py-0.5 rounded-full" x-text="networkLogs.length"></span>
                    </div>
                </button>
                <button @click="activeTab = 'consent'" class="px-3 py-2 text-sm font-medium border-b-2 transition-colors pb-1.5 focus:outline-none whitespace-nowrap"
                    :class="activeTab === 'consent' ? 'border-[#58a6ff] text-[#e6edf3]' : 'border-transparent text-[#8b949e] hover:text-[#c9d1d9] hover:border-[#8b949e]'">
                    <div class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        Consent State
                    </div>
                </button>
            </div>

            <!-- Events/Timeline Tab -->
            <div x-show="activeTab === 'events'" class="flex-1 overflow-y-auto flex flex-col">
                <div class="flex items-center justify-between p-2 border-b border-[#30363d] bg-[#0d1117] shrink-0 sticky top-0 z-10 shadow-sm">
                    <span class="text-xs font-mono text-[#8b949e] pl-2">All Events</span>
                    <button @click="clearLogs()" class="text-xs px-2 py-1 rounded hover:bg-[#21262d] text-[#8b949e] hover:text-[#e6edf3] flex items-center gap-1 transition-colors outline-none focus:ring-1 focus:ring-[#58a6ff]">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Clear
                    </button>
                </div>
                
                <div class="flex-1 overflow-y-auto p-2 space-y-2 pb-6">
                    <div x-show="logs.length === 0" class="flex flex-col items-center justify-center h-40 text-[#8b949e]">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mb-2 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p class="text-sm">Waiting for events...</p>
                    </div>

                    <template x-for="(log, index) in logs" :key="log.id">
                        <div class="rounded border border-[#30363d] bg-[#0d1117] overflow-hidden shadow-sm" x-data="{ expanded: index === 0 }">
                            <div @click="expanded = !expanded" class="px-3 py-2.5 cursor-pointer flex items-center justify-between hover:bg-[#161b22] border-l-4 transition-colors"
                                :class="getBorderColor(log.type)">
                                <div class="flex items-center gap-3 overflow-hidden flex-1">
                                    <span class="text-[10px] text-[#8b949e] font-mono shrink-0" x-text="log.time"></span>
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide shrink-0" :class="getBadgeClass(log.type)" x-text="getBadgeLabel(log.type)"></span>
                                    <span class="text-[13px] font-medium truncate text-[#e6edf3]" x-text="log.title"></span>
                                </div>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-[#8b949e] shrink-0 transition-transform ml-2" :class="expanded ? 'rotate-180 text-[#e6edf3]' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </div>
                            <div x-show="expanded" class="border-t border-[#30363d] bg-[#0d1117] relative group">
                                <button @click.stop="copyJson(log.payload, $event)" class="absolute top-2 right-2 p-1.5 bg-[#21262d] border border-[#30363d] rounded text-[#8b949e] hover:text-[#e6edf3] hover:bg-[#30363d] transition-colors opacity-0 group-hover:opacity-100" title="Copy JSON">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                </button>
                                <div class="text-[13px] font-mono overflow-x-auto">
                                    <pre class="p-3 m-0"><code x-html="formatJson(log.payload)"></code></pre>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Pixels Tab -->
            <div x-show="activeTab === 'pixels'" class="flex-1 overflow-y-auto p-5 bg-[#0d1117] pb-10">
                <div class="mb-6 flex flex-col gap-1">
                    <h3 class="text-sm font-semibold text-[#e6edf3]">Detected Tracking Pixels</h3>
                    <p class="text-xs text-[#8b949e]">All tracking pixels and SDKs detected on the page.</p>
                </div>

                <div x-show="Object.keys(detectedPixels).length === 0" class="flex flex-col items-center justify-center h-40 text-[#8b949e]">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mb-2 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <p class="text-sm">Scanning for pixels...</p>
                </div>

                <div class="space-y-3">
                    <template x-for="(pixel, name) in detectedPixels" :key="name">
                        <div class="rounded-lg border border-[#30363d] bg-[#161b22] overflow-hidden shadow-sm" x-data="{ open: true }">
                            <div @click="open = !open" class="px-4 py-3 cursor-pointer flex items-center justify-between hover:bg-[#21262d] transition-colors">
                                <div class="flex items-center gap-3">
                                    <span class="w-8 h-8 rounded-lg flex items-center justify-center text-sm font-bold" :class="getPixelBgClass(name)" x-text="getPixelIcon(name)"></span>
                                    <div>
                                        <div class="text-sm font-semibold text-[#e6edf3]" x-text="getPixelDisplayName(name)"></div>
                                        <div class="text-[11px] text-[#8b949e]" x-text="pixel.events + ' events captured'"></div>
                                    </div>
                                </div>
                                <span class="bg-[#30363d] text-[#e6edf3] text-xs px-2 py-0.5 rounded-full font-mono" x-text="pixel.events"></span>
                            </div>
                            <div x-show="open" class="border-t border-[#21262d] px-4 py-3 space-y-2">
                                <template x-for="(evt, idx) in pixel.lastEvents" :key="idx">
                                    <div class="text-[12px] font-mono text-[#8b949e] bg-[#0d1117] rounded px-3 py-2 border border-[#21262d]">
                                        <span class="text-[#79c0ff]" x-text="evt.title"></span>
                                        <span class="text-[10px] text-[#484f58] ml-2" x-text="evt.time"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Network Tab -->
            <div x-show="activeTab === 'network'" class="flex-1 overflow-y-auto flex flex-col">
                <div class="flex items-center justify-between p-2 border-b border-[#30363d] bg-[#0d1117] shrink-0 sticky top-0 z-10 shadow-sm">
                    <span class="text-xs font-mono text-[#8b949e] pl-2">Pixel Network Requests</span>
                    <span class="text-xs text-[#8b949e] pr-2" x-text="networkLogs.length + ' requests'"></span>
                </div>
                
                <div class="flex-1 overflow-y-auto p-2 space-y-1 pb-6">
                    <div x-show="networkLogs.length === 0" class="flex flex-col items-center justify-center h-40 text-[#8b949e]">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 mb-2 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        <p class="text-sm">Monitoring network requests...</p>
                    </div>

                    <template x-for="(req, index) in networkLogs" :key="req.id">
                        <div class="rounded border border-[#21262d] bg-[#0d1117] overflow-hidden shadow-sm" x-data="{ expanded: false }">
                            <div @click="expanded = !expanded" class="px-3 py-2 cursor-pointer flex items-center justify-between hover:bg-[#161b22] transition-colors gap-2">
                                <div class="flex items-center gap-2 overflow-hidden flex-1 min-w-0">
                                    <span class="text-[10px] text-[#8b949e] font-mono shrink-0" x-text="req.time"></span>
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide shrink-0" :class="getBadgeClass(req.source)" x-text="getBadgeLabel(req.source)"></span>
                                    <span class="text-[12px] font-mono truncate text-[#8b949e]" x-text="req.title"></span>
                                </div>
                                <span class="text-[10px] text-[#8b949e] font-mono shrink-0" x-text="req.payload.duration || ''"></span>
                            </div>
                            <div x-show="expanded" class="border-t border-[#21262d] bg-[#0d1117]">
                                <div class="text-[12px] font-mono overflow-x-auto">
                                    <pre class="p-3 m-0"><code x-html="formatJson(req.payload)"></code></pre>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Consent State Tab -->
            <div x-show="activeTab === 'consent'" class="flex-1 overflow-y-auto p-5 bg-[#0d1117] pb-10">
                <div class="mb-6 flex flex-col gap-1">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-[#e6edf3]">Google Consent Mode v2</h3>
                        <div class="text-[10px] text-[#8b949e] bg-[#161b22] border border-[#30363d] px-2 py-0.5 rounded-full flex items-center gap-1.5 shadow-inner" title="Tracks the active state of Google Consent Mode">
                            <span class="relative flex h-1.5 w-1.5">
                              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#58a6ff] opacity-75"></span>
                              <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-[#58a6ff]"></span>
                            </span>
                            Tracking Live
                        </div>
                    </div>
                    <p class="text-xs text-[#8b949e]">These parameters reflect the current consent state applied in the active session.</p>
                </div>

                <div class="space-y-3">
                    <template x-for="(status, param) in consentState" :key="param">
                        <div class="flex items-center justify-between px-4 py-3 rounded-lg border shadow-sm transition-colors duration-300"
                             :class="{
                               'consent-granted': status === 'granted',
                               'consent-denied': status === 'denied',
                               'consent-waiting border-[#30363d] bg-[#161b22]': status === 'waiting'
                             }">
                            <div class="flex items-center gap-3">
                                <svg x-show="status === 'granted'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 opacity-80" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <svg x-show="status === 'denied'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 opacity-80" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                                <svg x-show="status === 'waiting'" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                
                                <div class="text-[13px] font-medium font-mono tracking-tight" :class="status === 'waiting' ? 'text-[#e6edf3]' : ''" x-text="param"></div>
                            </div>
                            <div class="text-[11px] font-bold uppercase tracking-wider px-2.5 py-1 rounded shadow-sm"
                                 :class="{
                                     'bg-[#3fb950] text-[#0d1117]': status === 'granted',
                                     'bg-[#f85149] text-[#0d1117]': status === 'denied',
                                     'text-[#8b949e] border border-[#30363d]': status === 'waiting'
                                 }" x-text="status">
                            </div>
                        </div>
                    </template>
                </div>
                
                <div class="mt-8 pt-6 border-t border-[#30363d] space-y-3">
                    <h3 class="text-xs font-semibold text-[#8b949e] uppercase tracking-wider mb-3">Other Properties</h3>
                    
                    <div class="flex items-center justify-between px-3 py-2 bg-[#161b22] border border-[#30363d] rounded-lg shadow-sm">
                        <div class="text-xs font-mono text-[#e6edf3]">wait_for_update</div>
                        <div class="text-xs font-mono text-[#d2a8ff]" x-text="otherState.wait_for_update + 'ms'"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function consentDebugger() {
            return {
                connected: false,
                activeTab: 'events',
                logs: [],
                networkLogs: [],
                detectedPixels: {},
                consentState: {
                    'ad_storage': 'waiting',
                    'analytics_storage': 'waiting',
                    'ad_user_data': 'waiting',
                    'ad_personalization': 'waiting',
                    'personalization_storage': 'waiting',
                    'functionality_storage': 'waiting',
                    'security_storage': 'waiting'
                },
                otherState: {
                    'wait_for_update': 500
                },

                init() {
                    window.addEventListener('message', (event) => {
                        if (event.data && event.data.type === 'ycookies_preview_ready') {
                            this.connected = true;
                            this.addSystemLog('Debugger Connected', 'Intercepting all tags & pixels...');
                        } else if (event.data && event.data.type === 'ycookies_debugger_event') {
                            if (!this.connected) this.connected = true;
                            this.processIncomingEvent(event.data);
                        }
                    });
                    
                    setTimeout(() => {
                        if (!this.connected) this.connected = true;
                    }, 4000);
                },

                reloadIframe(clearData = false) {
                    this.connected = false;
                    this.logs = [];
                    this.networkLogs = [];
                    this.detectedPixels = {};
                    
                    // Reset consent state to waiting
                    for (let key in this.consentState) {
                        this.consentState[key] = 'waiting';
                    }
                    
                    const iframe = document.getElementById('preview-iframe');
                    if (iframe) {
                        let src = iframe.src;
                        // Strip existing clear_data or cb parameters
                        src = src.replace(/([&?])clear_data=1&?/, '$1').replace(/[&?]$/, '');
                        src = src.replace(/([&?])cb=\d+&?/, '$1').replace(/[&?]$/, '');
                        
                        // Add cache buster
                        src += (src.includes('?') ? '&' : '?') + 'cb=' + Date.now();
                        
                        // Add clear_data if requested
                        if (clearData) {
                            src += '&clear_data=1';
                        }
                        
                        iframe.src = src;
                    }
                },

                processIncomingEvent(data) {
                    const payload = data.payload;
                    const source = data.source || null;
                    const customTitle = data.title || null;
                    const now = new Date();
                    const time = `${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}:${now.getSeconds().toString().padStart(2, '0')}.${now.getMilliseconds().toString().padStart(3, '0')}`;
                    
                    let type = source || 'datalayer';
                    let title = customTitle || 'dataLayer.push()';
                    let cleanPayload = payload;

                    // Handle network requests separately
                    if (type === 'network') {
                        this.networkLogs.unshift({
                            id: Date.now() + Math.random().toString(36).substr(2, 9),
                            time, type, title, source: payload.source || type, payload: cleanPayload
                        });
                        if (this.networkLogs.length > 200) this.networkLogs.length = 200;
                        
                        // Track pixel detection
                        const pixelSource = payload.source || type;
                        this.trackPixel(pixelSource, title, time);
                        return;
                    }

                    // Consent events
                    if (type === 'consent') {
                        if (Array.isArray(payload) && payload[0] === 'consent') {
                            const action = payload[1];
                            type = action === 'default' ? 'consent-default' : 'consent-update';
                            title = customTitle || `gtag('consent', '${action}')`;
                            cleanPayload = payload.length > 2 ? payload[2] : {};
                            this.updateConsentState(cleanPayload);
                        } else if (typeof payload === 'object' && !Array.isArray(payload)) {
                            type = 'consent-update';
                            this.updateConsentState(payload);
                        }
                    }
                    
                    // Legacy format (from preview-iframe without source field)
                    if (!source) {
                        if (Array.isArray(payload) && payload[0] === 'consent') {
                            const action = payload[1];
                            type = action === 'default' ? 'consent-default' : 'consent-update';
                            title = `gtag('consent', '${action}')`;
                            cleanPayload = payload.length > 2 ? payload[2] : {};
                            this.updateConsentState(cleanPayload);
                        } else if (payload && payload.event) {
                            title = `Event: ${payload.event}`;
                            if (payload.event.includes('fbq') || payload.event === 'fbq_event') type = 'meta';
                            else if (payload.event.startsWith('gtm.')) type = 'gtm';
                        } else if (Array.isArray(payload) && payload[0] === 'set') {
                            type = 'system';
                            title = `gtag('set')`;
                        }
                    }

                    // Track pixel detection
                    if (['meta', 'tiktok', 'linkedin', 'pinterest', 'twitter', 'snapchat', 'gtag'].includes(type)) {
                        this.trackPixel(type, title, time);
                    }

                    this.logs.unshift({
                        id: Date.now() + Math.random().toString(36).substr(2, 9),
                        time, type, title, payload: cleanPayload
                    });

                    if (this.logs.length > 200) this.logs.length = 200;
                },

                trackPixel(source, title, time) {
                    if (!this.detectedPixels[source]) {
                        this.detectedPixels[source] = { events: 0, lastEvents: [] };
                    }
                    this.detectedPixels[source].events++;
                    this.detectedPixels[source].lastEvents.unshift({ title, time });
                    if (this.detectedPixels[source].lastEvents.length > 5) {
                        this.detectedPixels[source].lastEvents.length = 5;
                    }
                },

                updateConsentState(params) {
                    for (const key in params) {
                        if (this.consentState.hasOwnProperty(key)) {
                            this.consentState[key] = params[key];
                        } else if (this.otherState.hasOwnProperty(key)) {
                            this.otherState[key] = params[key];
                        }
                    }
                },

                // ── UI Helpers ──
                getBorderColor(type) {
                    const map = {
                        'datalayer': 'border-[#58a6ff]',
                        'consent-update': 'border-[#3fb950]',
                        'consent-default': 'border-[#d29922]',
                        'system': 'border-[#8b949e]',
                        'meta': 'border-[#1877f2]',
                        'tiktok': 'border-[#ff0050]',
                        'linkedin': 'border-[#0a66c2]',
                        'pinterest': 'border-[#e60023]',
                        'twitter': 'border-[#1d9bf0]',
                        'snapchat': 'border-[#fffc00]',
                        'gtag': 'border-[#fbbc04]',
                        'gtm': 'border-[#f59e0b]',
                        'network': 'border-[#f97316]',
                        'google-analytics': 'border-[#e37400]',
                        'google-ads': 'border-[#4285f4]',
                        'bing': 'border-[#008373]',
                        'clarity': 'border-[#6c2bd9]',
                        'hotjar': 'border-[#fd3a5c]',
                    };
                    return map[type] || 'border-[#8b949e]';
                },

                getBadgeClass(type) {
                    const map = {
                        'datalayer': 'bg-[#58a6ff]/10 text-[#58a6ff] border border-[#58a6ff]/20',
                        'consent-update': 'bg-[#3fb950]/10 text-[#3fb950] border border-[#3fb950]/20',
                        'consent-default': 'bg-[#d29922]/10 text-[#d29922] border border-[#d29922]/20',
                        'system': 'bg-[#8b949e]/10 text-[#8b949e] border border-[#8b949e]/20',
                        'meta': 'bg-[#1877f2]/10 text-[#60a5fa] border border-[#1877f2]/30',
                        'tiktok': 'bg-[#ff0050]/10 text-[#ff6b8a] border border-[#ff0050]/30',
                        'linkedin': 'bg-[#0a66c2]/10 text-[#60a5fa] border border-[#0a66c2]/30',
                        'pinterest': 'bg-[#e60023]/10 text-[#f85149] border border-[#e60023]/30',
                        'twitter': 'bg-[#1d9bf0]/10 text-[#58a6ff] border border-[#1d9bf0]/30',
                        'snapchat': 'bg-[#fffc00]/10 text-[#fbbf24] border border-[#fffc00]/30',
                        'gtag': 'bg-[#fbbc04]/10 text-[#fbbf24] border border-[#fbbc04]/30',
                        'gtm': 'bg-[#f59e0b]/10 text-[#fbbf24] border border-[#f59e0b]/30',
                        'network': 'bg-[#f97316]/10 text-[#fb923c] border border-[#f97316]/30',
                        'google-analytics': 'bg-[#e37400]/10 text-[#fb923c] border border-[#e37400]/30',
                        'google-ads': 'bg-[#4285f4]/10 text-[#60a5fa] border border-[#4285f4]/30',
                        'bing': 'bg-[#008373]/10 text-[#34d399] border border-[#008373]/30',
                        'clarity': 'bg-[#6c2bd9]/10 text-[#a371f7] border border-[#6c2bd9]/30',
                        'hotjar': 'bg-[#fd3a5c]/10 text-[#f85149] border border-[#fd3a5c]/30',
                    };
                    return map[type] || 'bg-[#8b949e]/10 text-[#8b949e] border border-[#8b949e]/20';
                },

                getBadgeLabel(type) {
                    const map = {
                        'datalayer': 'DataLayer', 'consent-update': 'Update', 'consent-default': 'Default',
                        'system': 'System', 'meta': 'Meta Pixel', 'tiktok': 'TikTok',
                        'linkedin': 'LinkedIn', 'pinterest': 'Pinterest', 'twitter': 'Twitter/X',
                        'snapchat': 'Snapchat', 'gtag': 'gtag', 'gtm': 'GTM', 'network': 'Network',
                        'google-analytics': 'GA', 'google-ads': 'Google Ads', 'bing': 'Bing',
                        'clarity': 'Clarity', 'hotjar': 'Hotjar',
                    };
                    return map[type] || type;
                },

                getPixelDisplayName(name) {
                    const map = {
                        'meta': 'Meta Pixel (Facebook)', 'tiktok': 'TikTok Pixel', 'linkedin': 'LinkedIn Insight Tag',
                        'pinterest': 'Pinterest Tag', 'twitter': 'Twitter / X Pixel', 'snapchat': 'Snapchat Pixel',
                        'gtag': 'Google Tag (gtag.js)', 'gtm': 'Google Tag Manager', 'google-analytics': 'Google Analytics',
                        'google-ads': 'Google Ads', 'bing': 'Microsoft / Bing Ads', 'clarity': 'Microsoft Clarity',
                        'hotjar': 'Hotjar', 'datalayer': 'dataLayer',
                    };
                    return map[name] || name;
                },

                getPixelIcon(name) {
                    const map = {
                        'meta': 'f', 'tiktok': '♪', 'linkedin': 'in', 'pinterest': 'P',
                        'twitter': '𝕏', 'snapchat': '👻', 'gtag': 'G', 'gtm': 'GTM',
                        'google-analytics': 'GA', 'google-ads': 'Ad', 'bing': 'B',
                        'clarity': 'C', 'hotjar': 'H', 'datalayer': 'DL',
                    };
                    return map[name] || '?';
                },

                getPixelBgClass(name) {
                    const map = {
                        'meta': 'bg-[#1877f2]/20 text-[#60a5fa]', 'tiktok': 'bg-[#ff0050]/20 text-[#ff6b8a]',
                        'linkedin': 'bg-[#0a66c2]/20 text-[#60a5fa]', 'pinterest': 'bg-[#e60023]/20 text-[#f85149]',
                        'twitter': 'bg-[#1d9bf0]/20 text-[#58a6ff]', 'snapchat': 'bg-[#fffc00]/20 text-[#fbbf24]',
                        'gtag': 'bg-[#fbbc04]/20 text-[#fbbf24]', 'gtm': 'bg-[#f59e0b]/20 text-[#fbbf24]',
                        'google-analytics': 'bg-[#e37400]/20 text-[#fb923c]', 'google-ads': 'bg-[#4285f4]/20 text-[#60a5fa]',
                        'bing': 'bg-[#008373]/20 text-[#34d399]', 'clarity': 'bg-[#6c2bd9]/20 text-[#a371f7]',
                        'hotjar': 'bg-[#fd3a5c]/20 text-[#f85149]', 'datalayer': 'bg-[#58a6ff]/20 text-[#58a6ff]',
                    };
                    return map[name] || 'bg-[#8b949e]/20 text-[#8b949e]';
                },
                
                clearLogs() { this.logs = []; },
                
                copyJson(payload, event) {
                    const str = JSON.stringify(payload, null, 2);
                    navigator.clipboard.writeText(str).then(() => {
                        const btn = event.currentTarget;
                        const originalHTML = btn.innerHTML;
                        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>';
                        setTimeout(() => { btn.innerHTML = originalHTML; }, 2000);
                    });
                },
                
                addSystemLog(title, message) {
                     const now = new Date();
                     const time = `${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}:${now.getSeconds().toString().padStart(2, '0')}.${now.getMilliseconds().toString().padStart(3, '0')}`;
                     
                     this.logs.unshift({
                        id: Date.now() + Math.random().toString(36).substr(2, 9),
                        time, type: 'system', title, payload: { status: message }
                    });
                },

                formatJson(obj) {
                    if (obj === undefined) return '<span class="text-[#8b949e]">undefined</span>';
                    
                    const json = JSON.stringify(obj, null, 2);
                    if (!json) return '';
                    return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
                        let cls = 'json-number';
                        if (/^"/.test(match)) {
                            if (/:$/.test(match)) {
                                cls = 'json-key';
                                match = match.replace(/"/g, '');
                            } else {
                                cls = 'json-string';
                            }
                        } else if (/true|false/.test(match)) {
                            cls = 'json-boolean';
                        } else if (/null/.test(match)) {
                            cls = 'json-boolean';
                        }
                        return '<span class="' + cls + '">' + match + '</span>';
                    });
                }
            }
        }
    </script>
</body>
</html>
