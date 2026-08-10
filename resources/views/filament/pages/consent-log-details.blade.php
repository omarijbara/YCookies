<div class="space-y-5">
    {{-- Header: UID + Latest Badge --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="text-xs text-gray-400">UID</span>
            <code class="px-3 py-1.5 rounded-lg bg-gray-800 text-sm font-mono text-gray-200 select-all border border-gray-700">
                {{ $log->consent_uid }}
            </code>
        </div>
        @if($log->is_latest)
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-green-500/15 text-green-400 ring-1 ring-green-500/30">
                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                Latest Consent
            </span>
        @else
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-700/50 text-gray-400">
                Superseded
            </span>
        @endif
    </div>

    {{-- Meta Grid --}}
    <div class="grid grid-cols-2 gap-4 p-4 rounded-xl bg-gray-800/40 border border-gray-700/50">
        <div>
            <span class="block text-xs text-gray-500 mb-0.5">Date & Time</span>
            <span class="text-sm text-gray-200">{{ $log->created_at->format('Y-m-d H:i:s') }}</span>
        </div>
        <div>
            <span class="block text-xs text-gray-500 mb-0.5">Cookie Version</span>
            <span class="text-sm text-gray-200 font-semibold">{{ $log->cookie_version ?? 'N/A' }}</span>
        </div>
        <div>
            <span class="block text-xs text-gray-500 mb-0.5">Consent Type</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                {{ match($log->consent_type) {
                    'all' => 'bg-green-500/20 text-green-400',
                    'essential' => 'bg-blue-500/20 text-blue-400',
                    'custom' => 'bg-yellow-500/20 text-yellow-400',
                    'renewed' => 'bg-purple-500/20 text-purple-400',
                    default => 'bg-gray-500/20 text-gray-400',
                } }}">
                {{ ucfirst($log->consent_type) }}
            </span>
        </div>
        <div>
            <span class="block text-xs text-gray-500 mb-0.5">Domain</span>
            <span class="text-sm text-gray-200">{{ $log->domain?->name ?? 'N/A' }}</span>
        </div>
        <div>
            <span class="block text-xs text-gray-500 mb-0.5">IP Hash</span>
            <span class="text-xs text-gray-400 font-mono">{{ Str::limit($log->ip_hash, 24) }}</span>
        </div>
        <div>
            <span class="block text-xs text-gray-500 mb-0.5">User Agent</span>
            <span class="text-xs text-gray-400 truncate block" title="{{ $log->user_agent }}">
                {{ Str::limit($log->user_agent, 60) }}
            </span>
        </div>
    </div>

    {{-- Consent Groups --}}
    @if(is_array($log->consents_granted) && count($log->consents_granted) > 0)
        <div>
            <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Groups</h4>
            <div class="flex flex-wrap gap-2">
                @foreach($log->consents_granted as $group => $granted)
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium
                        {{ $granted ? 'bg-green-500/10 text-green-400 ring-1 ring-green-500/20' : 'bg-red-500/10 text-red-400 ring-1 ring-red-500/20' }}">
                        @if($granted)
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                        @else
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                        @endif
                        {{ ucfirst($group) }}
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Service Consents (grouped by CookieGroup) --}}
    @if(!empty($servicesByGroup))
        <div>
            <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Service Consents</h4>
            <div class="space-y-3">
                @foreach($servicesByGroup as $groupName => $services)
                    <div class="p-3 rounded-lg bg-gray-800/30 border border-gray-700/40">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-2 h-2 rounded-full bg-primary-500"></span>
                            <span class="text-sm font-medium text-gray-300">{{ $groupName }}</span>
                            <span class="text-xs text-gray-500">({{ count($services) }})</span>
                        </div>
                        <div class="flex flex-wrap gap-1.5 pl-4">
                            @foreach($services as $serviceName)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-green-500/10 text-green-400">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                                    {{ $serviceName }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="text-center py-4 text-sm text-gray-500">
            No service-level consent details available for this entry.
        </div>
    @endif
</div>
