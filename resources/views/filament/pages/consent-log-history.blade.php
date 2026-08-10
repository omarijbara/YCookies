<div class="space-y-4">
    @forelse($history as $entry)
        <div class="p-4 rounded-lg border border-gray-700 bg-gray-800/50 {{ $entry->is_latest ? 'ring-2 ring-primary-500' : '' }}">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-white">
                        {{ $entry->created_at->format('M d, Y H:i:s') }}
                    </span>
                    @if($entry->is_latest)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-primary-500/20 text-primary-400">
                            Current
                        </span>
                    @endif
                </div>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                    {{ match($entry->consent_type) {
                        'all' => 'bg-green-500/20 text-green-400',
                        'essential' => 'bg-blue-500/20 text-blue-400',
                        'custom' => 'bg-yellow-500/20 text-yellow-400',
                        'renewed' => 'bg-purple-500/20 text-purple-400',
                        default => 'bg-gray-500/20 text-gray-400',
                    } }}">
                    {{ ucfirst($entry->consent_type) }}
                </span>
            </div>

            <div class="grid grid-cols-2 gap-2 text-sm">
                <div>
                    <span class="text-gray-400">Version:</span>
                    <span class="text-gray-200">{{ $entry->cookie_version ?? 'N/A' }}</span>
                </div>
                <div>
                    <span class="text-gray-400">IP Hash:</span>
                    <span class="text-gray-200 font-mono text-xs">{{ Str::limit($entry->ip_hash, 12) }}</span>
                </div>
            </div>

            @if(is_array($entry->consents_granted) && count($entry->consents_granted) > 0)
                <div class="mt-2">
                    <span class="text-xs text-gray-400">Groups:</span>
                    <div class="flex flex-wrap gap-1 mt-1">
                        @foreach($entry->consents_granted as $group => $granted)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs
                                {{ $granted ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                                {{ $group }}: {{ $granted ? '✓' : '✗' }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if(is_array($entry->services_granted) && count($entry->services_granted) > 0)
                <div class="mt-2">
                    <span class="text-xs text-gray-400">Services:</span>
                    <div class="flex flex-wrap gap-1 mt-1">
                        @foreach($entry->services_granted as $service)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-indigo-500/20 text-indigo-400">
                                {{ $service }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @empty
        <div class="text-center text-gray-400 py-8">
            <p>No consent history found for this UID.</p>
        </div>
    @endforelse
</div>
