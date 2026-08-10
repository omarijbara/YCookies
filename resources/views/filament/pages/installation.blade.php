<x-filament-panels::page>
    @if($activeDomain)

        {{-- Domain Switcher --}}
        @if(count($domainOptions) > 1)
        <x-filament::section>
            <div class="flex items-center gap-4">
                <x-heroicon-o-globe-alt class="w-5 h-5 text-gray-400" />
                <div class="flex items-center gap-3 flex-1">
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('ycookies.installation.domain') }}</span>
                    <select wire:model.live="selectedDomainId"
                        class="rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-sm text-gray-950 dark:text-white py-2 px-3 focus:ring-2 focus:ring-primary-500 dark:[color-scheme:dark]">
                        @foreach($domainOptions as $domainId => $domainName)
                            <option value="{{ $domainId }}">{{ $domainName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('ycookies.installation.site_id') }}</span>
                    <x-filament::badge color="primary">
                        {{ $activeDomain->site_id }}
                    </x-filament::badge>
                </div>
            </div>
        </x-filament::section>
        @else
        <x-filament::section>
            <div class="flex items-center gap-3">
                <x-heroicon-o-globe-alt class="w-5 h-5 text-gray-400" />
                <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $activeDomain->name }}</span>
                <span class="text-gray-300 dark:text-gray-600">·</span>
                <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('ycookies.installation.site_id') }}</span>
                <x-filament::badge color="primary">
                    {{ $activeDomain->site_id }}
                </x-filament::badge>
            </div>
        </x-filament::section>
        @endif

        {{-- Wrapper for shared Alpine state --}}
        <div x-data="{ activeTab: 'script', scriptMode: 'advanced' }">

            {{-- ════════════ METHOD COMPARISON ════════════ --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('ycookies.installation.choose_method') }}</x-slot>
                <x-slot name="description">{{ __('ycookies.installation.choose_method_desc') }}</x-slot>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- Basic Script --}}
                    <button type="button" x-on:click="activeTab = 'script'; scriptMode = 'basic'"
                        :class="activeTab === 'script' && scriptMode === 'basic'
                            ? 'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-500/10'
                            : 'ring-1 ring-gray-200 dark:ring-white/10 hover:ring-gray-300 dark:hover:ring-white/20'"
                        class="rounded-xl p-5 text-start transition-all">
                        <div class="flex items-center gap-2 mb-3">
                            <x-heroicon-s-code-bracket class="w-5 h-5 text-blue-500" />
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('ycookies.installation.compare_basic_title') }}</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('ycookies.installation.compare_basic_desc') }}</p>
                        <div class="space-y-1.5 text-xs">
                            <div class="flex items-center gap-1.5 text-green-600 dark:text-green-400">
                                <x-heroicon-s-check-circle class="w-3.5 h-3.5 shrink-0" />
                                <span>{{ __('ycookies.installation.compare_basic_pro1') }}</span>
                            </div>
                            <div class="flex items-center gap-1.5 text-green-600 dark:text-green-400">
                                <x-heroicon-s-check-circle class="w-3.5 h-3.5 shrink-0" />
                                <span>{{ __('ycookies.installation.compare_basic_pro2') }}</span>
                            </div>
                            <div class="flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
                                <x-heroicon-s-exclamation-triangle class="w-3.5 h-3.5 shrink-0" />
                                <span>{{ __('ycookies.installation.compare_basic_con1') }}</span>
                            </div>
                        </div>
                    </button>

                    {{-- Advanced Script --}}
                    <button type="button" x-on:click="activeTab = 'script'; scriptMode = 'advanced'"
                        :class="activeTab === 'script' && scriptMode === 'advanced'
                            ? 'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-500/10'
                            : 'ring-1 ring-gray-200 dark:ring-white/10 hover:ring-gray-300 dark:hover:ring-white/20'"
                        class="rounded-xl p-5 text-start transition-all relative">
                        <span class="absolute -top-2 right-3 inline-flex items-center rounded-full bg-green-100 dark:bg-green-500/20 px-2 py-0.5 text-[10px] font-semibold text-green-700 dark:text-green-400 ring-1 ring-inset ring-green-600/20">{{ __('ycookies.installation.recommended') }}</span>
                        <div class="flex items-center gap-2 mb-3">
                            <x-heroicon-s-shield-check class="w-5 h-5 text-green-500" />
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('ycookies.installation.compare_advanced_title') }}</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('ycookies.installation.compare_advanced_desc') }}</p>
                        <div class="space-y-1.5 text-xs">
                            <div class="flex items-center gap-1.5 text-green-600 dark:text-green-400">
                                <x-heroicon-s-check-circle class="w-3.5 h-3.5 shrink-0" />
                                <span>{{ __('ycookies.installation.compare_advanced_pro1') }}</span>
                            </div>
                            <div class="flex items-center gap-1.5 text-green-600 dark:text-green-400">
                                <x-heroicon-s-check-circle class="w-3.5 h-3.5 shrink-0" />
                                <span>{{ __('ycookies.installation.compare_advanced_pro2') }}</span>
                            </div>
                            <div class="flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
                                <x-heroicon-s-exclamation-triangle class="w-3.5 h-3.5 shrink-0" />
                                <span>{{ __('ycookies.installation.compare_advanced_con1') }}</span>
                            </div>
                        </div>
                    </button>

                    {{-- Proxy Mode --}}
                    <button type="button" x-on:click="activeTab = 'proxy'"
                        :class="activeTab === 'proxy'
                            ? 'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-500/10'
                            : 'ring-1 ring-gray-200 dark:ring-white/10 hover:ring-gray-300 dark:hover:ring-white/20'"
                        class="rounded-xl p-5 text-start transition-all">
                        <div class="flex items-center gap-2 mb-3">
                            <x-heroicon-s-globe-alt class="w-5 h-5 text-purple-500" />
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('ycookies.installation.compare_proxy_title') }}</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('ycookies.installation.compare_proxy_desc') }}</p>
                        <div class="space-y-1.5 text-xs">
                            <div class="flex items-center gap-1.5 text-green-600 dark:text-green-400">
                                <x-heroicon-s-check-circle class="w-3.5 h-3.5 shrink-0" />
                                <span>{{ __('ycookies.installation.compare_proxy_pro1') }}</span>
                            </div>
                            <div class="flex items-center gap-1.5 text-green-600 dark:text-green-400">
                                <x-heroicon-s-check-circle class="w-3.5 h-3.5 shrink-0" />
                                <span>{{ __('ycookies.installation.compare_proxy_pro2') }}</span>
                            </div>
                            <div class="flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
                                <x-heroicon-s-exclamation-triangle class="w-3.5 h-3.5 shrink-0" />
                                <span>{{ __('ycookies.installation.compare_proxy_con1') }}</span>
                            </div>
                        </div>
                    </button>
                </div>
            </x-filament::section>

            {{-- Tabs --}}
            <div class="mt-6">
            <x-filament::tabs>
                <x-filament::tabs.item alpine-active="activeTab === 'script'" x-on:click="activeTab = 'script'" icon="heroicon-o-code-bracket">
                    {{ __('ycookies.installation.method_script') }}
                </x-filament::tabs.item>
                <x-filament::tabs.item alpine-active="activeTab === 'proxy'" x-on:click="activeTab = 'proxy'" icon="heroicon-o-globe-alt" badge="{{ __('ycookies.installation.full_control') }}">
                    {{ __('ycookies.installation.method_proxy') }}
                </x-filament::tabs.item>
            </x-filament::tabs>

            {{-- ════════════ TAB 1: SCRIPT EMBED ════════════ --}}
            <div x-show="activeTab === 'script'" x-cloak>
                <x-filament::section>
                    <x-slot name="heading">{{ __('ycookies.installation.script_title') }}</x-slot>
                    <x-slot name="description">{{ __('ycookies.installation.script_description') }}</x-slot>

                    <div class="space-y-8">
                        {{-- Mode Switcher --}}
                        <x-filament::tabs>
                            <x-filament::tabs.item alpine-active="scriptMode === 'basic'" x-on:click="scriptMode = 'basic'">
                                {{ __('ycookies.installation.basic') }}
                            </x-filament::tabs.item>
                            <x-filament::tabs.item alpine-active="scriptMode === 'advanced'" x-on:click="scriptMode = 'advanced'" badge="{{ __('ycookies.installation.recommended') }}" badge-color="success">
                                {{ __('ycookies.installation.advanced') }}
                            </x-filament::tabs.item>
                        </x-filament::tabs>

                        {{-- Basic --}}
                        <div x-show="scriptMode === 'basic'" x-cloak>
                            <div class="rounded-xl bg-blue-50 dark:bg-blue-500/10 p-4 mb-4 ring-1 ring-inset ring-blue-500/20">
                                <div class="flex gap-3">
                                    <x-heroicon-s-information-circle class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" />
                                    <p class="text-sm text-blue-700 dark:text-blue-300"><strong>{{ __('ycookies.installation.basic_note_title') }}:</strong> {{ __('ycookies.installation.basic_note_text') }}</p>
                                </div>
                            </div>
                            <div class="relative group">
                                <pre class="rounded-xl bg-gray-950 p-4 overflow-x-auto ring-1 ring-white/10" id="basic-embed-code"><code class="text-sm font-mono text-gray-300 leading-relaxed">{{ $basicEmbedCode }}</code></pre>
                                <button type="button"
                                    onclick="navigator.clipboard.writeText(document.getElementById('basic-embed-code').innerText).then(() => { this.textContent='✓ {{ __("ycookies.installation.copied") }}'; setTimeout(()=>this.textContent='{{ __("ycookies.installation.copy") }}',2000); })"
                                    class="absolute top-3 right-3 rounded-lg bg-white/10 px-2.5 py-1 text-xs font-medium text-gray-400 hover:text-white hover:bg-white/20 transition opacity-0 group-hover:opacity-100">
                                    {{ __('ycookies.installation.copy') }}
                                </button>
                            </div>
                        </div>

                        {{-- Advanced --}}
                        <div x-show="scriptMode === 'advanced'" x-cloak>
                            <div class="rounded-xl bg-green-50 dark:bg-green-500/10 p-4 mb-4 ring-1 ring-inset ring-green-500/20">
                                <div class="flex gap-3">
                                    <x-heroicon-s-shield-check class="w-5 h-5 text-green-500 shrink-0 mt-0.5" />
                                    <p class="text-sm text-green-700 dark:text-green-300"><strong>{{ __('ycookies.installation.advanced_note_title') }}:</strong> {{ __('ycookies.installation.advanced_note_text') }}</p>
                                </div>
                            </div>
                            <div class="relative group">
                                <pre class="rounded-xl bg-gray-950 p-4 overflow-x-auto ring-1 ring-white/10 max-h-96" id="advanced-embed-code"><code class="text-sm font-mono text-gray-300 leading-relaxed">{{ $advancedEmbedCode }}</code></pre>
                                <button type="button"
                                    onclick="navigator.clipboard.writeText(document.getElementById('advanced-embed-code').innerText).then(() => { this.textContent='✓ {{ __("ycookies.installation.copied") }}'; setTimeout(()=>this.textContent='{{ __("ycookies.installation.copy") }}',2000); })"
                                    class="absolute top-3 right-3 rounded-lg bg-white/10 px-2.5 py-1 text-xs font-medium text-gray-400 hover:text-white hover:bg-white/20 transition opacity-0 group-hover:opacity-100">
                                    {{ __('ycookies.installation.copy') }}
                                </button>
                            </div>

                            {{-- CSP Limitation Note --}}
                            <div class="rounded-xl bg-amber-50 dark:bg-amber-500/10 p-4 mt-4 ring-1 ring-inset ring-amber-500/20">
                                <div class="flex gap-3">
                                    <x-heroicon-s-exclamation-triangle class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                                    <div class="text-sm text-amber-700 dark:text-amber-300">
                                        <strong>{{ __('ycookies.installation.csp_note_title') }}:</strong>
                                        {{ __('ycookies.installation.csp_note_text') }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Steps --}}
                        <div class="border-t border-gray-200 dark:border-white/10 pt-5 mt-2">
                            <h4 class="text-sm font-medium text-gray-950 dark:text-white mb-3">{{ __('ycookies.installation.instructions_title') }}</h4>
                            <ol class="space-y-2 list-decimal list-inside text-sm text-gray-500 dark:text-gray-400">
                                <li>{{ __('ycookies.installation.step_1') }}</li>
                                <li>{{ __('ycookies.installation.step_2') }}</li>
                                <li>{{ __('ycookies.installation.step_3') }}</li>
                            </ol>
                        </div>
                    </div>
                </x-filament::section>
            </div>

            {{-- ════════════ TAB 2: PROXY TUNNEL ════════════ --}}
            <div x-show="activeTab === 'proxy'" x-cloak>
                <x-filament::section>
                    <x-slot name="heading">{{ __('ycookies.installation.proxy_title') }}</x-slot>
                    <x-slot name="description">{{ __('ycookies.installation.proxy_description') }}</x-slot>

                    <div class="space-y-8">
                        {{-- How it works --}}
                        <div class="rounded-xl bg-gray-50 dark:bg-white/5 p-6 ring-1 ring-gray-200 dark:ring-white/10">
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-4">{{ __('ycookies.installation.how_it_works') }}</h4>
                            <div class="space-y-4">
                                @foreach([
                                    __('ycookies.installation.proxy_step_1'),
                                    __('ycookies.installation.proxy_step_2', ['target' => $cnameTarget]),
                                    __('ycookies.installation.proxy_step_3'),
                                ] as $i => $step)
                                <div class="flex items-start gap-3">
                                    <span class="flex items-center justify-center shrink-0 w-6 h-6 rounded-full bg-primary-50 dark:bg-primary-400/10 text-primary-600 dark:text-primary-400 text-xs font-bold ring-1 ring-inset ring-primary-500/20">{{ $i + 1 }}</span>
                                    <span class="text-sm text-gray-600 dark:text-gray-400 pt-0.5">{{ $step }}</span>
                                </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Origin Server IP --}}
                        <div>
                            <label for="origin-ip" class="text-sm font-medium text-gray-950 dark:text-white">{{ __('ycookies.installation.origin_ip') }}</label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('ycookies.installation.origin_ip_help') }}</p>
                            <input type="text" id="origin-ip" wire:model="originIp"
                                placeholder="212.227.101.145"
                                class="fi-input block w-full mt-1.5 rounded-lg border-gray-300 dark:border-white/10 bg-white/0 dark:bg-white/5 text-gray-950 dark:text-white shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500 placeholder:text-gray-400 dark:placeholder:text-gray-500 font-mono" />
                            @error('originIp')
                                <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Origin Host (optional) --}}
                        <div>
                            <label for="origin-host" class="text-sm font-medium text-gray-950 dark:text-white">{{ __('ycookies.installation.origin_host') }}</label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('ycookies.installation.origin_host_help', ['domain' => $activeDomain->name]) }}</p>
                            <input type="text" id="origin-host" wire:model="originHost"
                                placeholder="{{ $activeDomain->name }}"
                                class="fi-input block w-full mt-1.5 rounded-lg border-gray-300 dark:border-white/10 bg-white/0 dark:bg-white/5 text-gray-950 dark:text-white shadow-sm text-sm focus:border-primary-500 focus:ring-primary-500 placeholder:text-gray-400 dark:placeholder:text-gray-500 font-mono" />
                        </div>

                        {{-- Toggle --}}
                        <label class="inline-flex items-center gap-3 cursor-pointer">
                            <button type="button" wire:click="$toggle('proxyEnabled')" role="switch"
                                class="relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 cursor-pointer focus:outline-none focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 dark:focus:ring-offset-gray-900 {{ $proxyEnabled ? 'bg-primary-600' : 'bg-gray-200 dark:bg-white/10' }}"
                                aria-checked="{{ $proxyEnabled ? 'true' : 'false' }}">
                                <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow ring-0 transition duration-200 {{ $proxyEnabled ? 'translate-x-5' : 'translate-x-0' }}"></span>
                            </button>
                            <span class="text-sm font-medium text-gray-950 dark:text-white">{{ __('ycookies.installation.enable_proxy') }}</span>
                        </label>

                        {{-- CNAME --}}
                        <div class="rounded-xl bg-gray-950 p-4 ring-1 ring-white/10">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 mb-2">{{ __('ycookies.installation.dns_record') }}</p>
                            <code class="text-sm font-mono">
                                <span class="text-cyan-400">{{ $activeDomain->name }}</span>
                                <span class="text-gray-600 mx-1">→</span>
                                <span class="text-gray-400">CNAME</span>
                                <span class="text-gray-600 mx-1">→</span>
                                <span class="text-emerald-400">{{ $cnameTarget }}</span>
                            </code>
                            <p class="mt-2 text-xs text-gray-500">{{ __('ycookies.installation.dns_help') }}</p>
                        </div>

                        {{-- Status --}}
                        @if($proxyEnabled && $proxyStatus)
                        <div @class([
                            'flex items-center gap-3 rounded-xl p-3 ring-1 ring-inset',
                            'bg-green-50 dark:bg-green-500/10 ring-green-500/20' => $proxyStatus === 'active',
                            'bg-red-50 dark:bg-red-500/10 ring-red-500/20' => $proxyStatus === 'dns_error',
                            'bg-amber-50 dark:bg-amber-500/10 ring-amber-500/20' => $proxyStatus === 'ssl_pending',
                            'bg-gray-50 dark:bg-white/5 ring-gray-300/20 dark:ring-white/10' => !in_array($proxyStatus, ['active', 'dns_error', 'ssl_pending']),
                        ])>
                            @if($proxyStatus === 'active')
                                <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-green-500"></span></span>
                                <span class="text-sm font-medium text-green-700 dark:text-green-400">{{ __('ycookies.installation.status_active') }}</span>
                            @elseif($proxyStatus === 'dns_error')
                                <span class="h-2.5 w-2.5 rounded-full bg-red-500"></span>
                                <span class="text-sm font-medium text-red-700 dark:text-red-400">{{ __('ycookies.installation.status_dns_error') }}</span>
                            @elseif($proxyStatus === 'ssl_pending')
                                <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-amber-500"></span></span>
                                <span class="text-sm font-medium text-amber-700 dark:text-amber-400">{{ __('ycookies.installation.status_ssl_pending') }}</span>
                            @else
                                <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-gray-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-gray-400"></span></span>
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('ycookies.installation.status_pending') }}</span>
                            @endif
                        </div>
                        @endif

                        {{-- Buttons --}}
                        <div class="flex items-center gap-3 border-t border-gray-100 dark:border-white/5 pt-5">
                            <x-filament::button wire:click="saveProxySettings" icon="heroicon-o-check">
                                {{ __('ycookies.installation.save') }}
                            </x-filament::button>
                            @if($proxyEnabled)
                            <x-filament::button wire:click="verifyDns" color="gray" icon="heroicon-o-arrow-path">
                                {{ __('ycookies.installation.verify_dns') }}
                            </x-filament::button>
                            @endif
                        </div>
                    </div>
                </x-filament::section>
            </div>
            </div> {{-- end mt-6 wrapper --}}

        </div>

    @else
        <x-filament::section>
            <div class="flex items-center gap-3 text-amber-600 dark:text-amber-400">
                <x-heroicon-s-exclamation-triangle class="w-5 h-5 shrink-0" />
                <p class="text-sm font-medium">{{ __('ycookies.installation.no_domain') }}</p>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
