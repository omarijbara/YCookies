<x-filament-panels::page>
    @php
        $colorMap = [
            'primary' => ['from' => '#f59e0b', 'to' => '#d97706'],
            'danger'  => ['from' => '#ef4444', 'to' => '#b91c1c'],
            'warning' => ['from' => '#f59e0b', 'to' => '#d97706'],
            'success' => ['from' => '#22c55e', 'to' => '#15803d'],
            'info'    => ['from' => '#3b82f6', 'to' => '#1d4ed8'],
            'gray'    => ['from' => '#6b7280', 'to' => '#374151'],
            'blue'    => ['from' => '#3b82f6', 'to' => '#1e40af'],
            'indigo'  => ['from' => '#6366f1', 'to' => '#3730a3'],
            'violet'  => ['from' => '#8b5cf6', 'to' => '#5b21b6'],
            'purple'  => ['from' => '#a855f7', 'to' => '#7e22ce'],
            'pink'    => ['from' => '#ec4899', 'to' => '#be185d'],
            'rose'    => ['from' => '#f43f5e', 'to' => '#be123c'],
            'red'     => ['from' => '#ef4444', 'to' => '#b91c1c'],
            'orange'  => ['from' => '#f97316', 'to' => '#c2410c'],
            'amber'   => ['from' => '#f59e0b', 'to' => '#b45309'],
            'yellow'  => ['from' => '#eab308', 'to' => '#a16207'],
            'lime'    => ['from' => '#84cc16', 'to' => '#4d7c0f'],
            'green'   => ['from' => '#22c55e', 'to' => '#15803d'],
            'emerald' => ['from' => '#10b981', 'to' => '#047857'],
            'teal'    => ['from' => '#14b8a6', 'to' => '#0f766e'],
            'cyan'    => ['from' => '#06b6d4', 'to' => '#0e7490'],
            'sky'     => ['from' => '#0ea5e9', 'to' => '#0369a1'],
        ];
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        @foreach($this->getTemplates() as $key => $template)
            @php
                $color = $template['color'] ?? 'primary';
                $gradientFrom = $colorMap[$color]['from'] ?? $colorMap['primary']['from'];
                $gradientTo   = $colorMap[$color]['to']   ?? $colorMap['primary']['to'];
            @endphp

            <div class="group rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 flex flex-col overflow-hidden transition-all duration-300 hover:shadow-xl hover:-translate-y-1 hover:ring-2 hover:ring-white/20">
                {{-- Gradient Header --}}
                <div class="relative h-32 flex items-center justify-center overflow-hidden" style="background: linear-gradient(135deg, {{ $gradientFrom }}, {{ $gradientTo }});">
                    {{-- Decorative elements --}}
                    <div class="absolute -right-8 -top-8 h-28 w-28 rounded-full border-2 border-white/10"></div>
                    <div class="absolute -right-4 -top-4 h-20 w-20 rounded-full border-2 border-white/10"></div>
                    <div class="absolute -left-6 -bottom-6 h-24 w-24 rounded-full border-2 border-white/10"></div>
                    <div class="absolute right-10 bottom-4 h-16 w-16 rounded-full border border-white/[0.07]"></div>

                    {{-- Icon --}}
                    <div class="relative z-10 flex h-16 w-16 items-center justify-center rounded-2xl bg-white/15 backdrop-blur-sm ring-1 ring-white/20 transition-transform duration-300 group-hover:scale-110">
                        <x-filament::icon :icon="$template['icon']" class="h-8 w-8 text-white drop-shadow-md" />
                    </div>
                </div>

                {{-- Body --}}
                <div class="flex flex-1 flex-col p-5">
                    <h3 class="text-base font-bold text-gray-900 dark:text-white leading-snug">
                        {{ $template['name'] }}
                    </h3>
                    <p class="mt-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ $template['provider'] }}
                    </p>
                    <p class="mt-3 flex-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300 line-clamp-3">
                        {{ $template['purpose'] }}
                    </p>
                </div>

                {{-- Metadata Strip --}}
                <div class="grid grid-cols-2 border-t border-gray-100 dark:border-white/5 text-center">
                    <div class="border-r border-gray-100 dark:border-white/5 py-3 px-4">
                        <span class="block text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Cookies</span>
                        <span class="mt-0.5 block text-sm font-bold text-gray-800 dark:text-gray-200">{{ count($template['cookies'] ?? []) }}</span>
                    </div>
                    <div class="py-3 px-4">
                        <span class="block text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Version</span>
                        <span class="mt-0.5 block text-sm font-bold text-gray-800 dark:text-gray-200">{{ $template['version'] ?? '1.0.0' }}</span>
                    </div>
                </div>

                {{-- Action Button --}}
                <button
                    wire:click="mountAction('installTemplate', { template: '{{ $key }}' })"
                    class="w-full py-3 text-sm font-semibold text-white transition-all duration-200 hover:brightness-110 focus:outline-none cursor-pointer"
                    style="background: linear-gradient(135deg, {{ $gradientFrom }}, {{ $gradientTo }});"
                >
                    Add Service
                </button>
            </div>
        @endforeach
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
