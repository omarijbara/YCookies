<x-filament-panels::page>
    <div class="space-y-8">

        {{-- Header --}}
        <x-filament::section>
            <div class="flex items-center gap-4">
                <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-amber-500/10 ring-1 ring-amber-500/20">
                    <x-heroicon-o-wrench-screwdriver class="w-6 h-6 text-amber-500" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Developer Tools</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Secret toolbox — only accessible via <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-white/10 text-xs font-mono text-amber-600 dark:text-amber-400">ydev</code> search.</p>
                </div>
            </div>
        </x-filament::section>

        {{-- Action Buttons --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

            {{-- Recompile Assets --}}
            <x-filament::section>
                <div class="flex flex-col items-center text-center space-y-4 py-4 relative">
                    {{-- Env Warning Badge --}}
                    @if(!app()->environment('local'))
                        <div class="absolute -top-2 right-0 bg-rose-500/10 text-rose-400 ring-1 ring-rose-500/20 px-2 py-0.5 rounded text-[10px] uppercase font-bold tracking-wide cursor-help" title="Fails on production without Node.js installed in the container">
                            Node/npx required
                        </div>
                    @endif
                    <div class="flex items-center justify-center w-14 h-14 rounded-full bg-primary-500/10 ring-1 ring-primary-500/20">
                        <x-heroicon-o-arrow-path class="w-7 h-7 text-primary-500" />
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Recompile Assets</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Run <code class="text-xs font-mono">npx vite build</code> to recompile CSS & JS</p>
                    </div>
                    <x-filament::button
                        wire:click="recompileAssets"
                        icon="heroicon-o-arrow-path"
                        wire:loading.attr="disabled"
                        wire:target="recompileAssets"
                    >
                        <span wire:loading.remove wire:target="recompileAssets">Recompile</span>
                        <span wire:loading wire:target="recompileAssets">Building...</span>
                    </x-filament::button>
                </div>
            </x-filament::section>

            {{-- Clear Caches --}}
            <x-filament::section>
                <div class="flex flex-col items-center text-center space-y-4 py-4">
                    <div class="flex items-center justify-center w-14 h-14 rounded-full bg-red-500/10 ring-1 ring-red-500/20">
                        <x-heroicon-o-trash class="w-7 h-7 text-red-500" />
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Clear All Caches</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Config, routes, views & application cache</p>
                    </div>
                    <x-filament::button
                        wire:click="clearAllCaches"
                        color="danger"
                        icon="heroicon-o-trash"
                        wire:loading.attr="disabled"
                        wire:target="clearAllCaches"
                    >
                        <span wire:loading.remove wire:target="clearAllCaches">Clear Caches</span>
                        <span wire:loading wire:target="clearAllCaches">Clearing...</span>
                    </x-filament::button>
                </div>
            </x-filament::section>

            {{-- Run Migrations --}}
            <x-filament::section>
                <div class="flex flex-col items-center text-center space-y-4 py-4">
                    <div class="flex items-center justify-center w-14 h-14 rounded-full bg-emerald-500/10 ring-1 ring-emerald-500/20">
                        <x-heroicon-o-circle-stack class="w-7 h-7 text-emerald-500" />
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Run Migrations</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Execute pending database migrations</p>
                    </div>
                    <x-filament::button
                        wire:click="runMigrations"
                        color="success"
                        icon="heroicon-o-circle-stack"
                        wire:loading.attr="disabled"
                        wire:target="runMigrations"
                    >
                        <span wire:loading.remove wire:target="runMigrations">Migrate</span>
                        <span wire:loading wire:target="runMigrations">Migrating...</span>
                    </x-filament::button>
                </div>
            </x-filament::section>

        </div>

        {{-- Output Console --}}
        @if($buildOutput)
        <x-filament::section>
            <x-slot name="heading">Output</x-slot>
            <pre class="rounded-xl bg-gray-950 p-4 overflow-x-auto ring-1 ring-white/10 max-h-96 text-sm font-mono text-gray-300 leading-relaxed whitespace-pre-wrap">{{ $buildOutput }}</pre>
        </x-filament::section>
        @endif

    </div>
</x-filament-panels::page>
