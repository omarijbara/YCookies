{{-- History Table — past health check runs --}}
<div>
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-gray-300 uppercase tracking-wide">Check History</h3>
        <div class="flex gap-1.5">
            @foreach(['all' => 'All', 'healthy' => '🟢', 'warning' => '🟡', 'failing' => '🔴'] as $val => $label)
                <button wire:click="$set('historyFilter', '{{ $val }}')"
                        class="text-xs px-2.5 py-1 rounded-lg transition
                        {{ $historyFilter === $val ? 'bg-primary-500/20 text-primary-400 font-semibold' : 'bg-gray-700/50 text-gray-400 hover:bg-gray-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>
    <div class="rounded-xl border border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-800/80 border-b border-gray-700">
                <tr>
                    <th class="text-left px-4 py-2.5 text-gray-400 font-medium">Status</th>
                    <th class="text-left px-4 py-2.5 text-gray-400 font-medium">Source</th>
                    <th class="text-left px-4 py-2.5 text-gray-400 font-medium">Checks</th>
                    <th class="text-left px-4 py-2.5 text-gray-400 font-medium">Duration</th>
                    <th class="text-left px-4 py-2.5 text-gray-400 font-medium">When</th>
                    <th class="text-right px-4 py-2.5 text-gray-400 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-700/50">
                @foreach($checkHistory as $run)
                    @if($historyFilter !== 'all' && ($run['status'] ?? '') !== $historyFilter)
                        @continue
                    @endif
                    <tr class="hover:bg-gray-800/50 transition {{ $viewingResultId === $run['id'] ? 'bg-primary-500/10' : '' }}">
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-semibold
                                {{ match($run['status'] ?? '') {
                                    'healthy' => 'bg-emerald-500/20 text-emerald-400',
                                    'warning' => 'bg-amber-500/20 text-amber-400',
                                    'failing' => 'bg-red-500/20 text-red-400',
                                    default => 'bg-gray-600 text-gray-300'
                                } }}">
                                {{ match($run['status'] ?? '') {
                                    'healthy' => '🟢',
                                    'warning' => '🟡',
                                    'failing' => '🔴',
                                    default => '⚪'
                                } }}
                                {{ ucfirst($run['status'] ?? 'unknown') }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-gray-300">{{ ucfirst($run['source'] ?? '') }}</td>
                        <td class="px-4 py-2.5 text-gray-300">
                            <span class="text-emerald-400">✓{{ $run['checks_passed'] ?? 0 }}</span>
                            @if(($run['checks_warned'] ?? 0) > 0)
                                <span class="text-amber-400 ml-1">⚠{{ $run['checks_warned'] }}</span>
                            @endif
                            @if(($run['checks_failed'] ?? 0) > 0)
                                <span class="text-red-400 ml-1">✗{{ $run['checks_failed'] }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-gray-400">{{ $run['duration_ms'] ?? 0 }}ms</td>
                        <td class="px-4 py-2.5 text-gray-400">
                            {{ isset($run['checked_at']) ? \Carbon\Carbon::parse($run['checked_at'])->diffForHumans() : '' }}
                        </td>
                        <td class="px-4 py-2.5 text-right space-x-2">
                            <button wire:click="viewResult({{ $run['id'] }})"
                                    class="text-primary-400 hover:text-primary-300 text-xs font-medium">
                                View
                            </button>
                            <button wire:click="deleteResult({{ $run['id'] }})"
                                    wire:confirm="Delete this health check result?"
                                    class="text-red-400 hover:text-red-300 text-xs font-medium">
                                Delete
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
