<x-filament-widgets::widget>
    <div @class([
        'flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium',
        'bg-blue-500/10 text-blue-400 border border-blue-500/20' => $severity === 'info',
        'bg-amber-500/10 text-amber-400 border border-amber-500/20' => $severity === 'warning',
        'bg-red-500/10 text-red-400 border border-red-500/20' => $severity === 'danger',
    ])>
        <x-dynamic-component :component="$icon" class="h-5 w-5 shrink-0" />
        <span>{{ $message }}</span>
    </div>
</x-filament-widgets::widget>
